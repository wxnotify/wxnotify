<?php

namespace Tests\Fakes;

use DateTimeInterface;
use NativePHP\Geolocation\Geolocation;
use NativePHP\Geolocation\PendingSharedLocationWatch;

/**
 * Records what the app asks the native location layer to do. The builders it returns are real
 * but inert off a device (no nativephp_call), so nothing starts.
 */
class RecordingGeolocation extends Geolocation
{
    /** @var list<array<string, mixed>> */
    public array $shared = [];

    /** @var list<array{id: string, clearBuffer: bool}> */
    public array $stopped = [];

    /** @var array<string, mixed>|null */
    public ?array $status = null;

    public function sharePosition(
        string $url,
        ?string $token = null,
        int $interval = 60,
        DateTimeInterface|string|null $expiresAt = null,
        bool $stopWatchOnExpiry = false,
        bool $fineAccuracy = true,
    ): PendingSharedLocationWatch {
        $this->shared[] = compact('url', 'token', 'interval', 'expiresAt', 'fineAccuracy');

        return parent::sharePosition($url, $token, $interval, $expiresAt, $stopWatchOnExpiry, $fineAccuracy);
    }

    public function stopBackgroundWatch(string $id, bool $clearBuffer = false): void
    {
        $this->stopped[] = ['id' => $id, 'clearBuffer' => $clearBuffer];
        $this->status = null;
    }

    public function backgroundWatchStatus(): ?array
    {
        return $this->status;
    }
}
