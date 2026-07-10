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

        if (! $share->is_active || ($share->expires_at && $share->expires_at->isPast())) {
            abort(404);
        }

        if ($video->privacy === VideoPrivacy::Disabled || $video->privacy === VideoPrivacy::Private) {
            abort(404);
        }

        $requiresPassword = $video->privacy === VideoPrivacy::Password || $share->password_hash !== null;

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

        if (! $share->is_active || ($share->expires_at && $share->expires_at->isPast())) {
            abort(404);
        }

        if ($video->privacy === VideoPrivacy::Disabled || $video->privacy === VideoPrivacy::Private) {
            abort(404);
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
}
