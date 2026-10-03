<?php

namespace App\Support;

use App\Models\AlertRule;
use Illuminate\Support\Collection;

/**
 * "A, B, C, D, E (and 2 more)": the first five alert names, escaped for HTML
 * (Filament renders notification bodies as HTML), shared by the deletion
 * guards that tell a subscriber which alerts are in the way.
 */
final class AlertNameSummary
{
    private const NAMED_ALERTS = 5;

    /**
     * @param  Collection<int, AlertRule>  $alerts
     */
    public static function of(Collection $alerts): string
    {
        $names = $alerts->map(fn (AlertRule $alert): string => e($alert->displayName()))->values();
        $listed = $names->take(self::NAMED_ALERTS)->implode(', ');
        $rest = $names->count() - self::NAMED_ALERTS;

        return $rest > 0 ? "{$listed} (and {$rest} more)" : $listed;
    }
}
