<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public bool $maintenance_mode;

    public ?string $maintenance_message;

    public static function group(): string
    {
        return 'general';
    }
}
