<?php

namespace App\Services\Video;

use App\Enums\VideoPrivacy;
use App\Enums\VideoStatus;
use App\Models\Video;
use App\Models\VideoShare;
use App\Models\VideoView;
use App\Services\Cloudflare\CloudflareStreamService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ramsey\Collection\Collection;
use RuntimeException;
use Throwable;

class VideoService
{
    /**
     * How long one Cloudflare status pull covers. Comfortably longer than a single
     * client poll interval, so a page polling every few seconds cannot turn into a
     * matching stream of Cloudflare API calls.
     */
    private const RECONCILE_THROTTLE_SECONDS = 5;

    /**
     * How long a row may sit unsettled before a Cloudflare 404 is taken to mean the
     * upload was abandoned rather than merely not registered yet.
     */
    private const ABANDONED_AFTER_MINUTES = 10;

    /**
     * How long a part-uploaded row may go without a progress write before it is
     * treated as having no live uploader behind it. Progress is reported every few
     * seconds, so a couple of minutes of silence is unambiguous.
     */
    private const UPLOAD_STALE_AFTER_MINUTES = 2;

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
            ->withCount(['views', 'favoritedBy'])
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

    public function createTusUploadUrl(int $userId, string $title, ?string $description, string $fileName, int $fileSize, string $fileType = 'video/webm', int $maxDurationSeconds = 7200): array
    {
        $video = Video::query()->create([
            'user_id' => $userId,
            'title' => $title,
            'description' => $description,
            'slug' => $this->uniqueSlug($title),
            'status' => VideoStatus::Uploading,
            'privacy' => VideoPrivacy::Public,
            'size_bytes' => $fileSize,
        ]);

        try {
            $directUpload =
                $this->cloudflareStreamService->createTusUploadUrl(
                    fileSize: $fileSize,
                    fileName: $fileName,
                    fileType: $fileType,
                    creatorId: (string) $userId,
                    maxDurationSeconds: $maxDurationSeconds,
                );

            $cloudflareUid = $directUpload['uid'] ?? null;
            $uploadUrl = $directUpload['uploadURL'] ?? null;

            if (
                ! is_string($cloudflareUid) ||
                $cloudflareUid === '' ||
                ! is_string($uploadUrl) ||
                $uploadUrl === ''
            ) {
                throw new RuntimeException(
                    'Cloudflare tus response is missing required values.'
                );
            }

            $video->update([
                'upload_uid' => $cloudflareUid,
                'cloudflare_uid' => $cloudflareUid,
                'cloudflare_meta' => [
                    'upload_protocol' => 'tus',
                    'file_name' => $fileName,
                    'file_type' => $fileType,
                    'file_size' => $fileSize,
                    'max_duration_seconds' => $maxDurationSeconds,
                ],
            ]);

            return [
                'video' => $video->refresh(),
                'upload_url' => $uploadUrl,
                'upload_uid' => $cloudflareUid,
            ];
        } catch (Throwable $exception) {
            $video->forceDelete();

            report($exception);

            throw new RuntimeException(
                'Unable to create resumable upload URL. Please retry.',
                previous: $exception,
            );
        }
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
            try {
                $this->cloudflareStreamService->deleteVideo($video->cloudflare_uid);
            } catch (\RuntimeException $e) {
                if (str_contains($e->getMessage(), '"code": 10003')) {
                    Log::warning('Cloudflare video not found. Deleting database record.', [
                        'video_id' => $video->id,
                        'cloudflare_uid' => $video->cloudflare_uid,
                    ]);
                } else {
                    throw $e;
                }
            }
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

    /**
     * Persist the real upload percentage the browser is reporting mid-transfer.
     *
     * Deliberately narrow: it only ever moves the bar forward, and only while the
     * row is still `uploading`. Both guards matter because this endpoint races
     * Cloudflare's webhook — a progress ping issued at 99% can easily land after
     * the webhook has already taken the video to processing or ready, and without
     * them that late ping would drag a playable video back to "uploading 99%".
     *
     * Never touches `status` beyond keeping it at `uploading`: a finished byte
     * transfer is not a playable video, so only the webhook may promote it.
     */
    public function recordUploadProgress(Video $video, int $percentage): Video
    {
        if ($video->status !== VideoStatus::Uploading) {
            return $video;
        }

        $percentage = max(0, min(100, $percentage));

        // Equal is not worth a write; lower means an out-of-order request.
        if ($percentage <= (int) $video->processing_percentage) {
            return $video;
        }

        $video->update([
            'status' => VideoStatus::Uploading,
            'processing_percentage' => $percentage,
        ]);

        return $video;
    }

    /**
     * Apply a Cloudflare webhook payload to its video row.
     *
     * This is the webhook's critical path, so it does exactly one lookup and one
     * write and makes no outbound Cloudflare calls — the download-URL backfill
     * that used to run inline lives in backfillCloudflareDownload(), which the
     * controller runs after the response has been flushed.
     *
     * Idempotent by construction: the same payload delivered twice computes the
     * same column values. `ready` is additionally treated as terminal, so a
     * re-delivered or delayed `inprogress` event cannot un-ready a video.
     */
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
        $state = (string) (data_get($payload, 'status.state') ?? data_get($payload, 'result.status.state') ?? '');

        $isFailed = $state === 'error';
        // Cloudflare only guarantees playability once readyToStream flips true;
        // a 100% pctComplete on its own does not mean the video will play.
        $isReady = $readyToStream && ! $isFailed;

        // Cloudflare is still waiting for bytes, so this is the upload phase, not
        // the transcode. Saying "processing" here would both lie to the user and
        // lock recordUploadProgress out of its own row (it only writes while the
        // status is `uploading`), freezing the progress bar mid-upload.
        $isAwaitingBytes = in_array($state, ['pendingupload', 'downloading'], true);

        if ($isFailed) {
            $status = VideoStatus::Failed;
            $percentage = (int) $video->processing_percentage;
        } elseif ($isAwaitingBytes && $video->status === VideoStatus::Uploading) {
            $status = VideoStatus::Uploading;
            $percentage = (int) $video->processing_percentage;
        } elseif ($isReady) {
            $status = VideoStatus::Ready;
            $percentage = 100;
        } else {
            $status = VideoStatus::Processing;
            // Real transcode progress when Cloudflare sends it (a string such as
            // "39.000000"); otherwise hold whatever the upload already reported
            // rather than snapping the bar back to a hardcoded 50.
            $reported = data_get($payload, 'status.pctComplete') ?? data_get($payload, 'result.status.pctComplete');
            $percentage = $reported !== null
                ? max(0, min(100, (int) round((float) $reported)))
                : (int) $video->processing_percentage;
        }

        // `ready` is terminal. Out-of-order deliveries are normal with webhooks,
        // and re-processing an older event must not corrupt a settled status.
        if ($video->status === VideoStatus::Ready && ! $isFailed) {
            $status = VideoStatus::Ready;
            $percentage = 100;
        }

        $video->update([
            'status' => $status,
            'processing_percentage' => $percentage,
            'duration_seconds' => (int) round((float) (data_get($payload, 'duration') ?? data_get($payload, 'result.duration') ?? 0)) ?: $video->duration_seconds,
            'size_bytes' => $this->extractSizeBytes($payload) ?? $video->size_bytes,
            'thumbnail_url' => data_get($payload, 'thumbnail') ?? data_get($payload, 'result.thumbnail') ?? $video->thumbnail_url,
            'playback_url' => data_get($payload, 'playback.hls') ?? data_get($payload, 'result.playback.hls') ?? $video->playback_url,
            'download_url' => $this->extractDownloadUrl($payload) ?? $video->download_url,
            'cloudflare_meta' => $payload,
        ]);

        return $video->refresh();
    }

