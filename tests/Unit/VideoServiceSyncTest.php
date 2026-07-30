<?php

namespace Tests\Unit;

use App\Enums\VideoPrivacy;
use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Services\Cloudflare\CloudflareStreamService;
use App\Services\Video\VideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class VideoServiceSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_from_cloudflare_payload_sets_size_and_download_url_from_payload(): void
    {
        $video = Video::query()->create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Payload Sync Video',
            'slug' => 'payload-sync-video',
            'status' => VideoStatus::Uploading,
            'privacy' => VideoPrivacy::Private,
            'cloudflare_uid' => 'cf-payload-uid-1',
            'upload_uid' => 'cf-payload-uid-1',
        ]);

        $cloudflareMock = Mockery::mock(CloudflareStreamService::class);
        $this->app->instance(CloudflareStreamService::class, $cloudflareMock);

        /** @var VideoService $service */
        $service = $this->app->make(VideoService::class);

        $synced = $service->syncFromCloudflarePayload([
            'uid' => 'cf-payload-uid-1',
            'readyToStream' => true,
            'duration' => 42.3,
            'size' => 321654,
            'playback' => [
                'hls' => 'https://videodelivery.example.com/manifest/video.m3u8',
            ],
            'download' => [
                'url' => 'https://videodelivery.example.com/download/video.mp4',
            ],
        ]);

        $this->assertNotNull($synced);
        $this->assertSame(321654, $synced->size_bytes);
        $this->assertSame('https://videodelivery.example.com/download/video.mp4', $synced->download_url);
        $this->assertSame(VideoStatus::Ready, $synced->status);
    }

    /**
     * The webhook's critical path must not make outbound calls: resolving a
     * download URL costs up to two Cloudflare round trips, and the status write
     * used to sit behind them. A strict mock with no expectations fails the test
     * if anything reaches Cloudflare.
     */
    public function test_sync_from_cloudflare_payload_makes_no_cloudflare_calls(): void
    {
        $this->makeVideo('cf-fallback-uid-1');

        $cloudflareMock = Mockery::mock(CloudflareStreamService::class);
        $this->app->instance(CloudflareStreamService::class, $cloudflareMock);

        /** @var VideoService $service */
        $service = $this->app->make(VideoService::class);

        $synced = $service->syncFromCloudflarePayload([
            'uid' => 'cf-fallback-uid-1',
            'readyToStream' => true,
            'duration' => 12,
            'playback' => [
                'hls' => 'https://videodelivery.example.com/manifest/fallback.m3u8',
            ],
        ]);

        $this->assertNotNull($synced);
        $this->assertSame(VideoStatus::Ready, $synced->status);
        $this->assertSame(100, $synced->processing_percentage);
        // Not yet resolved — that is backfillCloudflareDownload()'s job, below.
        $this->assertNull($synced->download_url);
    }

    public function test_backfill_resolves_download_url_when_missing_from_payload(): void
    {
        $video = $this->makeVideo('cf-fallback-uid-2', [
            'status' => VideoStatus::Ready,
            'size_bytes' => 123456,
        ]);

        $cloudflareMock = Mockery::mock(CloudflareStreamService::class);
        $cloudflareMock->shouldReceive('createDownload')
            ->once()
            ->with('cf-fallback-uid-2')
            ->andReturn([
                'default' => [
                    'url' => 'https://videodelivery.example.com/download/fallback.mp4',
                ],
            ]);

        $this->app->instance(CloudflareStreamService::class, $cloudflareMock);

        /** @var VideoService $service */
        $service = $this->app->make(VideoService::class);
        $service->backfillCloudflareDownload($video);

        $this->assertSame(
            'https://videodelivery.example.com/download/fallback.mp4',
            $video->refresh()->download_url,
        );
    }

    /**
     * Webhook deliveries arrive out of order and get retried. Once a video is
     * playable, replaying an older "inprogress" event must not un-ready it.
     */
    public function test_ready_is_terminal_against_a_stale_redelivered_event(): void
    {
        $this->makeVideo('cf-terminal-uid', ['status' => VideoStatus::Ready, 'processing_percentage' => 100]);

        $this->app->instance(CloudflareStreamService::class, Mockery::mock(CloudflareStreamService::class));
        /** @var VideoService $service */
        $service = $this->app->make(VideoService::class);

        $synced = $service->syncFromCloudflarePayload([
            'uid' => 'cf-terminal-uid',
            'readyToStream' => false,
            'status' => ['state' => 'inprogress', 'pctComplete' => '40.000000'],
        ]);

        $this->assertSame(VideoStatus::Ready, $synced->status);
        $this->assertSame(100, $synced->processing_percentage);
    }

    public function test_processing_percentage_follows_cloudflare_pct_complete(): void
    {
        $this->makeVideo('cf-progress-uid', ['processing_percentage' => 100]);

        $this->app->instance(CloudflareStreamService::class, Mockery::mock(CloudflareStreamService::class));
        /** @var VideoService $service */
        $service = $this->app->make(VideoService::class);

        $synced = $service->syncFromCloudflarePayload([
            'uid' => 'cf-progress-uid',
            'readyToStream' => false,
            'status' => ['state' => 'inprogress', 'pctComplete' => '37.500000'],
        ]);

        $this->assertSame(VideoStatus::Processing, $synced->status);
        $this->assertSame(38, $synced->processing_percentage);
    }

    public function test_an_error_state_marks_the_video_failed(): void
    {
        $this->makeVideo('cf-error-uid');

        $this->app->instance(CloudflareStreamService::class, Mockery::mock(CloudflareStreamService::class));
        /** @var VideoService $service */
        $service = $this->app->make(VideoService::class);

        $synced = $service->syncFromCloudflarePayload([
            'uid' => 'cf-error-uid',
            'readyToStream' => false,
            'status' => ['state' => 'error', 'errorReasonCode' => 'ERR_NON_VIDEO'],
        ]);

        $this->assertSame(VideoStatus::Failed, $synced->status);
    }

    public function test_record_upload_progress_only_moves_forward_and_never_readies(): void
    {
        $video = $this->makeVideo('cf-upload-uid');

        $this->app->instance(CloudflareStreamService::class, Mockery::mock(CloudflareStreamService::class));
        /** @var VideoService $service */
        $service = $this->app->make(VideoService::class);

        $service->recordUploadProgress($video, 40);
        $this->assertSame(40, $video->refresh()->processing_percentage);

        // An out-of-order request from a slow connection must not rewind the bar.
        $service->recordUploadProgress($video, 12);
        $this->assertSame(40, $video->refresh()->processing_percentage);

        // A finished byte transfer is not a playable video.
        $service->recordUploadProgress($video, 100);
        $video->refresh();
        $this->assertSame(100, $video->processing_percentage);
        $this->assertSame(VideoStatus::Uploading, $video->status);
    }

    /**
     * The reverse race: a progress ping issued at 99% can land after the webhook
     * has already readied the video. It must be ignored, not applied.
     */
    public function test_record_upload_progress_cannot_drag_back_a_settled_video(): void
    {
        $video = $this->makeVideo('cf-settled-uid', [
            'status' => VideoStatus::Ready,
            'processing_percentage' => 100,
        ]);

        $this->app->instance(CloudflareStreamService::class, Mockery::mock(CloudflareStreamService::class));
        /** @var VideoService $service */
        $service = $this->app->make(VideoService::class);

        $service->recordUploadProgress($video, 99);

        $video->refresh();
        $this->assertSame(VideoStatus::Ready, $video->status);
        $this->assertSame(100, $video->processing_percentage);
    }

    /**
     * The stuck-video case: bytes are all uploaded, Cloudflare has finished
     * transcoding, but its webhook never arrived — guaranteed in local dev, where
     * Cloudflare cannot reach the dev server. Pulling the status must recover it.
     */
    public function test_reconcile_recovers_a_video_whose_webhook_never_arrived(): void
    {
        $video = $this->makeVideo('cf-stuck-uid', ['processing_percentage' => 100]);

        $cloudflareMock = Mockery::mock(CloudflareStreamService::class);
        $cloudflareMock->shouldReceive("findVideo")
            ->once()
            ->with('cf-stuck-uid')
            ->andReturn([
                'uid' => 'cf-stuck-uid',
                'readyToStream' => true,
                'status' => ['state' => 'ready', 'pctComplete' => '100.000000'],
                'duration' => 4.4,
                'thumbnail' => 'https://cf.example.com/thumb.jpg',
                'playback' => ['hls' => 'https://cf.example.com/video.m3u8'],
            ]);
        $this->app->instance(CloudflareStreamService::class, $cloudflareMock);

        /** @var VideoService $service */
        $service = $this->app->make(VideoService::class);
        $reconciled = $service->reconcileFromCloudflare($video);

        $this->assertSame(VideoStatus::Ready, $reconciled->status);
        $this->assertSame('https://cf.example.com/video.m3u8', $reconciled->playback_url);
        $this->assertSame('https://cf.example.com/thumb.jpg', $reconciled->thumbnail_url);
    }

    /**
     * Mid-upload the browser owns the progress number. Asking Cloudflare then would
     * get `pendingupload` back and, worse, could flip the row out of `uploading`
     * and lock the progress reporter out of it. A strict mock proves no call is made.
     */
    public function test_reconcile_does_not_call_cloudflare_mid_upload(): void
    {
        $video = $this->makeVideo('cf-inflight-uid', ['processing_percentage' => 55]);

        $this->app->instance(CloudflareStreamService::class, Mockery::mock(CloudflareStreamService::class));

        /** @var VideoService $service */
        $service = $this->app->make(VideoService::class);
        $service->reconcileFromCloudflare($video);

        $this->assertSame(VideoStatus::Uploading, $video->refresh()->status);
        $this->assertSame(55, $video->processing_percentage);
    }

    /**
     * A tab closed at 40% leaves a row nothing will ever finish. It must not stay
     * `uploading` for ever (which also means polling for ever) — once the progress
     * writes stop, Cloudflare gets asked, says it has no such video, and the row is
     * settled as failed.
     */
    public function test_reconcile_settles_an_upload_abandoned_part_way(): void
    {
        $video = $this->makeVideo('cf-abandoned-uid', ['processing_percentage' => 40]);
        // No progress write for a while, and created long enough ago that a 404 is
        // unambiguous.
        $video->forceFill([
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ])->saveQuietly();

        $cloudflareMock = Mockery::mock(CloudflareStreamService::class);
        $cloudflareMock->shouldReceive('findVideo')
            ->once()
            ->with('cf-abandoned-uid')
            ->andReturn(null); // Cloudflare dropped the incomplete upload
        $this->app->instance(CloudflareStreamService::class, $cloudflareMock);

        /** @var VideoService $service */
        $service = $this->app->make(VideoService::class);
        $reconciled = $service->reconcileFromCloudflare($video->fresh());

        $this->assertSame(VideoStatus::Failed, $reconciled->status);
    }

    /**
     * The other side of that coin: a paused upload has also stopped writing progress,
     * but Cloudflare still holds the resource and answers `pendingupload`. The row
     * must survive untouched so resuming still works.
     */
    public function test_reconcile_leaves_a_paused_upload_alone(): void
    {
        $video = $this->makeVideo('cf-paused-uid', ['processing_percentage' => 40]);
        $video->forceFill([
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ])->saveQuietly();

        $cloudflareMock = Mockery::mock(CloudflareStreamService::class);
        $cloudflareMock->shouldReceive('findVideo')
            ->once()
            ->andReturn([
                'uid' => 'cf-paused-uid',
                'readyToStream' => false,
                'status' => ['state' => 'pendingupload'],
            ]);
        $this->app->instance(CloudflareStreamService::class, $cloudflareMock);

        /** @var VideoService $service */
        $service = $this->app->make(VideoService::class);
        $reconciled = $service->reconcileFromCloudflare($video->fresh());

        $this->assertSame(VideoStatus::Uploading, $reconciled->status);
        $this->assertSame(40, $reconciled->processing_percentage);
    }

    /** A freshly minted row must never be judged abandoned. */
    public function test_reconcile_does_not_fail_a_brand_new_row_on_a_404(): void
    {
        $video = $this->makeVideo('cf-new-uid', ['processing_percentage' => 100]);

        $cloudflareMock = Mockery::mock(CloudflareStreamService::class);
        $cloudflareMock->shouldReceive('findVideo')->once()->andReturn(null);
        $this->app->instance(CloudflareStreamService::class, $cloudflareMock);

        /** @var VideoService $service */
        $service = $this->app->make(VideoService::class);
        $reconciled = $service->reconcileFromCloudflare($video);

        $this->assertSame(VideoStatus::Uploading, $reconciled->status);
    }

    public function test_reconcile_skips_settled_videos(): void
    {
        $ready = $this->makeVideo('cf-done-uid', ['status' => VideoStatus::Ready]);
        $failed = $this->makeVideo('cf-dead-uid', ['status' => VideoStatus::Failed]);

        $this->app->instance(CloudflareStreamService::class, Mockery::mock(CloudflareStreamService::class));

        /** @var VideoService $service */
        $service = $this->app->make(VideoService::class);
        $service->reconcileFromCloudflare($ready);
        $service->reconcileFromCloudflare($failed);

        $this->assertSame(VideoStatus::Ready, $ready->refresh()->status);
        $this->assertSame(VideoStatus::Failed, $failed->refresh()->status);
    }

    /**
     * Every viewer of a processing video polls, so the pull has to be shared rather
     * than per-request or a popular share link would fan out into Cloudflare calls.
     */
    public function test_reconcile_is_throttled_across_repeated_calls(): void
    {
        $video = $this->makeVideo('cf-throttle-uid', [
            'status' => VideoStatus::Processing,
            'processing_percentage' => 10,
        ]);

        $cloudflareMock = Mockery::mock(CloudflareStreamService::class);
        $cloudflareMock->shouldReceive("findVideo")
            ->once() // <- the assertion: three calls, one Cloudflare request
            ->with('cf-throttle-uid')
            ->andReturn([
                'uid' => 'cf-throttle-uid',
                'readyToStream' => false,
                'status' => ['state' => 'inprogress', 'pctComplete' => '20.000000'],
            ]);
        $this->app->instance(CloudflareStreamService::class, $cloudflareMock);

        /** @var VideoService $service */
        $service = $this->app->make(VideoService::class);
        $service->reconcileFromCloudflare($video->fresh());
        $service->reconcileFromCloudflare($video->fresh());
        $service->reconcileFromCloudflare($video->fresh());

        $this->assertSame(20, $video->refresh()->processing_percentage);
    }

    public function test_pending_upload_state_does_not_leave_the_uploading_phase(): void
    {
        $this->makeVideo('cf-pending-uid', ['processing_percentage' => 70]);

        $this->app->instance(CloudflareStreamService::class, Mockery::mock(CloudflareStreamService::class));
        /** @var VideoService $service */
        $service = $this->app->make(VideoService::class);

        $synced = $service->syncFromCloudflarePayload([
            'uid' => 'cf-pending-uid',
            'readyToStream' => false,
            'status' => ['state' => 'pendingupload'],
        ]);

        $this->assertSame(VideoStatus::Uploading, $synced->status);
        $this->assertSame(70, $synced->processing_percentage);
    }

    private function makeVideo(string $uid, array $attributes = []): Video
    {
        return Video::query()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'title' => 'Sync Video '.$uid,
            'slug' => 'sync-video-'.$uid,
            'status' => VideoStatus::Uploading,
            'privacy' => VideoPrivacy::Private,
            'cloudflare_uid' => $uid,
            'upload_uid' => $uid,
        ], $attributes));
    }
}
