<?php

namespace App\NativeComponents;

use App\Services\LocationSharing;
use App\Services\PushRegistration;
use App\Services\Welcome;
use App\Support\Concerns\HandlesPushToken;
use App\Support\Concerns\RequiresSignIn;
use App\Support\WelcomeState;
use Native\Mobile\Edge\NativeComponent;
use Throwable;

/**
 * The welcome flow: what WxNotify is, what it offers, then push and location explained before the
 * OS asks, and a last "you're set" screen. The steps come from Welcome::steps(), so a permission that
 * is already on, refused by the OS or (for location) without a free phone slot is never shown.
 * Skip leaves at once and asks for nothing. The first run (/welcome) marks the flow seen as it
 * starts, so quitting halfway never brings it back; the replay (/welcome/tour, whose route carries
 * a `replay` default) never touches that flag and closes back to Settings like the back arrow.
 * Push enrolment can start here, so the screen carries the OS push-token handler.
 */
class WelcomeFlow extends NativeComponent
{
    use HandlesPushToken;
    use RequiresSignIn;

    /** @var list<'welcome'|'offer'|'push'|'location'|'done'> */
    public array $steps = [];

    public int $index = 0;

    /** Set from the /welcome/tour route's default. */
    public bool $replay = false;

    /** The location step was left out (or refused) because the plan has no free phone slot. */
    public bool $noSlot = false;

    /** Why location sharing did not start, shown on the last screen. */
    public ?string $note = null;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $this->replay = $this->param('replay') === true;
        $this->steps = app(Welcome::class)->steps();
        $this->noSlot = ! in_array('location', $this->steps, true) && $this->sharingIsOff();

        if (! $this->replay) {
            app(WelcomeState::class)->markSeen();
        }
    }

    public function step(): string
    {
        return $this->steps[$this->index] ?? 'done';
    }

    public function next(): void
    {
        $this->index = min($this->index + 1, max(count($this->steps) - 1, 0));
    }

    public function skip(): void
    {
        $this->close();
    }

    public function finish(): void
    {
        $this->close();
    }

    public function allowPush(): void
    {
        try {
            app(PushRegistration::class)->enable();
        } catch (Throwable) {
            // The OS dialog could not be raised; Account offers push again later.
        }

        $this->next();
    }

    public function notNowPush(): void
    {
        app(PushRegistration::class)->decline();

        $this->next();
    }

    public function shareLocation(): void
    {
        $outcome = app(LocationSharing::class)->enable();

        match ($outcome) {
            'started' => null,
            'limit' => $this->noSlot = true,
            'offline' => $this->note = 'Couldn\'t start sharing: you appear to be offline. You can turn it on later from Account.',
            default => $this->note = 'Couldn\'t start sharing right now. You can turn it on later from Account.',
        };

        $this->next();
    }

    public function notNowLocation(): void
    {
        $this->next();
    }

    /** The first run leaves for Alerts; a replay closes back to Settings, as its back arrow does. */
    private function close(): void
    {
        if ($this->replay) {
            $this->back();

            return;
        }

        $this->replace('/');
    }

    /**
     * Sharing is off on this phone, so a missing location step means the slot lookup said no (or
     * failed, which Welcome counts as no slot). Read locally: no second server call.
     */
    private function sharingIsOff(): bool
    {
        return app(LocationSharing::class)->state()['status'] === 'off';
    }
}
