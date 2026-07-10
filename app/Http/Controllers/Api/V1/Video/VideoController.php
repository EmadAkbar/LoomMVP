<?php

namespace App\Http\Controllers\Api\V1\Video;

use App\Enums\VideoPrivacy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Video\CreateUploadUrlRequest;
use App\Http\Requests\Video\UpdateVideoRequest;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Services\Video\VideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoController extends Controller
{
    public function __construct(private readonly VideoService $videoService) {}

    public function index(Request $request): JsonResponse
    {
        $videos = $this->videoService->paginateForUser(
            userId: $request->user()->id,
            perPage: (int) $request->integer('per_page', 12)
        );

        return response()->json([
            'success' => true,
            'message' => 'Videos fetched successfully.',
            'data' => VideoResource::collection($videos)->response()->getData(true),
            'errors' => null,
        ]);
    }

    public function createUploadUrl(CreateUploadUrlRequest $request): JsonResponse
    {
        $result = $this->videoService->createUploadUrl(
            userId: $request->user()->id,
            title: $request->input('title', 'Untitled Video'),
            description: $request->input('description', null),
        );

        return response()->json([
            'success' => true,
            'message' => 'Upload URL created successfully.',
            'data' => [
                'video' => new VideoResource($result['video']),
                'upload_url' => $result['upload_url'],
                'upload_uid' => $result['upload_uid'],
            ],
            'errors' => null,
        ], 201);
    }

    public function show(Request $request, Video $video): JsonResponse
    {
        // abort_unless($video->user_id === $request->user()->id, 403);
        if($video->privacy === VideoPrivacy::Disabled || $video->privacy === VideoPrivacy::Private) {
            return response()->json([
                'success' => false,
                'message' => 'Video is not accessible.',
                'data' => null,
                'errors' => ['video' => ['Video is not accessible.']],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Video fetched successfully.',
            'data' => ['video' => new VideoResource($video)],
            'errors' => null,
        ]);
    }

    public function update(UpdateVideoRequest $request, Video $video): JsonResponse
    {
        abort_unless($video->user_id == $request->user()->id, 403);

        $video = $this->videoService->update($video, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Video updated successfully.',
            'data' => ['video' => new VideoResource($video)],
            'errors' => null,
        ]);
    }

    public function destroy(Request $request, Video $video): JsonResponse
    {
        abort_unless($video->user_id == $request->user()->id, 403);

        $this->videoService->delete($video);

        return response()->json([
            'success' => true,
            'message' => 'Video deleted successfully.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
