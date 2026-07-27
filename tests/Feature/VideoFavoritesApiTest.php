<?php

namespace Tests\Feature;

use App\Enums\VideoPrivacy;
use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VideoFavoritesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_favorite_and_unfavorite_video(): void
    {
        $user = User::factory()->create();

        $video = Video::query()->create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Favorite Me',
            'slug' => 'favorite-me',
            'status' => VideoStatus::Ready,
            'privacy' => VideoPrivacy::Public,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/videos/' . $video->uuid . '/favorite')
            ->assertCreated()
            ->assertJsonPath('data.is_favorite', true);

        $this->assertDatabaseHas('video_favorites', [
            'video_id' => $video->id,
            'user_id' => $user->id,
        ]);

        $this->getJson('/api/v1/favorites/videos')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.uuid', $video->uuid);

        $this->deleteJson('/api/v1/videos/' . $video->uuid . '/favorite')
            ->assertOk()
            ->assertJsonPath('data.is_favorite', false);

        $this->assertDatabaseMissing('video_favorites', [
            'video_id' => $video->id,
            'user_id' => $user->id,
        ]);
    }
}
