<?php

namespace App\Models;

use App\Enums\VideoPrivacy;
use App\Enums\VideoStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Video extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'user_id',
        'cloudflare_uid',
        'upload_uid',
        'title',
        'description',
        'status',
        'privacy',
        'password_hash',
        'thumbnail_url',
        'duration_seconds',
        'size_bytes',
        'playback_url',
        'download_url',
        'slug',
        'processing_percentage',
        'cloudflare_meta',
    ];

    protected $casts = [
        'status' => VideoStatus::class,
        'privacy' => VideoPrivacy::class,
        'duration_seconds' => 'integer',
        'size_bytes' => 'integer',
        'processing_percentage' => 'integer',
        'cloudflare_meta' => 'array',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(VideoComment::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(VideoShare::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(VideoView::class);
    }
}
