<?php

namespace App\Services\Cloudflare;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudflareStreamService
{
    public function __construct()
    {
        // Credentials are validated lazily so non-Cloudflare endpoints can resolve this service safely.
    }

    public function createDirectUploadUrl(array $meta = [], ?int $maxDurationSeconds = null): array
    {
        $this->assertConfigured();

        $payload = [
            'maxDurationSeconds' => $maxDurationSeconds ?? 3600,
            'meta' => $meta,
            'requireSignedURLs' => false,
        ];

        $response = $this->client()->post($this->baseUrl() . '/direct_upload', $payload);

        if ($response->failed()) {
            throw new RuntimeException('Unable to create Cloudflare direct upload URL: ' . $response->body());
        }

        return $response->json('result');
    }

    public function getVideo(string $uid): array
    {
        $this->assertConfigured();

        $response = $this->client()->get($this->baseUrl() . '/' . $uid);

        if ($response->failed()) {
            throw new RuntimeException('Unable to fetch Cloudflare video: ' . $response->body());
        }

        return $response->json('result');
    }

    public function deleteVideo(string $uid): void
    {
        $this->assertConfigured();

        $response = $this->client()->delete($this->baseUrl() . '/' . $uid);

        if ($response->failed()) {
            throw new RuntimeException('Unable to delete Cloudflare video: ' . $response->body());
        }
    }

    public function createDownload(string $uid): array
    {
        $this->assertConfigured();

        $response = $this->client()->post($this->baseUrl() . '/' . $uid . '/downloads');

        if ($response->failed()) {
            throw new RuntimeException('Unable to create Cloudflare download: ' . $response->body());
        }

        return $response->json('result') ?? [];
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) config('cloudflare.stream_api_token'))
            ->acceptJson()
            ->asJson();
    }

    private function baseUrl(): string
    {
        $accountId = (string) config('cloudflare.account_id');

        return "https://api.cloudflare.com/client/v4/accounts/{$accountId}/stream";
    }

    private function assertConfigured(): void
    {
        $accountId = (string) config('cloudflare.account_id');
        $apiToken = (string) config('cloudflare.stream_api_token');

        if ($accountId === '' || $apiToken === '') {
            throw new RuntimeException('Cloudflare Stream credentials are not configured.');
        }
    }
}
