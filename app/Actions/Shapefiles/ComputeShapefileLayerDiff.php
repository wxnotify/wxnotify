<?php

namespace App\Actions\Shapefiles;

use App\Models\ShapefileLayer;
use App\Models\ShapefileRecordOverride;
use App\Support\Shapefiles\ShapefileLayerDefinition;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ComputeShapefileLayerDiff
{
    /**
     * @return array{added: int, removed: int, changed: int, override_conflicts: array<int, string>}
     */
    public function __invoke(ShapefileLayer $layer, ShapefileLayerDefinition $definition): array
    {
        $columns = $this->comparableColumns($definition);

        $liveRows = $this->keyedRows(DB::table($definition->liveTable), $definition, $columns);
        $stagingRows = $this->keyedRows(DB::table($definition->stagingTable), $definition, $columns);

        $addedKeys = array_diff(array_keys($stagingRows), array_keys($liveRows));
        $removedKeys = array_diff(array_keys($liveRows), array_keys($stagingRows));
        $commonKeys = array_intersect(array_keys($stagingRows), array_keys($liveRows));

        $changedKeys = array_values(array_filter(
            $commonKeys,
            fn (string $key): bool => $liveRows[$key] !== $stagingRows[$key],
        ));

        $overrideConflicts = [];

        foreach (ShapefileRecordOverride::where('shapefile_layer_id', $layer->id)->get() as $override) {
            $stagingRow = $stagingRows[$override->record_key] ?? null;

            if ($stagingRow !== null
                && array_key_exists($override->field, $stagingRow)
                && (string) $stagingRow[$override->field] !== (string) $override->value) {
                $overrideConflicts[] = $override->record_key;
            }
        }

        return [
            'added' => count($addedKeys),
            'removed' => count($removedKeys),
            'changed' => count($changedKeys),
            'override_conflicts' => array_values(array_unique($overrideConflicts)),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function comparableColumns(ShapefileLayerDefinition $definition): array
    {
        $columns = array_values($definition->attributeMap);

        if ($definition->zoneType !== null) {
            $columns[] = 'zone_type';
        }

        return array_values(array_unique($columns));
    }

    /**
     * @param  array<int, string>  $columns
     * @return array<string, array<string, mixed>>
     */
    private function keyedRows(Builder $query, ShapefileLayerDefinition $definition, array $columns): array
    {
        $keyed = [];

        foreach ($query->select($columns)->get() as $row) {
            $record = (array) $row;
            $keyed[$definition->serializeNaturalKey($record)] = $record;
        }

        return $keyed;
    }
}
