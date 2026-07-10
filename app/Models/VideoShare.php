<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoShare extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'video_id',
        'share_uuid',
        'expires_at',
        'is_active',
        'password_hash',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function uniqueIds(): array
    {
        return ['share_uuid'];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
