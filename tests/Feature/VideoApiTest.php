<?php

namespace Tests\Feature;

use App\Enums\VideoPrivacy;
use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Services\Cloudflare\CloudflareStreamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class VideoApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_videos_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/videos')->assertUnauthorized();
    }

    public function test_authenticated_user_gets_only_own_videos(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        Video::query()->create([
            'user_id' => $owner->id,
            'title' => 'Owner Video',
            'slug' => 'owner-video',
            'status' => VideoStatus::Ready,
            'privacy' => VideoPrivacy::Unlisted,
        ]);

        Video::query()->create([
            'user_id' => $other->id,
            'title' => 'Other Video',
            'slug' => 'other-video',
            'status' => VideoStatus::Ready,
            'privacy' => VideoPrivacy::Unlisted,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/videos');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.title', 'Owner Video');
    }

    public function test_user_cannot_access_another_users_video(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $video = Video::query()->create([
            'user_id' => $owner->id,
            'title' => 'Private Owner Video',
            'slug' => 'private-owner-video',
            'status' => VideoStatus::Ready,
            'privacy' => VideoPrivacy::Private,
        ]);

        Sanctum::actingAs($intruder);

        $this->getJson('/api/v1/videos/' . $video->uuid)->assertForbidden();
    }

    public function test_owner_can_update_video_title_and_password(): void
    {
        $owner = User::factory()->create();

        $video = Video::query()->create([
            'user_id' => $owner->id,
            'title' => 'Initial Video',
            'slug' => 'initial-video',
            'status' => VideoStatus::Ready,
            'privacy' => VideoPrivacy::Unlisted,
        ]);

        Sanctum::actingAs($owner);

        $this->patchJson('/api/v1/videos/' . $video->uuid, [
            'title' => 'Updated Video',
            'privacy' => VideoPrivacy::Password->value,
            'password' => 'pass1234',
        ])->assertOk()
            ->assertJsonPath('data.video.title', 'Updated Video')
            ->assertJsonPath('data.video.privacy', VideoPrivacy::Password->value);

        $video->refresh();

        $this->assertSame('updated-video', $video->slug);
        $this->assertNotNull($video->password_hash);
    }

    public function test_owner_can_delete_video_and_cloudflare_delete_is_called(): void
    {
        $owner = User::factory()->create();

        $video = Video::query()->create([
            'user_id' => $owner->id,
            'title' => 'Delete Me',
            'slug' => 'delete-me',
            'status' => VideoStatus::Ready,
            'privacy' => VideoPrivacy::Unlisted,
            'cloudflare_uid' => 'cf-video-uid-123',
        ]);

        $cloudflareMock = Mockery::mock(CloudflareStreamService::class);
        $cloudflareMock->shouldReceive('deleteVideo')
            ->once()
            ->with('cf-video-uid-123');

        $this->app->instance(CloudflareStreamService::class, $cloudflareMock);

        Sanctum::actingAs($owner);

        $this->deleteJson('/api/v1/videos/' . $video->uuid)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('videos', ['id' => $video->id]);
    }
}
