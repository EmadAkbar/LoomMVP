<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status?->value ?? $this->status,
            'privacy' => $this->privacy?->value ?? $this->privacy,
            'thumbnail_url' => $this->thumbnail_url,
            'duration_seconds' => $this->duration_seconds,
            'size_bytes' => $this->size_bytes,
            'playback_url' => $this->playback_url,
            'download_url' => $this->download_url,
            'slug' => $this->slug,
            'processing_percentage' => $this->processing_percentage,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
