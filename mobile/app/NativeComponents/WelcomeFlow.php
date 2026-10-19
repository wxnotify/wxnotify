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

    /**
     * Why the last screen mentions location sharing when the Location step did not run:
     * 'used_up' when the server said the plan has no free phone slot (or refused the start with its
     * limit), 'unknown' when sharing is off but the slot lookup failed, timed out or had no answer.
     *
     * @var 'used_up'|'unknown'|null
     */
    public ?string $slotNotice = null;

    /** Why location sharing did not start, shown on the last screen. */
    public ?string $note = null;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $this->replay = $this->param('replay') === true;
        $welcome = app(Welcome::class);
        $this->steps = $welcome->steps();
        $this->slotNotice = $this->slotNoticeFor($welcome->phoneSlot());

        if (! $this->replay) {
            app(WelcomeState::class)->markSeen();
        }
    }

    /**
     * The step on screen. The permission actions check it first: a second tap queued while the first
     * call ran still reaches its method after the screen has moved on, and must do nothing.
     */
    public function step(): string
    {
        return $this->steps[$this->index] ?? 'done';
    }

    /**
     * The view passes the index the tap was made on, so a second tap queued while the first ran
     * (and still bound to the same callback) does not skip a screen.
     */
    public function next(?int $from = null): void
    {
        if ($from !== null && $from !== $this->index) {
            return;
        }

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
        if ($this->step() !== 'push') {
            return;
        }

        try {
            app(PushRegistration::class)->enable();
        } catch (Throwable) {
            // The OS dialog could not be raised; Account offers push again later.
        }

        $this->next();
    }

    public function notNowPush(): void
    {
        if ($this->step() !== 'push') {
            return;
        }

        app(PushRegistration::class)->decline();

        $this->next();
    }

    public function shareLocation(): void
    {
        if ($this->step() !== 'location') {
            return;
        }

        $outcome = app(LocationSharing::class)->enable();

        match ($outcome) {
            'started' => null,
            'limit' => $this->slotNotice = 'used_up',
            'offline' => $this->note = 'Couldn\'t start sharing: you appear to be offline. You can turn it on later from Account.',
            default => $this->note = 'Couldn\'t start sharing right now. You can turn it on later from Account.',
        };

        $this->next();
    }

    public function notNowLocation(): void
    {
        if ($this->step() !== 'location') {
            return;
        }

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
     * Sharing off and the Location step left out means the slot lookup said no or could not tell.
     * Sharing on or waiting (or unreadable) needs no notice.
     *
     * @return 'used_up'|'unknown'|null
     */
    private function slotNoticeFor(?bool $phoneSlot): ?string
    {
        if (in_array('location', $this->steps, true)) {
            return null;
        }

        try {
            if (app(LocationSharing::class)->state()['status'] !== 'off') {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return $phoneSlot === false ? 'used_up' : 'unknown';
    }
}
