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
use Illuminate\Support\Facades\Hash;

class VideoShareController extends Controller
{
    public function __construct(private readonly VideoService $videoService) {}

    public function store(Request $request, Video $video): JsonResponse
    {
        abort_unless($video->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'password' => ['nullable', 'string', 'min:4', 'max:100'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $share = $this->videoService->createShare(
            video: $video,
            password: $validated['password'] ?? null,
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

        $requiresPassword = $this->requiresPassword($video, $share);

        return response()->json([
            'success' => true,
            'message' => 'Share fetched successfully.',
            'data' => [
                'requires_password' => $requiresPassword,
                'video' => $requiresPassword ? [
                    'uuid' => $video->uuid,
                    'title' => $video->title,
                    'thumbnail_url' => $video->thumbnail_url,
                ] : new VideoResource($video),
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

        if (! $this->requiresPassword($video, $share)) {
            return response()->json([
                'success' => true,
                'message' => 'Password not required for this video.',
                'data' => ['video' => new VideoResource($video)],
                'errors' => null,
            ]);
        }

        $validVideoPassword = $video->password_hash && Hash::check($validated['password'], $video->password_hash);
        $validSharePassword = $share->password_hash && Hash::check($validated['password'], $share->password_hash);

        abort_unless($validVideoPassword || $validSharePassword, 403);

        return response()->json([
            'success' => true,
            'message' => 'Password verified successfully.',
            'data' => ['video' => new VideoResource($video)],
            'errors' => null,
        ]);
    }

    private function isShareActive(VideoShare $share): bool
    {
        return $share->is_active && (! $share->expires_at || ! $share->expires_at->isPast());
    }

    private function isVideoShareable(Video $video): bool
    {
        return $video->privacy !== VideoPrivacy::Disabled;
    }

    private function requiresPassword(Video $video, VideoShare $share): bool
    {
        if ($video->privacy === VideoPrivacy::Public) {
            return false;
        }

        return $video->privacy === VideoPrivacy::Password
            || $video->password_hash !== null
            || $share->password_hash !== null;
    }
}
