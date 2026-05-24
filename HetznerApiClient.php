<?php

namespace Paymenter\Extensions\Servers\HetznerStorageBox;

use Illuminate\Support\Facades\Http;

/**
 * HTTP client wrapper for the Hetzner API.
 *
 * Two base URLs are involved:
 *   - api.hetzner.com/v1    — Storage Boxes and their actions
 *   - api.hetzner.cloud/v1  — Cloud resources (SSH keys, etc.)
 *
 * The correct base URL is resolved automatically from the path.
 *
 * All Storage Box mutating operations are asynchronous and return an action
 * object. Use waitForAction() to block until the action completes or fails.
 */
class HetznerApiClient
{
    private const CLOUD_BASE   = 'https://api.hetzner.cloud';
    private const STORAGE_BASE = 'https://api.hetzner.com';

    /** Maximum seconds to wait for an async action to complete. */
    private const ACTION_TIMEOUT_SECONDS = 120;
    private const ACTION_POLL_INTERVAL   = 2;

    public function __construct(private readonly string $apiToken) {}

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $path, $body);
    }

    /**
     * DELETE returns an action body for Storage Boxes (not 204 No Content).
     */
    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    /**
     * Poll the Storage Box actions endpoint until the action reaches a
     * terminal state (success or error).
     *
     * Sleeps before polling to avoid hammering the API on fast operations.
     *
     * @throws \RuntimeException if the action fails or the timeout is exceeded.
     */
    public function waitForAction(int $actionId): void
    {
        $elapsed = 0;

        while ($elapsed < self::ACTION_TIMEOUT_SECONDS) {
            sleep(self::ACTION_POLL_INTERVAL);
            $elapsed += self::ACTION_POLL_INTERVAL;

            $response = $this->get("/v1/storage_boxes/actions/{$actionId}");
            $status   = $response['action']['status'] ?? 'unknown';

            if ($status === 'success') {
                return;
            }

            if ($status === 'error') {
                $message = $response['action']['error']['message'] ?? 'Unknown error';
                throw new \RuntimeException("Hetzner action {$actionId} failed: {$message}");
            }
        }

        throw new \RuntimeException(
            "Hetzner action {$actionId} did not complete within " . self::ACTION_TIMEOUT_SECONDS . " seconds."
        );
    }

    private function request(string $method, string $path, array $body = []): array
    {
        $url     = $this->baseUrl($path) . $path;
        $pending = Http::withToken($this->apiToken)
            ->acceptJson()
            ->contentType('application/json');

        $response = match ($method) {
            'GET'    => $pending->get($url),
            'POST'   => $pending->post($url, $body),
            'DELETE' => $pending->delete($url),
            default  => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };

        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->body();
            throw new \RuntimeException(
                "Hetzner API error [{$response->status()}] {$method} {$path}: {$error}"
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Storage Box paths (including their actions) go to api.hetzner.com.
     * Everything else goes to api.hetzner.cloud.
     */
    private function baseUrl(string $path): string
    {
        if (str_contains($path, 'storage_box')) {
            return self::STORAGE_BASE;
        }

        return self::CLOUD_BASE;
    }
}
