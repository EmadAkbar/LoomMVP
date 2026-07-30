<?php

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Video;
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

        // The signature was already verified by VerifyCloudflareWebhookSignature,
        // so the only thing on the critical path is the status write itself: one
        // lookup, one update, no outbound Cloudflare calls. Everything else — the
        // audit row, the log lines, the download-URL backfill that costs up to two
        // API round trips — is deferred to terminating callbacks, which run after
        // this response has been flushed. Cloudflare's delivery timer therefore
        // measures a single query, not our bookkeeping.
        $video = null;
        $error = null;

        try {
            $video = $this->videoService->syncFromCloudflarePayload($payload);
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

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

        app()->terminating(function () use ($payload, $context, $video, $error) {
            $this->recordAfterResponse($payload, $context, $video, $error);
        });

        // Always 200, even on a processing error: a payload we cannot apply will
        // not apply on redelivery either, and Cloudflare retrying it forever adds
        // load without ever succeeding. The failure is captured in the audit row.
        return response()->json([
            'success' => true,
            'message' => 'Webhook received.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * Audit logging plus the download-URL backfill, run once the response is out.
     *
     * Keeping the WebhookEvent row append-only preserves the existing delivery
     * history; the video row it describes is idempotent on its own, so a repeated
     * delivery adds a log entry without changing the resulting status.
     */
    private function recordAfterResponse(array $payload, array $context, ?Video $video, ?string $error): void
    {
        try {
            $event = WebhookEvent::query()->create([
                'provider' => 'cloudflare_stream',
                'event_type' => $context['event_type'],
                'external_id' => $context['external_id'],
                'payload' => $payload,
                'processed' => $error === null,
                'error' => $error,
            ]);

            if ($error !== null) {
                Log::channel('webhooks')->error('Cloudflare webhook processing failed.', $context + [
                    'webhook_event_id' => $event->id,
                    'error' => $error,
                ]);

                return;
            }

            Log::channel('webhooks')->info('Cloudflare webhook processed successfully.', $context + [
                'webhook_event_id' => $event->id,
                'video_uuid' => $video?->uuid,
                'status' => $video?->status?->value,
                'processing_percentage' => $video?->processing_percentage,
            ]);

            if ($video) {
                $this->videoService->backfillCloudflareDownload($video);
            }
        } catch (Throwable $exception) {
            Log::channel('webhooks')->error('Cloudflare webhook post-response work failed.', $context + [
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
