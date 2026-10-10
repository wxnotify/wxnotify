<?php

namespace App\NativeComponents;

use App\Support\Concerns\RequiresSignIn;
use Native\Mobile\Edge\NativeComponent;

/**
 * The Settings tab: the way into alert rules, locations, channels and the account. Each page is pushed over
 * this one, so the back arrow returns here.
 */
class SettingsScreen extends NativeComponent
{
    use RequiresSignIn;

    public function mount(): void
    {
        $this->redirectIfSignedOut();
    }

    public function openRules(): void
    {
        $this->navigate('/rules');
    }

    public function openLocations(): void
    {
        $this->navigate('/locations');
    }

    public function openChannels(): void
    {
        $this->navigate('/channels');
    }

    public function openAccount(): void
    {
        $this->navigate('/account');
    }
}
