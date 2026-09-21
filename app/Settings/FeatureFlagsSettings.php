<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class FeatureFlagsSettings extends Settings
{
    public bool $heads_up_enabled;

    public static function group(): string
    {
        return 'feature_flags';
    }
}
