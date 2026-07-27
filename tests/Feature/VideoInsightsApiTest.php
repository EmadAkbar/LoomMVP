<?php

namespace Tests\Feature;

use App\Enums\VideoPrivacy;
use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VideoInsightsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_fetch_video_view_insights(): void
    {
        $owner = User::factory()->create();

        $video = Video::query()->create([
            'user_id' => $owner->id,
            'title' => 'Insights Video',
            'slug' => 'insights-video',
            'status' => VideoStatus::Ready,
            'privacy' => VideoPrivacy::Public,
        ]);

        VideoView::query()->create([
            'video_id' => $video->id,
            'viewer_ip' => '10.0.0.1',
            'viewer_agent' => 'Agent A',
            'viewer_fingerprint' => 'fp-1',
            'watch_seconds' => 15,
        ]);

        VideoView::query()->create([
            'video_id' => $video->id,
            'viewer_ip' => '10.0.0.1',
            'viewer_agent' => 'Agent A',
            'viewer_fingerprint' => 'fp-1',
            'watch_seconds' => 30,
        ]);

        VideoView::query()->create([
            'video_id' => $video->id,
            'viewer_ip' => '10.0.0.2',
            'viewer_agent' => 'Agent B',
            'viewer_fingerprint' => 'fp-2',
            'watch_seconds' => 45,
        ]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/videos/' . $video->uuid . '/insights/views?days=30')
            ->assertOk()
            ->assertJsonPath('data.total_views', 3)
            ->assertJsonPath('data.unique_views', 2)
            ->assertJsonPath('data.video_uuid', $video->uuid);
    }

    public function test_non_owner_cannot_fetch_video_view_insights(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $video = Video::query()->create([
            'user_id' => $owner->id,
            'title' => 'Owner Insights',
            'slug' => 'owner-insights',
            'status' => VideoStatus::Ready,
            'privacy' => VideoPrivacy::Public,
        ]);

        Sanctum::actingAs($otherUser);

        $this->getJson('/api/v1/videos/' . $video->uuid . '/insights/views')
            ->assertForbidden();
    }
}
