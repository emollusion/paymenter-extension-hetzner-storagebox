<?php

namespace sa6bom\HetznerStorageBox;

use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around Laravel's HTTP client for the Hetzner API.
 *
 * Two base URLs are involved:
 *   - api.hetzner.cloud/v1  — Cloud resources (SSH keys registered in project)
 *   - api.hetzner.com/v1    — Storage Boxes and their actions
 *
 * Both accept the same Bearer token. The base URL is resolved automatically
 * from the path.
 *
 * All Storage Box actions are asynchronous. Use waitForAction() after any
 * action call to block until the action completes or fails.
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

    public function put(string $path, array $body = []): array
    {
        return $this->request('PUT', $path, $body);
    }

    /**
     * DELETE returns an action body for Storage Boxes (not 204).
     */
    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    /**
     * Poll the Storage Box actions endpoint until the action reaches a
     * terminal state (success or error).
     *
     * @throws \RuntimeException if the action fails or times out.
     */
    public function waitForAction(int $actionId): void
    {
        $elapsed = 0;

        while ($elapsed < self::ACTION_TIMEOUT_SECONDS) {
            $response = $this->get("/v1/storage_boxes/actions/{$actionId}");
            $status   = $response['action']['status'] ?? 'unknown';

            if ($status === 'success') {
                return;
            }

            if ($status === 'error') {
                $message = $response['action']['error']['message'] ?? 'Unknown error';
                throw new \RuntimeException("Hetzner action {$actionId} failed: {$message}");
            }

            sleep(self::ACTION_POLL_INTERVAL);
            $elapsed += self::ACTION_POLL_INTERVAL;
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
            'PUT'    => $pending->put($url, $body),
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
     * Storage Box paths (including actions) go to api.hetzner.com.
     * Everything else (SSH keys) goes to api.hetzner.cloud.
     */
    private function baseUrl(string $path): string
    {
        if (str_contains($path, 'storage_box')) {
            return self::STORAGE_BASE;
        }
        return self::CLOUD_BASE;
    }
}
