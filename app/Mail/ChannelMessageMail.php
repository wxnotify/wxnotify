<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ChannelMessageMail extends Mailable
{
    public readonly string $messageBody;

    public function __construct(
        public readonly ?string $messageTitle,
        string $messageBody,
        public readonly ?string $viewUrl = null,
    ) {
        $messageBody = str_replace(["\r\r\n", "\r\n", "\r"], "\n", $messageBody);

        $this->messageBody = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $messageBody) ?? $messageBody;
    }

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
