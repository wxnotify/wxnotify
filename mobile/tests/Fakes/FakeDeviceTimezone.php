<?php

namespace Tests\Fakes;

use App\Services\DeviceTimezone;

/**
 * The suite's default phone-timezone service: answers a scripted raw id (null by default; the real validation still applies) instead of
 * calling the badge-count plugin, and counts the reads. DeviceTimezoneTest builds the real service.
 */
class FakeDeviceTimezone extends DeviceTimezone
{
    public int $reads = 0;

    public function __construct(public ?string $timezone = null) {}

    protected function read(): ?string
    {
        $this->reads++;

        return $this->timezone;
    }
}
