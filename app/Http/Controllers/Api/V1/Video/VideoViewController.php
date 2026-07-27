<?php

namespace App\Http\Controllers\Api\V1\Video;

use App\Enums\VideoPrivacy;
use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Services\Video\VideoViewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoViewController extends Controller
{
    public function __construct(private readonly VideoViewService $videoViewService) {}

    public function store(Request $request, Video $video): JsonResponse
    {
        $user = $request->user('sanctum');
        $isOwner = $user && $user->id == $video->user_id;

        if (! $isOwner && in_array($video->privacy, [VideoPrivacy::Private, VideoPrivacy::Disabled], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Video is not accessible.',
                'data' => null,
                'errors' => [
                    'video' => ['Video is not accessible.'],
                ],
            ], 404);
        }

        $validated = $request->validate([
            'watch_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'viewer_device_id' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->videoViewService->recordView(
            video: $video,
            request: $request,
            watchSeconds: (int) ($validated['watch_seconds'] ?? 0),
            viewerDeviceId: $validated['viewer_device_id'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'View recorded successfully.',
            'data' => [
                'view_id' => $result['view']->id,
                'is_unique_viewer' => $result['is_unique_viewer'],
            ],
            'errors' => null,
        ], 201);
    }

    public function insights(Request $request, Video $video): JsonResponse
    {
        abort_unless($video->user_id == $request->user()->id, 403);

        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $insights = $this->videoViewService->insights(
            video: $video,
            days: (int) ($validated['days'] ?? 30),
        );

        return response()->json([
            'success' => true,
            'message' => 'View insights fetched successfully.',
            'data' => $insights,
            'errors' => null,
        ]);
    }
}
