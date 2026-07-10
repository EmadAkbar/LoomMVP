<?php

namespace App\Http\Controllers\Api\V1\Video;

use App\Http\Controllers\Controller;
use App\Http\Requests\Video\CreateCommentRequest;
use App\Http\Resources\VideoCommentResource;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoCommentController extends Controller
{
    public function index(Request $request, Video $video): JsonResponse
    {
        abort_unless($video->user_id == $request->user()->id, 403);

        $comments = $video->comments()->oldest('timestamp_seconds')->get();

        return response()->json([
            'success' => true,
            'message' => 'Comments fetched successfully.',
            'data' => ['comments' => VideoCommentResource::collection($comments)],
            'errors' => null,
        ]);
    }

    public function store(CreateCommentRequest $request, Video $video): JsonResponse
    {
        $comment = $video->comments()->create([
            'user_id' => $request->user()?->id,
            'guest_name' => $request->input('guest_name'),
            'comment' => $request->input('comment'),
            'timestamp_seconds' => $request->integer('timestamp_seconds'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Comment created successfully.',
            'data' => ['comment' => new VideoCommentResource($comment)],
            'errors' => null,
        ], 201);
    }
}
