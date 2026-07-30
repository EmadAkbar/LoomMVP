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

    /**
     * Like getVideo(), but tells "Cloudflare has no such video" apart from "the
     * call failed". A 404 is a real answer — the upload was abandoned or expired
     * and no amount of retrying will change it — whereas a network error means try
     * again later. getVideo() collapses both into one exception, and callers that
     * act on the difference need them separated.
     */
    public function findVideo(string $uid): ?array
    {
        $this->assertConfigured();

        $response = $this->client()->get($this->baseUrl() . '/' . $uid);

        if ($response->status() === 404) {
            return null;
        }

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
            // Laravel's default is no timeout at all. These calls now also run from
            // post-response reconciliation, where a hung connection would hold the
            // worker open indefinitely instead of just delaying one request.
            ->connectTimeout(5)
            ->timeout(15)
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

        /*
         * `direct_user=true` MUST travel as a query parameter, not in the body.
         * It is what decides which host Cloudflare puts in the Location header:
         *
         *   with it     https://upload.cloudflarestream.com/tus/<id>?tusv2=true
         *   without it  https://edge-production.gateway.api.cloudflare.com/
         *               client/v4/accounts/<account>/media/<id>?tusv2=true
         *
         * Both create the video and both answer 201, so the mistake is invisible
         * server-side. Only the first URL is usable from a browser: it allows
         * unauthenticated PATCH and sends the tus CORS headers. The second sits on
         * the authenticated API surface, where an unauthenticated HEAD answers 400
         * with no Access-Control-Allow-Origin at all — so the browser reports a
         * bare network failure and every resumable upload from the SPA dies.
         *
         * Sent as a body field it is simply ignored, which is what happened here.
         */
        $response = Http::withToken($apiToken)
            ->withHeaders([
                'Tus-Resumable' => '1.0.0',
                'Upload-Length' => (string) $fileSize,
                'Upload-Metadata' => $metadata,
                'Upload-Creator' => $creatorId,
            ])
            ->withQueryParameters(['direct_user' => 'true'])
            ->post(
                "https://api.cloudflare.com/client/v4/accounts/{$accountId}/stream"
            );

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
