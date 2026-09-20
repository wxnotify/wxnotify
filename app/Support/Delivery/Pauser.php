<?php

namespace App\Support\Delivery;

/**
 * The between-attempt wait a transport's own retry loop takes.
 *
 * It exists only so the wait is a container binding rather than a bare
 * sleep(): a test binds a recording no-op and the retry tests run instantly
 * while still proving how long production would have waited.
 */
class Pauser
{
    public function pause(int $seconds): void
    {
        sleep($seconds);
    }
}
