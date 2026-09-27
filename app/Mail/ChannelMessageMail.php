<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ChannelMessageMail extends Mailable
{
    public function __construct(
        public readonly ?string $messageTitle,
        public readonly string $messageBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: filled($this->messageTitle) ? $this->messageTitle : (string) config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.channel-message',
            text: 'mail.channel-message-text',
        );
    }
}
