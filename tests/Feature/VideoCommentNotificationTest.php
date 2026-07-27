<?php

namespace Tests\Feature;

use App\Enums\VideoPrivacy;
use App\Enums\VideoStatus;
use App\Mail\VideoCommentNotificationMail;
use App\Models\User;
use App\Models\Video;
use Illuminate\Mail\Mailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VideoCommentNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_comment_on_video_sends_notification_email_to_video_owner(): void
    {
        Mail::fake();

        $owner = User::factory()->create([
            'email' => 'owner@example.com',
        ]);

        $commenter = User::factory()->create();

        $video = Video::query()->create([
            'user_id' => $owner->id,
            'title' => 'Commented Video',
            'slug' => 'commented-video',
            'status' => VideoStatus::Ready,
            'privacy' => VideoPrivacy::Public,
        ]);

        Sanctum::actingAs($commenter);

        $this->postJson('/api/v1/videos/' . $video->uuid . '/comments', [
            'comment' => 'Great walkthrough!',
            'timestamp_seconds' => 42,
            'guest_name' => 'Viewer',
        ])->assertCreated();

        Mail::assertSent(VideoCommentNotificationMail::class, function (Mailable $mail) use ($owner) {
            return $mail->hasTo($owner->email);
        });
    }
}
