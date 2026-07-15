<?php

namespace App\Services\Video;

use App\Enums\VideoPrivacy;
use App\Enums\VideoStatus;
use App\Models\Video;
use App\Models\VideoShare;
use App\Services\Cloudflare\CloudflareStreamService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class VideoService
{
    public function __construct(
        private readonly CloudflareStreamService $cloudflareStreamService
    ) {}

    public function paginateForUser(int $userId, int $perPage = 12, string $search = '', string $privacy = ''): LengthAwarePaginator
    {
        return Video::query()
            ->where('user_id', $userId)
            ->when($search !== '', function ($query) use ($search) {
                $query->where('title', 'like', "%{$search}%");
            })
            ->when($privacy !== '', function ($query) use ($privacy) {
                $query->where('privacy', $privacy);
            })
            ->latest()
            ->paginate($perPage);
    }

    public function createUploadUrl(int $userId, string $title = 'Untitled Video', ?string $description = null): array
    {
        $video = Video::query()->create([
            'user_id' => $userId,
            'title' => $title,
            'description' => $description,
            'slug' => $this->uniqueSlug($title),
            'status' => VideoStatus::Uploading,
            'privacy' => VideoPrivacy::Public,
        ]);

        try {
            $directUpload = $this->cloudflareStreamService->createDirectUploadUrl([
                'video_id' => (string) $video->uuid,
                'user_id' => (string) $userId,
                'title' => $title,
                'description' => $description,
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
        $sizeBytes = $this->extractSizeBytes($payload) ?? $video->size_bytes;
        $downloadUrl = $this->extractDownloadUrl($payload) ?? $video->download_url;

        if ($readyToStream && (! $downloadUrl || ! $sizeBytes)) {
            try {
                $download = $this->cloudflareStreamService->createDownload((string) $uid);
                $downloadUrl = $this->extractDownloadUrl($download) ?? $downloadUrl;

                if (! $downloadUrl || ! $sizeBytes) {
                    $cloudflareVideo = $this->cloudflareStreamService->getVideo((string) $uid);
                    $downloadUrl = $this->extractDownloadUrl($cloudflareVideo) ?? $downloadUrl;
                    $sizeBytes = $this->extractSizeBytes($cloudflareVideo) ?? $sizeBytes;
                }
            } catch (Throwable $exception) {
                Log::channel('webhooks')->warning('Unable to resolve Cloudflare download URL or size during webhook sync.', [
                    'uid' => $uid,
                    'video_uuid' => $video->uuid,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $video->update([
            'status' => $status,
            'processing_percentage' => $readyToStream ? 100 : 50,
            'duration_seconds' => (int) round((float) (data_get($payload, 'duration') ?? data_get($payload, 'result.duration') ?? 0)) ?: $video->duration_seconds,
            'size_bytes' => $sizeBytes,
            'thumbnail_url' => data_get($payload, 'thumbnail') ?? data_get($payload, 'result.thumbnail') ?? $video->thumbnail_url,
            'playback_url' => data_get($payload, 'playback.hls') ?? data_get($payload, 'result.playback.hls') ?? $video->playback_url,
            'download_url' => $downloadUrl,
            'cloudflare_meta' => $payload,
        ]);

        return $video->refresh();
    }

    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $slug = Str::slug($title) . '-' . Str::lower(Str::random(6));
        return $slug;

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

    private function extractSizeBytes(array $payload): ?int
    {
        $size = data_get($payload, 'size')
            ?? data_get($payload, 'result.size')
            ?? data_get($payload, 'maxSizeBytes')
            ?? data_get($payload, 'result.maxSizeBytes');

        if ($size === null || $size === '') {
            return null;
        }

        $parsed = (int) $size;

        return $parsed > 0 ? $parsed : null;
    }

    private function extractDownloadUrl(array $payload): ?string
    {
        $candidates = [
            data_get($payload, 'download_url'),
            data_get($payload, 'download.url'),
            data_get($payload, 'download.urlSigned'),
            data_get($payload, 'downloads.default.url'),
            data_get($payload, 'downloads.0.url'),
            data_get($payload, 'default.url'),
            data_get($payload, 'default.urlSigned'),
            data_get($payload, 'url'),
            data_get($payload, 'urlSigned'),
            data_get($payload, 'result.download_url'),
            data_get($payload, 'result.download.url'),
            data_get($payload, 'result.download.urlSigned'),
            data_get($payload, 'result.downloads.default.url'),
            data_get($payload, 'result.downloads.0.url'),
            data_get($payload, 'result.default.url'),
            data_get($payload, 'result.default.urlSigned'),
            data_get($payload, 'result.url'),
            data_get($payload, 'result.urlSigned'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }
}
