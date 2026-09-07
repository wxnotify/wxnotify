<?php

namespace App\Actions\Shapefiles;

use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayerVersion;

class DismissShapefileVersion
{
    public function __invoke(ShapefileLayerVersion $version): void
    {
        $version->update(['status' => ShapefileVersionStatus::Rejected]);
    }
}
