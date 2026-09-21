<?php

namespace App\Models\Concerns;

use App\Support\MaintenanceModeGuard;

/**
 * Blocks subscriber writes while maintenance mode is on.
 *
 * The guard hooks the Eloquent creating/updating/deleting events only, so
 * Builder::update()/delete(), saveQuietly() and database cascades bypass it.
 */
trait BlockedDuringMaintenance
{
    protected static function bootBlockedDuringMaintenance(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            static::{$event}(fn () => app(MaintenanceModeGuard::class)->assertWritable());
        }
    }
}
