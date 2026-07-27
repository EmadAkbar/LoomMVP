<?php

namespace App\Mail;

use App\Models\Video;
use App\Models\VideoComment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VideoCommentNotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Video $video,
        public readonly VideoComment $comment,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Comment on Video: ' . $this->video->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.video-comment-notification',
            with: [
                'video' => $this->video,
                'comment' => $this->comment,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
