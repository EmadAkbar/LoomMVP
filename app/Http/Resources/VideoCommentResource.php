<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'guest_name' => $this->guest_name,
            'comment' => $this->comment,
            'timestamp_seconds' => $this->timestamp_seconds,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
