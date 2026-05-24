<?php

namespace Paymenter\Extensions\Servers\HetznerStorageBox;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Paymenter\Extensions\Servers\HetznerStorageBox\Mails\StorageBoxCredentialsMail;
use Paymenter\Extensions\Servers\HetznerStorageBox\Models\HetznerStorageBoxAccount;

#[ExtensionMeta(
    name: 'HetznerStorageBox',
    description: 'Provision and manage Hetzner Storage Boxes via the Hetzner Cloud API.',
    version: '0.1.0',
    author: 'Markus Morén',
    url: 'https://moren.it',
)]
class HetznerStorageBox extends Server
{
    // -------------------------------------------------------------------------
    // Extension lifecycle
    // -------------------------------------------------------------------------

    public function installed(): void
    {
        \App\Helpers\ExtensionHelper::runMigrations('extension/Servers/HetznerStorageBox/database/migrations');
    }

    public function uninstalled(): void
    {
        \App\Helpers\ExtensionHelper::rollbackMigrations('extension/Servers/HetznerStorageBox/database/migrations');
    }

    public function upgraded($oldVersion = null): void
    {
        \App\Helpers\ExtensionHelper::runMigrations('extension/Servers/HetznerStorageBox/database/migrations');
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    public function getConfig($values = []): array
    {
        return [
            [
                'name'        => 'api_token',
                'label'       => 'Hetzner Cloud API Token',
                'type'        => 'text',
                'required'    => true,
                'description' => 'Read & Write API token from your Hetzner Cloud project (Security → API Tokens).',
            ],
            [
                'name'        => 'default_location',
                'label'       => 'Default Location',
                'type'        => 'select',
                'required'    => true,
                'options'     => [
                    'fsn1' => 'Falkenstein (fsn1)',
                    'nbg1' => 'Nuremberg (nbg1)',
                    'hel1' => 'Helsinki (hel1)',
                ],
                'default'     => 'fsn1',
                'description' => 'Default datacenter location for new Storage Boxes.',
            ],
        ];
    }

    public function getProductConfig($values = []): array
    {
        return [
            [
                'name'        => 'storage_box_type',
                'label'       => 'Storage Box Plan',
                'type'        => 'select',
                'required'    => true,
                'options'     => [
                    'bx11' => 'BX11 — 1 TB',
                    'bx21' => 'BX21 — 5 TB',
                    'bx31' => 'BX31 — 10 TB',
                    'bx41' => 'BX41 — 20 TB',
                ],
                'description' => 'Hetzner Storage Box plan to provision.',
            ],
            [
                'name'        => 'location',
                'label'       => 'Location Override',
                'type'        => 'select',
                'required'    => false,
                'options'     => [
                    ''     => '— Use extension default —',
                    'fsn1' => 'Falkenstein (fsn1)',
                    'nbg1' => 'Nuremberg (nbg1)',
                    'hel1' => 'Helsinki (hel1)',
                ],
                'description' => 'Override the default location for this product.',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Lifecycle hooks
    // -------------------------------------------------------------------------

    public function createServer(Service $service, $settings, $properties): void
    {
        $settings = array_merge($this->getServerSettings($service), $settings, $properties);
        $apiToken = $settings['api_token'];
        $boxType  = $settings['storage_box_type'];
        $location = (array_key_exists('location', $settings) && !empty($settings['location']))
            ? $settings['location']
            : $settings['default_location'];

        $password = $this->generatePassword();
        $boxName  = $this->buildBoxName($service);
        $client   = $this->makeClient($apiToken);

        $accessSettings = [
            'ssh_enabled'          => true,
            'samba_enabled'        => true,
            'webdav_enabled'       => true,
            'zfs_enabled'          => false,
            'reachable_externally' => true,
        ];

        $response = $client->post('/v1/storage_boxes', [
            'name'             => $boxName,
            'password'         => $password,
            'location'         => $location,
            'storage_box_type' => $boxType,
            'access_settings'  => $accessSettings,
        ]);

        $boxId = $response['storage_box']['id'];

        // Log the Hetzner box ID immediately so it can be recovered manually
        // if any subsequent step (action wait, re-fetch, DB insert, mail) fails.
        Log::info("HetznerStorageBox: created box #{$boxId} for service #{$service->id}, awaiting action #{$response['action']['id']}");

        try {
            $client->waitForAction($response['action']['id']);

            // Re-fetch after action completes — username and server fields are
            // null in the create response and only populated once initialised.
            $box      = $client->get("/v1/storage_boxes/{$boxId}")['storage_box'];
            $username = $box['username'];
            $hostname = $box['server'];

            HetznerStorageBoxAccount::create([
                'service_id'     => $service->id,
                'hetzner_box_id' => $boxId,
                'username'       => $username,
                'hostname'       => $hostname,
                'box_type'       => $boxType,
                'location'       => $location,
                'status'         => 'active',
                'provisioned_at' => now(),
            ]);

            $this->sendCredentialsMail($service, $username, $hostname, $password);

        } catch (\Throwable $e) {
            Log::error(
                "HetznerStorageBox: provisioning failed for service #{$service->id} " .
                "(Hetzner box #{$boxId} may require manual cleanup): {$e->getMessage()}"
            );
            throw $e;
        }
    }

    public function suspendServer(Service $service, $settings, $properties): void
    {
        $settings = $this->getServerSettings($service);
        $account  = HetznerStorageBoxAccount::where('service_id', $service->id)->firstOrFail();
        $client   = $this->makeClient($settings['api_token']);

        $response = $client->post("/v1/storage_boxes/{$account->hetzner_box_id}/actions/update_access_settings", [
            'reachable_externally' => false,
        ]);

        $client->waitForAction($response['action']['id']);

        $account->update(['status' => 'suspended']);
    }

    public function unsuspendServer(Service $service, $settings, $properties): void
    {
        $settings = $this->getServerSettings($service);
        $account  = HetznerStorageBoxAccount::where('service_id', $service->id)->firstOrFail();
        $client   = $this->makeClient($settings['api_token']);

        $response = $client->post("/v1/storage_boxes/{$account->hetzner_box_id}/actions/update_access_settings", [
            'reachable_externally' => true,
            'ssh_enabled'          => true,
            'samba_enabled'        => true,
            'webdav_enabled'       => true,
        ]);

        $client->waitForAction($response['action']['id']);

        $account->update(['status' => 'active']);
    }

    public function terminateServer(Service $service, $settings, $properties): void
    {
        $settings = $this->getServerSettings($service);
        $account  = HetznerStorageBoxAccount::where('service_id', $service->id)->firstOrFail();
        $client   = $this->makeClient($settings['api_token']);

        $response = $client->delete("/v1/storage_boxes/{$account->hetzner_box_id}");

        if (!empty($response['action']['id'])) {
            $client->waitForAction($response['action']['id']);
        }

        $account->update(['status' => 'terminated']);
    }

    public function upgradeServer(Service $service, $settings, $properties): void
    {
        $settings   = array_merge($this->getServerSettings($service), $settings, $properties);
        $account    = HetznerStorageBoxAccount::where('service_id', $service->id)->firstOrFail();
        $client     = $this->makeClient($settings['api_token']);
        $newBoxType = $settings['storage_box_type'];

        $response = $client->post("/v1/storage_boxes/{$account->hetzner_box_id}/actions/change_type", [
            'storage_box_type' => $newBoxType,
        ]);

        $client->waitForAction($response['action']['id']);

        $account->update(['box_type' => $newBoxType]);
    }

    // -------------------------------------------------------------------------
    // Customer panel actions
    // -------------------------------------------------------------------------

    public function getActions(Service $service, $settings, $properties): array
    {
        $account = HetznerStorageBoxAccount::where('service_id', $service->id)->first();

        if (!$account) {
            return [];
        }

        return [
            [
                'type'  => 'text',
                'label' => 'Hostname',
                'text'  => $account->hostname,
            ],
            [
                'type'  => 'text',
                'label' => 'Username',
                'text'  => $account->username,
            ],
            [
                'type'  => 'text',
                'label' => 'Protocols',
                'text'  => 'SFTP (port 22/23) · FTPS · Samba/CIFS · WebDAV (HTTPS)',
            ],
            [
                'type'  => 'text',
                'label' => 'Plan',
                'text'  => strtoupper($account->box_type),
            ],
            [
                'name'     => 'regenerate_password',
                'label'    => 'Regenerate Password',
                'type'     => 'button',
                'function' => 'regeneratePassword',
            ],
        ];
    }

    public function regeneratePassword(Service $service): string
    {
        $settings    = $this->getServerSettings($service);
        $account     = HetznerStorageBoxAccount::where('service_id', $service->id)->firstOrFail();
        $client      = $this->makeClient($settings['api_token']);
        $newPassword = $this->generatePassword();

        $response = $client->post("/v1/storage_boxes/{$account->hetzner_box_id}/actions/reset_password", [
            'password' => $newPassword,
        ]);

        $client->waitForAction($response['action']['id']);

        // Password discarded after mail is sent — never stored.
        $this->sendCredentialsMail(
            $service,
            $account->username,
            $account->hostname,
            $newPassword,
            isReset: true,
        );

        // Store a success notification in the session so it displays after
        // the redirect — this is the native Paymenter notification pattern.
        \Illuminate\Support\Facades\Session::put('notification', [
            'message' => 'New credentials have been sent to your email address.',
            'type'    => 'success',
        ]);

        return route('services.show', $service->id);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build the Storage Box name from the customer email slug and service ID.
     * Example: markus-42
     */
    private function buildBoxName(Service $service): string
    {
        $email = $service->user->email ?? '';
        $local = explode('@', $email)[0];
        $slug  = strtolower(preg_replace('/[^a-z0-9]/i', '', $local));
        $slug  = substr($slug, 0, 20);

        // Fallback if the local part contained no alphanumeric characters.
        if ($slug === '') {
            $slug = 'box';
        }

        return "{$slug}-{$service->id}";
    }

    /**
     * Generate a cryptographically secure random password satisfying Hetzner's
     * password policy: 12–128 chars, must include upper, lower, digit, and
     * one of the allowed special characters.
     */
    private function generatePassword(): string
    {
        $lower   = 'abcdefghijklmnopqrstuvwxyz';
        $upper   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $digits  = '0123456789';
        $special = '!$%/()=?+#-.,:~*@_&';
        $all     = $lower . $upper . $digits . $special;

        // Guarantee at least one character from each required class.
        $chars   = [];
        $chars[] = $lower[random_int(0, strlen($lower) - 1)];
        $chars[] = $upper[random_int(0, strlen($upper) - 1)];
        $chars[] = $digits[random_int(0, strlen($digits) - 1)];
        $chars[] = $special[random_int(0, strlen($special) - 1)];

        for ($i = 4; $i < 32; $i++) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }

        // Cryptographically secure Fisher-Yates shuffle.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j          = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    private function sendCredentialsMail(
        Service $service,
        string $username,
        string $hostname,
        string $password,
        bool $isReset = false,
    ): void {
        Mail::to($service->user->email)->send(new StorageBoxCredentialsMail(
            user:     $service->user,
            username: $username,
            hostname: $hostname,
            password: $password,
            isReset:  $isReset,
        ));
    }

    /**
     * Load server-level settings into a plain key => value array.
     * The settings relation returns a Collection of objects with key/value
     * properties, not a plain array.
     */
    private function getServerSettings(Service $service): array
    {
        $serverSettings = $service->product->server?->settings;

        if (!$serverSettings) {
            throw new \RuntimeException(
                "No server linked to product #{$service->product_id}. " .
                "Assign the HetznerStorageBox server to the product in the admin panel."
            );
        }

        $result = [];
        foreach ($serverSettings as $setting) {
            $result[$setting->key] = $setting->value;
        }

        return $result;
    }

    private function makeClient(string $apiToken): HetznerApiClient
    {
        return new HetznerApiClient($apiToken);
    }
}
