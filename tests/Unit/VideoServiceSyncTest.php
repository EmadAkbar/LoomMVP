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

    public function test_sync_from_cloudflare_payload_fetches_download_url_when_missing_in_payload(): void
    {
        $video = Video::query()->create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Download Fallback Video',
            'slug' => 'download-fallback-video',
            'status' => VideoStatus::Uploading,
            'privacy' => VideoPrivacy::Private,
            'cloudflare_uid' => 'cf-fallback-uid-1',
            'upload_uid' => 'cf-fallback-uid-1',
        ]);

        $cloudflareMock = Mockery::mock(CloudflareStreamService::class);
        $cloudflareMock->shouldReceive('createDownload')
            ->once()
            ->with('cf-fallback-uid-1')
            ->andReturn([
                'default' => [
                    'url' => 'https://videodelivery.example.com/download/fallback.mp4',
                ],
            ]);

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
        $this->assertSame('https://videodelivery.example.com/download/fallback.mp4', $synced->download_url);
        $this->assertSame(VideoStatus::Ready, $synced->status);
    }
}
