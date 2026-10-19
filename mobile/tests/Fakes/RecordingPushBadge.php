<?php

namespace Tests\Fakes;

use App\Services\PushBadge;

/**
 * The suite's default badge service: records each clear instead of calling the plugin and the server,
 * so a screen test's request counts are only about that screen. PushBadgeTest and
 * PushBadgeScreensTest put the real service back.
 */
class RecordingPushBadge extends PushBadge
{
    /** @var array<int, bool> the argument of each clear, in order */
    public array $clears = [];

    public function __construct() {}

    public function clear(bool $server = true): void
    {
        $this->clears[] = $server;
    }
}
