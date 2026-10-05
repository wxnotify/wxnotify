<?php

namespace Tests\Fakes;

use NativePHP\Geolocation\PendingSharedLocationWatch;

/**
 * A real shared-location builder that reports the settings it was started with to the fake
 * Geolocation instead of calling the native layer, so tests can see the interval and distance
 * the app chained onto it.
 */
class RecordingSharedWatch extends PendingSharedLocationWatch
{
    public function __construct(private readonly RecordingGeolocation $recorder)
    {
        parent::__construct();
    }

    public function start(): bool
    {
        if ($this->started) {
            return false;
        }

        $this->recorder->started[] = [
            'id' => $this->getId(),
            'intervalMs' => $this->intervalMs,
            'minDistance' => $this->minDistanceMeters,
        ];

        return parent::start();
    }
}