    /**
     * Pull status from Cloudflare for a video that has not settled yet.
     *
     * The webhook is the fast path, not the only path. A push that never arrives
     * leaves a video stuck at "uploading 100%" forever even though Cloudflare
     * finished transcoding it — which is guaranteed in local development, where
     * Cloudflare cannot reach the dev server at all, and happens in production
     * whenever a delivery is dropped or delayed.
     *
     * This asks Cloudflare directly and feeds the answer through exactly the same
     * mapper the webhook uses, so `ready` still means "Cloudflare confirmed it is
     * playable" — the confirmation is simply pulled instead of pushed, and every
     * guard (ready-is-terminal, pendingupload, error) applies identically.
     *
     * @return Video The video, updated if Cloudflare had something newer.
     */
    public function reconcileFromCloudflare(Video $video): Video
    {
        // Settled videos never change again, so there is nothing to ask about.
        if (in_array($video->status, [VideoStatus::Ready, VideoStatus::Failed], true)) {
            return $video;
        }

        // Mid-upload the browser is the authority on progress and Cloudflare has
        // nothing useful to add — it is still waiting for bytes.
        //
        // "Mid-upload" has to mean an upload that is actually running, though, not
        // merely one that never finished: a tab closed at 40% would otherwise leave
        // the row `uploading` for ever, polling for ever. Progress writes bump
        // `updated_at`, so a row that has not moved in a while has no live uploader
        // behind it and is safe to ask Cloudflare about. A paused upload is safe
        // too — Cloudflare answers `pendingupload`, which is handled above and
        // leaves both status and percentage exactly as they were.
        if (
            $video->status === VideoStatus::Uploading
            && (int) $video->processing_percentage < 100
            && $video->updated_at
            && $video->updated_at->gt(now()->subMinutes(self::UPLOAD_STALE_AFTER_MINUTES))
        ) {
            return $video;
        }

        $uid = (string) ($video->cloudflare_uid ?? $video->upload_uid);

        if ($uid === '') {
            return $video;
        }

        // Every viewer of a processing video polls, so without this a popular share
        // link would multiply into one Cloudflare call per viewer per few seconds.
        // One call per video per window, no matter how many pollers.
        $throttleKey = 'cf_reconcile:'.$uid;

        if (! Cache::add($throttleKey, true, self::RECONCILE_THROTTLE_SECONDS)) {
            return $video;
        }

        try {
            $cloudflareVideo = $this->cloudflareStreamService->findVideo($uid);
        } catch (Throwable $exception) {
            Log::channel('webhooks')->warning('Cloudflare reconcile failed.', [
                'uid' => $uid,
                'video_uuid' => $video->uuid,
                'error' => $exception->getMessage(),
            ]);

            return $video;
        }

        // Cloudflare has no such video. The media id exists from the moment the
        // upload is created, so a 404 is not a race — the upload was abandoned and
        // Cloudflare has dropped it. Left as `uploading` this row would poll for
        // ever and read as "almost done" to the user; `failed` is both the truth
        // and terminal. The age check is pure belt-and-braces against a freshly
        // minted row being judged before Cloudflare has registered it.
        if ($cloudflareVideo === null) {
            if ($video->created_at && $video->created_at->lt(now()->subMinutes(self::ABANDONED_AFTER_MINUTES))) {
                $video->update(['status' => VideoStatus::Failed]);

                return $video->refresh();
            }

            return $video;
        }

        return $this->syncFromCloudflarePayload($cloudflareVideo) ?? $video;
    }

