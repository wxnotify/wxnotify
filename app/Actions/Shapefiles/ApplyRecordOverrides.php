<?php

namespace App\Actions\Shapefiles;

use App\Models\ShapefileLayer;
use App\Support\Shapefiles\ShapefileLayerDefinition;
use Illuminate\Support\Facades\DB;

class ApplyRecordOverrides
{
    public function __invoke(ShapefileLayer $layer, ShapefileLayerDefinition $definition): int
    {
        $applied = 0;

        foreach ($layer->recordOverrides as $override) {
            $keyParts = explode('|', $override->record_key);
            $query = DB::table($definition->liveTable);

            foreach ($definition->naturalKeyColumns as $index => $column) {
                $query->where($column, $keyParts[$index] ?? '');
            }

            $applied += $query->update([$override->field => $override->value]);
        }

        return $applied;
    }
}
