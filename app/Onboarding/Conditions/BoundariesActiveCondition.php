<?php

namespace App\Onboarding\Conditions;

use App\Models\ShapefileLayer;
use Illuminate\Database\Eloquent\Model;

/**
 * At least one shapefile layer exists and every layer has an active version.
 */
final class BoundariesActiveCondition extends SafeCondition
{
    public static function label(): string
    {
        return 'Boundary shapefiles are active';
    }

    protected function check(Model $subject): bool
    {
        return ShapefileLayer::query()->exists()
            && ! ShapefileLayer::query()->whereNull('active_version_id')->exists();
    }
}
