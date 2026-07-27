<?php

namespace App\Mail;

use App\Models\Video;
use App\Models\VideoView;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UniqueVideoViewAlertMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Video $video,
        public readonly VideoView $videoView,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Unique Video View Alert: ' . $this->video->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.video-viewed',
            with: [
                'video' => $this->video,
                'view' => $this->videoView,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
