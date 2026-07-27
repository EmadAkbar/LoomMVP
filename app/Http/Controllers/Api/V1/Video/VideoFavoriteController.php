<?php

namespace App\Http\Controllers\Api\V1\Video;

use App\Enums\VideoPrivacy;
use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoFavoriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $videos = $request->user()
            ->favoriteVideos()
            ->latest('video_favorites.created_at')
            ->withCount('views', 'favoritedBy')
            ->paginate((int) $request->integer('per_page', 12));

        return response()->json([
            'success' => true,
            'message' => 'Favorite videos fetched successfully.',
            'data' => VideoResource::collection($videos)->response()->getData(true),
            'errors' => null,
        ]);
    }

    public function store(Request $request, Video $video): JsonResponse
    {
        abort_if(
            $video->privacy === VideoPrivacy::Private && $video->user_id != $request->user()->id,
            404,
            'Video is not accessible.'
        );

        abort_if(
            $video->privacy === VideoPrivacy::Disabled && $video->user_id != $request->user()->id,
            404,
            'Video is not accessible.'
        );

        $request->user()->favoriteVideos()->syncWithoutDetaching([$video->id]);

        return response()->json([
            'success' => true,
            'message' => 'Video added to favorites.',
            'data' => [
                'video_uuid' => $video->uuid,
                'is_favorite' => true,
            ],
            'errors' => null,
        ], 201);
    }

    public function destroy(Request $request, Video $video): JsonResponse
    {
        $request->user()->favoriteVideos()->detach($video->id);

        return response()->json([
            'success' => true,
            'message' => 'Video removed from favorites.',
            'data' => [
                'video_uuid' => $video->uuid,
                'is_favorite' => false,
            ],
            'errors' => null,
        ]);
    }
}
