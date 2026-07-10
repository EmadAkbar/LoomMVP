<?php

namespace Tests\Feature;

use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloudflareWebhookApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_rejects_missing_signature_when_secret_is_configured(): void
    {
        config(['cloudflare.stream_webhook_secret' => 'test-webhook-secret']);

        $this->postJson('/api/v1/webhooks/cloudflare', [
            'uid' => 'video-uid-123',
            'event' => 'video.ready',
        ])->assertUnauthorized();
    }

    public function test_webhook_accepts_valid_signature_and_persists_event(): void
    {
        $secret = 'test-webhook-secret';
        config(['cloudflare.stream_webhook_secret' => $secret]);

        $payload = [
            'uid' => 'video-uid-456',
            'event' => 'video.ready',
            'readyToStream' => true,
        ];

        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $encodedPayload, $secret);

        $this->call(
            method: 'POST',
            uri: '/api/v1/webhooks/cloudflare',
            parameters: [],
            cookies: [],
            files: [],
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_WEBHOOK_SIGNATURE' => $signature,
            ],
            content: $encodedPayload,
        )
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('webhook_events', [
            'provider' => 'cloudflare_stream',
            'event_type' => 'video.ready',
            'external_id' => 'video-uid-456',
            'processed' => true,
        ]);

        $this->assertSame(1, WebhookEvent::query()->count());
    }
}
