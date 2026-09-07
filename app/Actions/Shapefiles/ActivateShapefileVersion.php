<?php

namespace App\Actions\Shapefiles;

use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayerVersion;
use App\Support\Shapefiles\ShapefileLayerDefinition;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use Illuminate\Support\Facades\DB;

class ActivateShapefileVersion
{
    public function __construct(private ApplyRecordOverrides $applyRecordOverrides = new ApplyRecordOverrides) {}

    public function __invoke(ShapefileLayerVersion $version): void
    {
        $layer = $version->shapefileLayer;
        $definition = ShapefileLayerDefinitions::forKey($layer->key);

        DB::transaction(function () use ($layer, $version, $definition): void {
            $previousVersion = $layer->activeVersion;

            $this->swap($definition);

            $previousVersion?->update(['status' => ShapefileVersionStatus::Superseded]);

            $version->update(['status' => ShapefileVersionStatus::Active, 'activated_at' => now()]);
            $layer->update(['active_version_id' => $version->id]);
        });

        ($this->applyRecordOverrides)($layer, $definition);
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
