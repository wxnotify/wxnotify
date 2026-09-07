<?php

namespace App\Actions\Shapefiles;

use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use App\Support\Shapefiles\ShapefileLayerDefinition;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use Illuminate\Support\Facades\DB;

class RollbackShapefileLayer
{
    public function __invoke(ShapefileLayer $layer): void
    {
        $currentVersion = $layer->activeVersion;

        $restoreVersion = ShapefileLayerVersion::where('shapefile_layer_id', $layer->id)
            ->where('status', ShapefileVersionStatus::Superseded)
            ->orderByDesc('activated_at')
            ->first();

        if ($restoreVersion === null) {
            return;
        }

        $definition = ShapefileLayerDefinitions::forKey($layer->key);

        DB::transaction(function () use ($layer, $currentVersion, $restoreVersion, $definition): void {
            $this->swap($definition);

            $currentVersion?->update(['status' => ShapefileVersionStatus::Superseded]);
            $restoreVersion->update(['status' => ShapefileVersionStatus::Active, 'activated_at' => now()]);
            $layer->update(['active_version_id' => $restoreVersion->id]);
        });
    }

    private function swap(ShapefileLayerDefinition $definition): void
    {
        $live = $definition->liveTable;
        $staging = $definition->stagingTable;
        $previous = "{$live}_swap_previous";

        DB::statement("ALTER TABLE {$live} RENAME TO {$previous}");
        DB::statement("ALTER TABLE {$staging} RENAME TO {$live}");
        DB::statement("ALTER TABLE {$previous} RENAME TO {$staging}");
    }
}
