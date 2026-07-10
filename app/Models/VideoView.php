<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoView extends Model
{
    use HasFactory;

    protected $fillable = [
        'video_id',
        'viewer_ip',
        'viewer_agent',
        'watch_seconds',
    ];

    protected $casts = [
        'watch_seconds' => 'integer',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
