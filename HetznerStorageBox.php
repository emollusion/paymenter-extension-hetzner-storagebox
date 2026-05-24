<?php

namespace sa6bom\HetznerStorageBox;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use sa6bom\HetznerStorageBox\Mails\StorageBoxCredentialsMail;
use sa6bom\HetznerStorageBox\Models\HetznerStorageBoxAccount;

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

    public function upgraded(): void
    {
        \App\Helpers\ExtensionHelper::runMigrations('extension/Servers/HetznerStorageBox/database/migrations');
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    /**
     * Extension-level settings shown in the Paymenter admin when configuring
     * the server entry.
     */
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

    /**
     * Product-level settings shown in the Paymenter admin when configuring a
     * product that uses this extension.
     */
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
                    'bx21' => 'BX21 — 2 TB',
                    'bx31' => 'BX31 — 5 TB',
                    'bx41' => 'BX41 — 10 TB',
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

    /**
     * Checkout-level fields shown to the customer when placing an order.
     */
    public function getCheckoutConfig(Product $product, $values = [], $settings = []): array
    {
        return [
            [
                'name'        => 'ssh_public_key',
                'label'       => 'SSH Public Key (optional)',
                'type'        => 'textarea',
                'required'    => false,
                'description' => 'Paste your SSH public key (RSA, ECDSA, or Ed25519) to enable key-based authentication. Leave blank to use password authentication only.',
                'validation'  => 'nullable',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Lifecycle hooks
    // -------------------------------------------------------------------------

    /**
     * Provision a new Storage Box when a service is activated.
     */
    public function createServer(Service $service, $settings, $properties): void
    {
        $settings = array_merge($settings, $properties);
        $apiToken = $settings['api_token'];
        $boxType  = $settings['storage_box_type'];
        $location = (array_key_exists('location', $settings) && $settings['location'] !== '')
            ? $settings['location']
            : $settings['default_location'];
        $sshKey   = array_key_exists('ssh_public_key', $properties)
            ? trim($properties['ssh_public_key'])
            : '';

        // Validate SSH key format if provided.
        if ($sshKey !== '' && !$this->isValidSshPublicKey($sshKey)) {
            throw new \RuntimeException('The provided SSH public key is not valid.');
        }

        $password = $this->generatePassword();
        $boxName  = $this->buildBoxName($service);
        $client   = $this->makeClient($apiToken);

        // Access settings: only secure protocols.
        // Note: ftps is not a separate flag — FTPS is part of FTP subsystem on
        // Hetzner Storage Boxes and controlled at the network/TLS level, not here.
        // The API access_settings fields are: ssh_enabled, samba_enabled,
        // webdav_enabled, zfs_enabled, reachable_externally.
        $accessSettings = [
            'ssh_enabled'          => true,
            'samba_enabled'        => true,
            'webdav_enabled'       => true,
            'zfs_enabled'          => false,
            'reachable_externally' => true,
        ];

        $payload = [
            'name'              => $boxName,
            'password'          => $password,
            'location'          => $location,
            'storage_box_type'  => $boxType,
            'access_settings'   => $accessSettings,
        ];

        // ssh_keys on the create endpoint takes raw OpenSSH public key strings
        // directly — no prior registration step needed.
        if ($sshKey !== '') {
            $payload['ssh_keys'] = [$sshKey];
        }

        $response = $client->post('/v1/storage_boxes', $payload);

        $box      = $response['storage_box'];
        $boxId    = $box['id'];
        $username = $box['username'];
        $hostname = $box['server']; // e.g. u45321.your-storagebox.de

        // The create action is async. Wait for it to complete before
        // considering provisioning done.
        $actionId = $response['action']['id'];
        $client->waitForAction($actionId);

        // Persist account metadata. Password is never stored.
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

        // Email credentials — the only time the plaintext password is used.
        $this->sendCredentialsMail($service, $username, $hostname, $password, $sshKey !== '');
    }

    /**
     * Suspend a service: disable external reachability.
     * Supports partial updates — only reachable_externally needs to be sent.
     */
    public function suspendServer(Service $service, $settings, $properties): void
    {
        $account = HetznerStorageBoxAccount::where('service_id', $service->id)->firstOrFail();
        $client  = $this->makeClient($settings['api_token']);

        $response = $client->post("/v1/storage_boxes/{$account->hetzner_box_id}/actions/update_access_settings", [
            'reachable_externally' => false,
        ]);

        $client->waitForAction($response['action']['id']);

        $account->update(['status' => 'suspended']);
    }

    /**
     * Unsuspend a service: restore external reachability and all enabled protocols.
     */
    public function unsuspendServer(Service $service, $settings, $properties): void
    {
        $account = HetznerStorageBoxAccount::where('service_id', $service->id)->firstOrFail();
        $client  = $this->makeClient($settings['api_token']);

        $response = $client->post("/v1/storage_boxes/{$account->hetzner_box_id}/actions/update_access_settings", [
            'reachable_externally' => true,
            'ssh_enabled'          => true,
            'samba_enabled'        => true,
            'webdav_enabled'       => true,
        ]);

        $client->waitForAction($response['action']['id']);

        $account->update(['status' => 'active']);
    }

    /**
     * Terminate a service: delete the Storage Box entirely.
     * Delete is also async and returns an action.
     */
    public function terminateServer(Service $service, $settings, $properties): void
    {
        $account = HetznerStorageBoxAccount::where('service_id', $service->id)->firstOrFail();
        $client  = $this->makeClient($settings['api_token']);

        $response = $client->delete("/v1/storage_boxes/{$account->hetzner_box_id}");

        // Delete returns an action body (not 204).
        if (!empty($response['action']['id'])) {
            $client->waitForAction($response['action']['id']);
        }

        $account->update(['status' => 'terminated']);
    }

    /**
     * Upgrade or downgrade a Storage Box to a different plan.
     */
    public function upgradeServer(Service $service, $settings, $properties): void
    {
        $settings   = array_merge($settings, $properties);
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

    /**
     * Actions shown on the customer's service page.
     */
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

    /**
     * Reset the Storage Box password and email the new one to the customer.
     * Called when the customer clicks "Regenerate Password".
     *
     * The new password is supplied by us to the API — Hetzner does not
     * generate it on our behalf.
     */
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

        // Password discarded after mail is queued — never stored.
        $this->sendCredentialsMail(
            $service,
            $account->username,
            $account->hostname,
            $newPassword,
            sshKeyProvided: false,
            isReset: true,
        );

        return 'New credentials have been sent to your email address.';
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
        $email = $service->user->email ?? 'customer';
        $slug  = strtolower(preg_replace('/[^a-z0-9]/i', '', explode('@', $email)[0]));
        $slug  = substr($slug, 0, 20);
        return "{$slug}-{$service->id}";
    }

    /**
     * Validate a customer-provided SSH public key.
     * Accepts RSA, ECDSA (nistp256/384/521), and Ed25519.
     */
    private function isValidSshPublicKey(string $key): bool
    {
        $parts = explode(' ', trim($key));
        if (count($parts) < 2) {
            return false;
        }

        $allowedTypes = [
            'ssh-rsa',
            'ecdsa-sha2-nistp256',
            'ecdsa-sha2-nistp384',
            'ecdsa-sha2-nistp521',
            'ssh-ed25519',
        ];

        if (!in_array($parts[0], $allowedTypes, true)) {
            return false;
        }

        // Key material must be valid base64.
        $decoded = base64_decode($parts[1], strict: true);
        return $decoded !== false && strlen($decoded) > 0;
    }

    /**
     * Generate a strong random password that satisfies Hetzner's policy:
     * 12–128 chars, must contain upper, lower, digit, and special character.
     * Only uses characters from Hetzner's allowed set.
     */
    private function generatePassword(): string
    {
        // Hetzner allowed special chars: ^ ° ! § $ % / ( ) = ? + # - . , ; : ~ * @ { } _ &
        $lower   = 'abcdefghijklmnopqrstuvwxyz';
        $upper   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $digits  = '0123456789';
        $special = '!$%/()=?+#-.,:~*@_&';
        $all     = $lower . $upper . $digits . $special;

        // Guarantee at least one of each required character class.
        $password  = $lower[random_int(0, strlen($lower) - 1)];
        $password .= $upper[random_int(0, strlen($upper) - 1)];
        $password .= $digits[random_int(0, strlen($digits) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];

        // Fill to 32 chars total.
        for ($i = 4; $i < 32; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }

        // Shuffle to avoid predictable prefix pattern.
        return str_shuffle($password);
    }

    /**
     * Send credentials email to the service owner.
     */
    private function sendCredentialsMail(
        Service $service,
        string $username,
        string $hostname,
        string $password,
        bool $sshKeyProvided,
        bool $isReset = false,
    ): void {
        Mail::to($service->user->email)->send(new StorageBoxCredentialsMail(
            user:           $service->user,
            username:       $username,
            hostname:       $hostname,
            password:       $password,
            sshKeyProvided: $sshKeyProvided,
            isReset:        $isReset,
        ));
    }

    /**
     * Load server-level settings from the service's product server configuration.
     * Mirrors the pattern established in the Virtualmin extension.
     */
    private function getServerSettings(Service $service): array
    {
        return $service->product->server->settings ?? [];
    }

    /**
     * Instantiate the Hetzner API client.
     */
    private function makeClient(string $apiToken): HetznerApiClient
    {
        return new HetznerApiClient($apiToken);
    }
}
