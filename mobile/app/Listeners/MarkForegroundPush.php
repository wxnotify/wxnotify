<?php

namespace App\Listeners;

use App\Events\ForegroundPushReceived;
use App\Support\PushState;

/**
 * A plain listener: the event is dispatched in the plugin's ephemeral PHP runtime, where no screen is
 * mounted, so it only records the push in the on-device cache. The next tick of a mounted tab screen
 * (see ClearsPushBadge) reads the flag and clears the badge.
 */
class MarkForegroundPush
{
    public function __construct(private PushState $state) {}

    public function handle(ForegroundPushReceived $event): void
    {
        $this->state->markForegroundPush();
    }
}