    /**
     * Fill in download URL and size for a ready video.
     *
     * Same logic that used to sit inside syncFromCloudflarePayload; it costs up to
     * two Cloudflare API round trips, which is why it no longer blocks the webhook
     * response. Playback never depended on it — only the Download button does.
     */
    public function backfillCloudflareDownload(Video $video): void
    {
        if ($video->status !== VideoStatus::Ready) {
            return;
        }

        if ($video->download_url && $video->size_bytes) {
            return;
        }

        $uid = (string) ($video->cloudflare_uid ?? $video->upload_uid);

        if ($uid === '') {
            return;
        }

        $downloadUrl = $video->download_url;
        $sizeBytes = $video->size_bytes;

        try {
            $download = $this->cloudflareStreamService->createDownload($uid);
            $downloadUrl = $this->extractDownloadUrl($download) ?? $downloadUrl;

            if (! $downloadUrl || ! $sizeBytes) {
                $cloudflareVideo = $this->cloudflareStreamService->getVideo($uid);
                $downloadUrl = $this->extractDownloadUrl($cloudflareVideo) ?? $downloadUrl;
                $sizeBytes = $this->extractSizeBytes($cloudflareVideo) ?? $sizeBytes;
            }
        } catch (Throwable $exception) {
            Log::channel('webhooks')->warning('Unable to resolve Cloudflare download URL or size during webhook sync.', [
                'uid' => $uid,
                'video_uuid' => $video->uuid,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        if ($downloadUrl === $video->download_url && $sizeBytes === $video->size_bytes) {
            return;
        }

        $video->update([
            'download_url' => $downloadUrl,
            'size_bytes' => $sizeBytes,
        ]);
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
            ->when($ignoreId, fn($query) => $query->where('id', '!=', $ignoreId))
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

    public function getViewStats(int $userId): array
    {
        $videoIds = Video::query()
            ->where('user_id', $userId)
            ->pluck('id');

        $baseQuery = VideoView::query()->whereIn('video_id', $videoIds);

        return [
            'today' => (clone $baseQuery)
                ->whereDate('created_at', today())
                ->count(),

            'this_week' => (clone $baseQuery)
                ->whereBetween('created_at', [
                    now()->startOfWeek(),
                    now()->endOfWeek(),
                ])
                ->count(),

            'this_month' => (clone $baseQuery)
                ->whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->count(),

            'all_time' => (clone $baseQuery)
                ->count(),
        ];
    }
}
