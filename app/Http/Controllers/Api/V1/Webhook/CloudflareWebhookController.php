<?php

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Services\Video\VideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class CloudflareWebhookController extends Controller
{
    public function __construct(private readonly VideoService $videoService) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->all();
        $context = [
            'provider' => 'cloudflare_stream',
            'event_type' => data_get($payload, 'event') ?? data_get($payload, 'type'),
            'external_id' => data_get($payload, 'uid') ?? data_get($payload, 'result.uid'),
            'ready_to_stream' => data_get($payload, 'readyToStream') ?? data_get($payload, 'result.readyToStream'),
            'status_state' => data_get($payload, 'status.state') ?? data_get($payload, 'result.status.state'),
            'signature_verified' => (bool) $request->attributes->get('webhook_signature_verified', false),
            'signature_timestamp' => $request->attributes->get('webhook_signature_timestamp'),
            'payload_size_bytes' => strlen($request->getContent()),
        ];

        Log::channel('webhooks')->info('Cloudflare webhook received.', $context + [
            'payload' => $payload,
        ]);

        $event = WebhookEvent::query()->create([
            'provider' => 'cloudflare_stream',
            'event_type' => data_get($payload, 'event') ?? data_get($payload, 'type'),
            'external_id' => data_get($payload, 'uid') ?? data_get($payload, 'result.uid'),
            'payload' => $payload,
            'processed' => false,
        ]);

        try {
            $video = $this->videoService->syncFromCloudflarePayload($payload);
            $event->update(['processed' => true]);

            Log::channel('webhooks')->info('Cloudflare webhook processed successfully.', $context + [
                'webhook_event_id' => $event->id,
                'video_uuid' => $video?->uuid,
            ]);
        } catch (Throwable $exception) {
            $event->update(['error' => $exception->getMessage()]);

            Log::channel('webhooks')->error('Cloudflare webhook processing failed.', $context + [
                'webhook_event_id' => $event->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Webhook received.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
