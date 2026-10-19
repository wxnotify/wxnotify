<?php

namespace App\Onboarding\Conditions;

use App\Models\ShapefileLayer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * At least one shapefile layer exists and every layer has an active version that finished importing
 * (a version activated before its import finished swapped an empty or stale table live).
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
            && ! ShapefileLayer::query()
                ->where(fn (Builder $query) => $query
                    ->whereNull('active_version_id')
                    ->orWhereHas('activeVersion', fn (Builder $version) => $version->whereNull('diff_summary')))
                ->exists();
    }
}
