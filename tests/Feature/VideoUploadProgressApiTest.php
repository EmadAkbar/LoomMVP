<?php

namespace Tests\Feature;

use App\Enums\VideoPrivacy;
use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VideoUploadProgressApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_report_upload_progress(): void
    {
        $user = User::factory()->create();
        $video = $this->makeVideo($user);

        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/videos/{$video->uuid}/upload-progress", [
            'processing_percentage' => 42,
        ])
            ->assertOk()
            ->assertJsonPath('data.processing_percentage', 42)
            ->assertJsonPath('data.status', 'uploading');

        $this->assertSame(42, $video->refresh()->processing_percentage);
    }

    /**
     * The whole point of a dedicated endpoint: a client that has finished sending
     * bytes still cannot declare its own video playable.
     */
    public function test_progress_endpoint_cannot_mark_a_video_ready(): void
    {
        $user = User::factory()->create();
        $video = $this->makeVideo($user);

        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/videos/{$video->uuid}/upload-progress", [
            'processing_percentage' => 100,
            // Ignored: not an accepted field.
            'status' => 'ready',
        ])->assertOk()->assertJsonPath('data.status', 'uploading');

        $video->refresh();
        $this->assertSame(100, $video->processing_percentage);
        $this->assertSame(VideoStatus::Uploading, $video->status);
    }

    public function test_progress_is_validated(): void
    {
        $user = User::factory()->create();
        $video = $this->makeVideo($user);

        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/videos/{$video->uuid}/upload-progress", [
            'processing_percentage' => 140,
        ])->assertStatus(422);

        $this->patchJson("/api/v1/videos/{$video->uuid}/upload-progress", [])
            ->assertStatus(422);
    }

    public function test_another_user_cannot_report_progress(): void
    {
        $owner = User::factory()->create();
        $video = $this->makeVideo($owner);

        Sanctum::actingAs(User::factory()->create());

        $this->patchJson("/api/v1/videos/{$video->uuid}/upload-progress", [
            'processing_percentage' => 50,
        ])->assertStatus(403);

        $this->assertSame(0, $video->refresh()->processing_percentage);
    }

    public function test_progress_requires_authentication(): void
    {
        $video = $this->makeVideo(User::factory()->create());

        $this->patchJson("/api/v1/videos/{$video->uuid}/upload-progress", [
            'processing_percentage' => 50,
        ])->assertStatus(401);
    }

    private function makeVideo(User $user): Video
    {
        return Video::query()->create([
            'user_id' => $user->id,
            'title' => 'Upload Progress Video',
            'slug' => 'upload-progress-video-'.uniqid(),
            'status' => VideoStatus::Uploading,
            'privacy' => VideoPrivacy::Private,
            'processing_percentage' => 0,
        ]);
    }
}
