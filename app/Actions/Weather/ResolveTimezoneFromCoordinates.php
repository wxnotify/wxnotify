<?php

namespace App\Actions\Weather;

use App\Support\Geography\StateTimezones;
use Illuminate\Support\Facades\DB;

/**
 * Best-effort server-side fallback for LocationForm's timezone field, used
 * only when the browser-JS auto-detect (TimezoneSelect::getTimezoneFromBrowser())
 * hasn't already filled it in — e.g. JS blocked or disabled. Resolves the
 * coordinate's containing us_states polygon locally via PostGIS, same
 * ST_Contains approach as ResolveLocationZones, then maps the state to its
 * most-populous timezone via StateTimezones. Returns null for a coordinate
 * outside every tracked state/territory (e.g. open ocean).
 */
class ResolveTimezoneFromCoordinates
{
    public function __invoke(float $lat, float $lng): ?string
    {
        $row = DB::selectOne(
            'SELECT state FROM us_states WHERE ST_Contains(boundary, ST_SetSRID(ST_MakePoint(?, ?), 4269)) LIMIT 1',
            [$lng, $lat],
        );

        return $row === null ? null : StateTimezones::forState($row->state);
    }
}
