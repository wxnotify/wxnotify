<?php

namespace Tests\Fakes;

use DateTimeInterface;
use Native\Mobile\PendingGeolocation;
use NativePHP\Geolocation\Geolocation;
use NativePHP\Geolocation\PendingSharedLocationWatch;
use Throwable;

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

    /** @var list<array{id: string, intervalMs: int, minDistance: float|int}> */
    public array $started = [];

    /** @var list<array{id: string, upTo: int}> */
    public array $trimmed = [];

    /** @var array<string, mixed>|null */
    public ?array $status = null;

    /** Thrown by sharePosition(), to simulate a native failure while starting the beacon. */
    public ?Throwable $throwOnStart = null;

    /** Thrown by stopBackgroundWatch(), to simulate a native failure while stopping it. */
    public ?Throwable $throwOnStop = null;

    /** @var list<array{fine: bool}> */
    public array $positionRequests = [];

    public int $permissionRequests = 0;

    public function getCurrentPosition(bool $fineAccuracy = false): PendingGeolocation
    {
        $this->positionRequests[] = ['fine' => $fineAccuracy];

        return parent::getCurrentPosition($fineAccuracy);
    }

    public function requestPermissions(): PendingGeolocation
    {
        $this->permissionRequests++;

        return parent::requestPermissions();
    }

    public function watchPosition(bool $fineAccuracy = false): PendingSharedLocationWatch
    {
        return (new RecordingSharedWatch($this))->fineAccuracy($fineAccuracy);
    }

    public function sharePosition(
        string $url,
        ?string $token = null,
        int $interval = 60,
        DateTimeInterface|string|null $expiresAt = null,
        bool $stopWatchOnExpiry = false,
        bool $fineAccuracy = true,
    ): PendingSharedLocationWatch {
        if ($this->throwOnStart !== null) {
            throw $this->throwOnStart;
        }

        $this->shared[] = compact('url', 'token', 'interval', 'expiresAt', 'fineAccuracy');

        return parent::sharePosition($url, $token, $interval, $expiresAt, $stopWatchOnExpiry, $fineAccuracy);
    }

    public function stopBackgroundWatch(string $id, bool $clearBuffer = false): void
    {
        if ($this->throwOnStop !== null) {
            throw $this->throwOnStop;
        }

        $this->stopped[] = ['id' => $id, 'clearBuffer' => $clearBuffer];
        $this->status = null;
    }

    public function trimWatch(string $id, int $upTo): void
    {
        $this->trimmed[] = ['id' => $id, 'upTo' => $upTo];
    }

    public function backgroundWatchStatus(): ?array
    {
        return $this->status;
    }
}
