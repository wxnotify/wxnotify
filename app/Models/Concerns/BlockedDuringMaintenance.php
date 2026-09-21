<?php

namespace App\Models\Concerns;

use App\Support\MaintenanceModeGuard;

trait BlockedDuringMaintenance
{
    protected static function bootBlockedDuringMaintenance(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            static::{$event}(fn () => app(MaintenanceModeGuard::class)->assertWritable());
        }
    }
}
