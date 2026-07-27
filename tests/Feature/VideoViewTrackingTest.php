<?php

namespace Tests\Feature;

use App\Enums\VideoPrivacy;
use App\Enums\VideoStatus;
use App\Mail\UniqueVideoViewAlertMail;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class VideoViewTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_viewer_triggers_single_admin_email_for_same_fingerprint(): void
    {
        config([
            'loom.notifications.admin_emails' => ['admin@example.com'],
        ]);

        Mail::fake();

        $video = Video::query()->create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Track Views Video',
            'slug' => 'track-views-video',
            'status' => VideoStatus::Ready,
            'privacy' => VideoPrivacy::Public,
        ]);

        $payload = [
            'watch_seconds' => 12,
            'viewer_device_id' => 'mac-or-device-123',
        ];

        $this->postJson('/api/v1/videos/' . $video->uuid . '/views', $payload)
            ->assertCreated()
            ->assertJsonPath('data.is_unique_viewer', true);

        $this->postJson('/api/v1/videos/' . $video->uuid . '/views', $payload)
            ->assertCreated()
            ->assertJsonPath('data.is_unique_viewer', false);

        $this->assertDatabaseCount('video_views', 2);

        Mail::assertSent(UniqueVideoViewAlertMail::class, 1);
    }
}
