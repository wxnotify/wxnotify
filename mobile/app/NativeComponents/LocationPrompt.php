<?php

namespace App\NativeComponents;

use App\Services\LocationSharing;
use App\Support\Concerns\RequiresSignIn;
use Native\Mobile\Edge\NativeComponent;

/**
 * The explainer before the OS asks for location: what is sent and why, so the system dialog is
 * not the subscriber's first sight of it. Reached from the Account row.
 */
class LocationPrompt extends NativeComponent
{
    use RequiresSignIn;

    public ?string $error = null;

    public function mount(): void
    {
        $this->redirectIfSignedOut();
    }

    public function share(): void
    {
        $this->error = null;

        $outcome = app(LocationSharing::class)->enable();

        if ($outcome === 'started') {
            $this->replace('/account');

            return;
        }

        $this->error = match ($outcome) {
            'limit' => 'Your plan\'s phone-location allowance is used up. Delete a phone location you no longer use on the dashboard, or upgrade your plan, to share this phone\'s location.',
            'offline' => 'Couldn\'t start sharing: you appear to be offline. Try again when you are back online.',
            default => 'Couldn\'t start sharing right now. Please try again.',
        };
    }

    public function notNow(): void
    {
        $this->replace('/account');
    }
}
