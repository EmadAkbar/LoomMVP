<?php

namespace Tests\Feature;

use App\Enums\VideoPrivacy;
use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoShare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VideoShareApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_share_link(): void
    {
        $user = User::factory()->create();

        $video = Video::query()->create([
            'user_id' => $user->id,
            'title' => 'Shareable Video',
            'slug' => 'shareable-video',
            'status' => VideoStatus::Ready,
            'privacy' => VideoPrivacy::Public,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/videos/' . $video->uuid . '/shares');

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('video_shares', [
            'video_id' => $video->id,
            'is_active' => true,
        ]);
    }

    public function test_store_rejects_non_public_video_share_creation(): void
    {
        $user = User::factory()->create();

        $video = Video::query()->create([
            'user_id' => $user->id,
            'title' => 'Unlisted Should Not Share',
            'slug' => 'unlisted-should-not-share',
            'status' => VideoStatus::Ready,
            'privacy' => VideoPrivacy::Unlisted,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/videos/' . $video->uuid . '/shares')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['video']);
    }

    public function test_store_returns_not_found_for_non_owner_instead_of_forbidden(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $video = Video::query()->create([
            'user_id' => $owner->id,
            'title' => 'Owner Public Video',
            'slug' => 'owner-public-video',
            'status' => VideoStatus::Ready,
            'privacy' => VideoPrivacy::Public,
        ]);

        Sanctum::actingAs($otherUser);

        $this->postJson('/api/v1/videos/' . $video->uuid . '/shares')
            ->assertNotFound();
    }

    public function test_public_share_show_returns_video_for_public_share_without_password_prompt(): void
    {
        $video = Video::query()->create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Public Video',
            'slug' => 'public-video',
            'status' => VideoStatus::Ready,
            'privacy' => VideoPrivacy::Public,
        ]);

        $share = VideoShare::query()->create([
            'video_id' => $video->id,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/share/' . $share->share_uuid)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.requires_password', false)
            ->assertJsonPath('data.video.uuid', $video->uuid);
    }

    public function test_share_show_returns_not_found_for_non_public_video(): void
    {
        $video = Video::query()->create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Private Video',
            'slug' => 'private-video',
            'status' => VideoStatus::Ready,
            'privacy' => VideoPrivacy::Private,
        ]);

        $share = VideoShare::query()->create([
            'video_id' => $video->id,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/share/' . $share->share_uuid)->assertNotFound();
    }

    public function test_verify_password_returns_validation_error_for_public_share(): void
    {
        $video = Video::query()->create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Public Video Verify',
            'slug' => 'public-video-verify',
            'status' => VideoStatus::Ready,
            'privacy' => VideoPrivacy::Public,
        ]);

        $share = VideoShare::query()->create([
            'video_id' => $video->id,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/share/' . $share->share_uuid . '/verify-password', [
            'password' => 'any-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_verify_password_returns_not_found_for_non_public_share(): void
    {
        $video = Video::query()->create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Disabled Video Verify',
            'slug' => 'disabled-video-verify',
            'status' => VideoStatus::Ready,
            'privacy' => VideoPrivacy::Disabled,
        ]);

        $share = VideoShare::query()->create([
            'video_id' => $video->id,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/share/' . $share->share_uuid . '/verify-password', [
            'password' => 'any-password',
        ])->assertNotFound();
    }
}
