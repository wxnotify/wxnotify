<?php

namespace App\Mail;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The notice that a subscriber's account is scheduled for deletion, with the date and how to cancel. Sent to the
 * address on file, so a hijacked session asking for deletion is visible to the real owner.
 */
class AccountDeletionScheduledMail extends Mailable
{
    public function __construct(public readonly User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your WxNotify account is scheduled for deletion');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.account-deletion-scheduled',
            with: [
                'name' => $this->user->name,
                'date' => $this->user->deletionDate()->format('F j, Y'),
                'profileUrl' => Filament::getPanel('dashboard')->getProfileUrl().'#delete-account',
            ],
        );
    }
}
