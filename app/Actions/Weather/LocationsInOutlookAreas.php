<?php

namespace App\Actions\Weather;

use App\Models\Location;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Which of the given locations lie inside, or on the edge of, one GeoJSON outlook geometry.
 *
 * One statement per geometry: the points go in as a VALUES list and PostGIS tests each against the
 * geometry (a MultiPolygon with holes is handled by ST_Intersects itself). A location without a fix
 * is skipped, and a geometry PostGIS cannot read matches nothing instead of failing the report.
 */
class LocationsInOutlookAreas
{
    /**
     * @param  array<string, mixed>  $geometry
     * @param  Collection<int, Location>  $locations
     * @return list<int>
     */
    public function __invoke(array $geometry, Collection $locations): array
    {
        $points = $locations->filter(fn ($location): bool => $location->hasFix())->values();

        if ($points->isEmpty() || ! isset($geometry['type'], $geometry['coordinates'])) {
            return [];
        }

        $rows = implode(', ', array_fill(0, $points->count(), '(?::bigint, ?::float8, ?::float8)'));
        $bindings = [json_encode($geometry)];

        foreach ($points as $location) {
            array_push($bindings, $location->id, (float) $location->lng, (float) $location->lat);
        }

        // The geometry is bound once and parsed in a CTE; the transaction is a savepoint so a
        // parse error cannot poison an outer transaction.
        try {
            $found = DB::transaction(fn (): array => DB::select(
                'WITH shape AS (SELECT ST_SetSRID(ST_GeomFromGeoJSON(?::text), 4326) AS geom) '
                ."SELECT p.id FROM (VALUES {$rows}) AS p(id, lng, lat) CROSS JOIN shape "
                .'WHERE ST_Intersects(shape.geom, ST_SetSRID(ST_MakePoint(p.lng, p.lat), 4326))',
                $bindings,
            ));
        } catch (Throwable) {
            return [];
        }

        return array_map(fn (object $row): int => (int) $row->id, $found);
    }
}
