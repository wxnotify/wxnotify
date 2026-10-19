<?php

namespace Tests\Fakes;

use App\Services\PushBadge;

/**
 * The suite's default badge service: does nothing instead of calling the plugin and the server,
 * so a screen test's request counts are only about that screen. PushBadgeTest and
 * PushBadgeScreensTest put the real service back.
 */
class NullPushBadge extends PushBadge
{
    /** Empty on purpose: it skips the parent's promoted API, state and token dependencies. */
    public function __construct() {}

    public function clear(): void {}

    public function retryPendingReset(): void {}
}
