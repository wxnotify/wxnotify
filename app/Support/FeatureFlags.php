<?php

namespace App\Support;

use App\Enums\Feature;
use App\Settings\FeatureFlagsSettings;

/**
 * Dashboard-wide kill switches for optional subscriber-facing features. A
 * flag decides whether a feature runs at all; who may use it is a separate
 * tier gate. Turning a flag off must hide the feature and stop it firing
 * without clearing anything a subscriber configured.
 */
class FeatureFlags
{
    public static function enabled(Feature $feature): bool
    {
        return (bool) app(FeatureFlagsSettings::class)->{$feature->settingsProperty()};
    }

    public static function disabled(Feature $feature): bool
    {
        return ! self::enabled($feature);
    }
}
