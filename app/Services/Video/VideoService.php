<?php

namespace App\Services\Video;

use App\Enums\VideoPrivacy;
use App\Enums\VideoStatus;
use App\Models\Video;
use App\Models\VideoShare;
use App\Services\Cloudflare\CloudflareStreamService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class VideoService
{
    public function __construct(
        private readonly CloudflareStreamService $cloudflareStreamService
    ) {}

    public function paginateForUser(int $userId, int $perPage = 12): LengthAwarePaginator
    {
        return Video::query()
            ->where('user_id', $userId)
            ->latest()
            ->paginate($perPage);
    }

    public function createUploadUrl(int $userId, string $title = 'Untitled Video'): array
    {
        $video = Video::query()->create([
            'user_id' => $userId,
            'title' => $title,
            'slug' => $this->uniqueSlug($title),
            'status' => VideoStatus::Uploading,
            'privacy' => VideoPrivacy::Private,
        ]);

        try {
            $directUpload = $this->cloudflareStreamService->createDirectUploadUrl([
                'video_id' => (string) $video->uuid,
                'user_id' => (string) $userId,
                'title' => $title,
            ]);

            $uploadUid = $directUpload['uid'] ?? null;
            $uploadUrl = $directUpload['uploadURL'] ?? null;

            if (! is_string($uploadUid) || ! is_string($uploadUrl) || $uploadUid === '' || $uploadUrl === '') {
                throw new RuntimeException('Cloudflare direct upload payload is missing required values.');
            }
        } catch (Throwable $exception) {
            $video->forceDelete();
            report($exception);

            throw new RuntimeException('Unable to create upload URL. Please retry.');
        }

        $video->update([
            'upload_uid' => $uploadUid,
            'cloudflare_uid' => $uploadUid,
            'cloudflare_meta' => $directUpload,
        ]);

        return [
            'video' => $video->refresh(),
            'upload_url' => $uploadUrl,
            'upload_uid' => $uploadUid,
        ];
    }

    public function update(Video $video, array $data): Video
    {
        if (isset($data['title']) && $data['title'] !== $video->title) {
            $data['slug'] = $this->uniqueSlug($data['title'], $video->id);
        }

        if (array_key_exists('password', $data)) {
            $data['password_hash'] = $data['password'] ? Hash::make($data['password']) : null;
            unset($data['password']);
        }

        $video->update($data);

        return $video->refresh();
    }

    public function delete(Video $video): void
    {
        if ($video->cloudflare_uid) {
            $this->cloudflareStreamService->deleteVideo($video->cloudflare_uid);
        }

        $video->delete();
    }

    public function createShare(Video $video, ?string $password = null, ?string $expiresAt = null): VideoShare
    {
        return $video->shares()->create([
            'expires_at' => $expiresAt,
            'is_active' => true,
            'password_hash' => $password ? Hash::make($password) : null,
        ]);
    }

    public function syncFromCloudflarePayload(array $payload): ?Video
    {
        $uid = data_get($payload, 'uid') ?? data_get($payload, 'result.uid');

        if (! $uid) {
            return null;
        }

        $video = Video::query()
            ->where('cloudflare_uid', $uid)
            ->orWhere('upload_uid', $uid)
            ->first();

        if (! $video) {
            return null;
        }

        $readyToStream = (bool) (data_get($payload, 'readyToStream') ?? data_get($payload, 'result.readyToStream'));
        $status = $readyToStream ? VideoStatus::Ready : VideoStatus::Processing;

        $video->update([
            'status' => $status,
            'processing_percentage' => $readyToStream ? 100 : 50,
            'duration_seconds' => (int) round((float) (data_get($payload, 'duration') ?? data_get($payload, 'result.duration') ?? 0)) ?: $video->duration_seconds,
            'thumbnail_url' => data_get($payload, 'thumbnail') ?? data_get($payload, 'result.thumbnail') ?? $video->thumbnail_url,
            'playback_url' => data_get($payload, 'playback.hls') ?? data_get($payload, 'result.playback.hls') ?? $video->playback_url,
            'cloudflare_meta' => $payload,
        ]);

        return $video->refresh();
    }

    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($title) ?: 'video';
        $slug = $baseSlug;
        $counter = 2;

        while (
            Video::query()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
