<?php

namespace App\Onboarding\Conditions;

use App\Support\Setup\ReferenceDataStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * The product catalog, tips and shapefile layers are all installed.
 */
final class ReferenceDataInstalledCondition extends SafeCondition
{
    public static function label(): string
    {
        return 'Reference data is installed';
    }

    protected function check(Model $subject): bool
    {
        return ReferenceDataStatus::allInstalled();
    }
}
