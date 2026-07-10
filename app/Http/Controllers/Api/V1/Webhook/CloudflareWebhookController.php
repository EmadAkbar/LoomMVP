<?php

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Services\Video\VideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CloudflareWebhookController extends Controller
{
    public function __construct(private readonly VideoService $videoService) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->all();

        $event = WebhookEvent::query()->create([
            'provider' => 'cloudflare_stream',
            'event_type' => data_get($payload, 'event') ?? data_get($payload, 'type'),
            'external_id' => data_get($payload, 'uid') ?? data_get($payload, 'result.uid'),
            'payload' => $payload,
            'processed' => false,
        ]);

        try {
            $this->videoService->syncFromCloudflarePayload($payload);
            $event->update(['processed' => true]);
        } catch (Throwable $exception) {
            $event->update(['error' => $exception->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Webhook received.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
