<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class SignUpSettings extends Settings
{
    public string $policy;

    public static function group(): string
    {
        return 'sign_up';
    }
}
