<?php

namespace App\Http\Controllers\Api\V1\Video;

use App\Enums\VideoPrivacy;
use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Models\VideoShare;
use App\Services\Video\VideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoShareController extends Controller
{
    public function __construct(private readonly VideoService $videoService) {}

    public function store(Request $request, Video $video): JsonResponse
    {
        if ($video->user_id != auth()->user()->id) {
            abort(404);
        }

        $validated = $request->validate([
            'expires_at' => ['nullable', 'date'],
        ]);

        if (! $this->isVideoShareable($video)) {
            return response()->json([
                'success' => false,
                'message' => 'Only public videos can be shared.',
                'data' => null,
                'errors' => ['video' => ['Only public videos can be shared.']],
            ], 422);
        }

        $share = $this->videoService->createShare(
            video: $video,
            password: null,
            expiresAt: $validated['expires_at'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Share link created successfully.',
            'data' => [
                'share_uuid' => $share->share_uuid,
                'share_url' => rtrim(config('app.frontend_url', env('FRONTEND_URL')), '/') . '/share/' . $share->share_uuid,
            ],
            'errors' => null,
        ], 201);
    }

    public function show(VideoShare $share): JsonResponse
    {
        $share->load('video');
        $video = $share->video;

        if (! $this->isShareActive($share) || ! $this->isVideoShareable($video)) {
            abort(404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Share fetched successfully.',
            'data' => [
                'requires_password' => false,
                'video' => new VideoResource($video),
            ],
            'errors' => null,
        ]);
    }

    public function verifyPassword(Request $request, VideoShare $share): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $share->load('video');
        $video = $share->video;

        if (! $this->isShareActive($share) || ! $this->isVideoShareable($video)) {
            abort(404);
        }

        return response()->json([
            'success' => false,
            'message' => 'Password verification is not supported for public shares.',
            'data' => null,
            'errors' => ['password' => ['Password verification is not supported for public shares.']],
        ], 422);
    }

    private function isShareActive(VideoShare $share): bool
    {
        return $share->is_active && (! $share->expires_at || ! $share->expires_at->isPast());
    }

    private function isVideoShareable(Video $video): bool
    {
        return $video->privacy === VideoPrivacy::Public;
    }
}
