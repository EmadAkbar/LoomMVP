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

    public function createTusUploadUrl(
        int $fileSize,
        string $fileName,
        string $fileType,
        string $creatorId,
        int $maxDurationSeconds = 7200,
    ): array {
        $accountId = config('cloudflare.account_id');
        $apiToken = config('cloudflare.stream_api_token');

        if (! $accountId || ! $apiToken) {
            throw new RuntimeException(
                'Cloudflare Stream credentials are not configured.'
            );
        }

        $metadata = $this->buildTusMetadata([
            'name' => $fileName,
            'filetype' => $fileType,
            'maxdurationseconds' => (string) $maxDurationSeconds,
        ]);

        $response = Http::withToken($apiToken)
            ->withHeaders([
                'Tus-Resumable' => '1.0.0',
                'Upload-Length' => (string) $fileSize,
                'Upload-Metadata' => $metadata,
                'Upload-Creator' => $creatorId,
            ])
            ->post(
                "https://api.cloudflare.com/client/v4/accounts/{$accountId}/stream",
                [
                    'direct_user' => true,
                ]
            );

        /*
         * Depending on Laravel HTTP-client behavior, query parameters should
         * preferably be added through withOptions/query as shown below.
         */
        if (! $response->successful()) {
            throw new RuntimeException(
                'Cloudflare tus endpoint creation failed: '.
                $response->body()
            );
        }

        $uploadUrl = $response->header('Location');
        $streamMediaId = $response->header('stream-media-id');

        if (! $uploadUrl || ! $streamMediaId) {
            throw new RuntimeException(
                'Cloudflare did not return the tus upload URL or media ID.'
            );
        }

        return [
            'uploadURL' => $uploadUrl,
            'uid' => $streamMediaId,
            'headers' => $response->headers(),
        ];
    }

    private function buildTusMetadata(array $metadata): string
    {
        return collect($metadata)
            ->filter(
                fn ($value) =>
                    $value !== null &&
                    $value !== ''
            )
            ->map(
                fn ($value, $key) =>
                    $key.' '.base64_encode((string) $value)
            )
            ->implode(',');
    }
}
