<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;
use Wxnotify\BadgeCount\Facades\BadgeCount;

/**
 * The phone's own IANA timezone id through the local badge-count plugin (NativePHP's Device::getInfo()
 * has none), or null when it cannot be read or is not a zone PHP knows. Never throws. Off a device
 * the call goes through the dev bridge fallback, so tests bind a fake (`Tests\Fakes\FakeDeviceTimezone`).
 */
class DeviceTimezone
{
    public function id(): ?string
    {
        try {
            $id = $this->read();
        } catch (Throwable $e) {
            Log::info('Device timezone read failed: '.$e::class);

            return null;
        }

        return is_string($id) && in_array($id, timezone_identifiers_list(), true) ? $id : null;
    }

    protected function read(): ?string
    {
        return BadgeCount::timezone();
    }
}
