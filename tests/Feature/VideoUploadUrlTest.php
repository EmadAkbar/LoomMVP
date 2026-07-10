<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Cloudflare\CloudflareStreamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class VideoUploadUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_request_upload_url(): void
    {
        $cloudflareMock = Mockery::mock(CloudflareStreamService::class);
        $cloudflareMock->shouldReceive('createDirectUploadUrl')
            ->once()
            ->andReturn([
                'uid' => 'mock-upload-uid-123',
                'uploadURL' => 'https://upload.example.com/mock-upload-uid-123',
            ]);

        $this->app->instance(CloudflareStreamService::class, $cloudflareMock);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/videos/upload-url', [
            'title' => 'Test Video',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.upload_uid', 'mock-upload-uid-123')
            ->assertJsonPath('data.upload_url', 'https://upload.example.com/mock-upload-uid-123');
    }
}
