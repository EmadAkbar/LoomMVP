<?php

namespace Tests\Feature;

use App\Enums\VideoPrivacy;
use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoShare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

        $response = $this->postJson('/api/v1/videos/' . $video->uuid . '/shares', [
            'password' => '1234',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('video_shares', [
            'video_id' => $video->id,
            'is_active' => true,
        ]);
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

    public function test_public_share_ignores_share_level_password_prompt(): void
    {
        $video = Video::query()->create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Public Video With Share Password',
            'slug' => 'public-video-with-share-password',
            'status' => VideoStatus::Ready,
            'privacy' => VideoPrivacy::Public,
        ]);

        $share = VideoShare::query()->create([
            'video_id' => $video->id,
            'is_active' => true,
            'password_hash' => Hash::make('1234'),
        ]);

        $this->getJson('/api/v1/share/' . $share->share_uuid)
            ->assertOk()
            ->assertJsonPath('data.requires_password', false)
            ->assertJsonPath('data.video.uuid', $video->uuid);
    }

    public function test_password_protected_share_requires_password_and_verifies_successfully(): void
    {
        $video = Video::query()->create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Password Video',
            'slug' => 'password-video',
            'status' => VideoStatus::Ready,
            'privacy' => VideoPrivacy::Password,
            'password_hash' => Hash::make('secret1234'),
        ]);

        $share = VideoShare::query()->create([
            'video_id' => $video->id,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/share/' . $share->share_uuid)
            ->assertOk()
            ->assertJsonPath('data.requires_password', true)
            ->assertJsonPath('data.video.uuid', $video->uuid);

        $this->postJson('/api/v1/share/' . $share->share_uuid . '/verify-password', [
            'password' => 'secret1234',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.video.uuid', $video->uuid);
    }

    public function test_password_verification_fails_with_wrong_password(): void
    {
        $video = Video::query()->create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Wrong Password Target',
            'slug' => 'wrong-password-target',
            'status' => VideoStatus::Ready,
            'privacy' => VideoPrivacy::Password,
            'password_hash' => Hash::make('correct-password'),
        ]);

        $share = VideoShare::query()->create([
            'video_id' => $video->id,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/share/' . $share->share_uuid . '/verify-password', [
            'password' => 'wrong-password',
        ])->assertForbidden();
    }
}
