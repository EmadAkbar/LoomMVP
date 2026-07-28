<?php

use App\Models\Video;
use App\Models\VideoComment;
use App\Models\VideoView;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/email-previews/{template}', function (string $template) {
    $video = new Video([
        'title' => 'How to share a project update',
    ]);
    $video->uuid = 'e3d629b2-4e6f-481d-b9d7-4f79275a8e11';

    if ($template === 'video-viewed') {
        $view = new VideoView([
            'viewer_ip' => '203.0.113.42',
            'viewer_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
            'watch_seconds' => 128,
        ]);
        $view->created_at = now()->subMinutes(12);

        return view('emails.video-viewed', compact('video', 'view'));
    }

    $comment = new VideoComment([
        'user_id' => 24,
        'guest_name' => 'Ayesha Khan',
        'comment' => "This walkthrough is really helpful. Could you also cover how to invite the rest of the team?",
        'timestamp_seconds' => 42,
    ]);
    $comment->created_at = now()->subMinutes(7);

    return view('emails.video-comment-notification', compact('video', 'comment'));
})->whereIn('template', ['video-viewed', 'video-comment-notification'])->name('email-preview');
