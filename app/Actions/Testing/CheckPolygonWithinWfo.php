<?php

namespace App\Actions\Testing;

use Illuminate\Support\Facades\DB;

/**
 * Non-blocking sanity check for the Geography step: does a synthetic
 * warning's polygon actually sit within the WFO the admin picked in Step 1?
 * Checks the polygon's simple average-lat/average-lon centroid against
 * cwa_boundaries via a real ST_Contains -- not a hard validation rule (a
 * real warning legitimately spans multiple CWAs), just a signal surfaced to
 * catch an obvious mismatch like the wrong point being clicked.
 */
class CheckPolygonWithinWfo
{
    /**
     * @param  array<int, array{lat: float, lon: float}>  $points
     */
    public function __invoke(array $points, string $wfo): bool
    {
        if ($points === []) {
            return false;
        }

        $centroidLat = collect($points)->avg('lat');
        $centroidLon = collect($points)->avg('lon');

        $row = DB::selectOne(
            'SELECT ST_Contains(boundary, ST_SetSRID(ST_MakePoint(?, ?), 4269)) AS contains FROM cwa_boundaries WHERE cwa = ?',
            [$centroidLon, $centroidLat, $wfo],
        );

        return $row !== null && ($row->contains === true || $row->contains === 't');
    }
}
