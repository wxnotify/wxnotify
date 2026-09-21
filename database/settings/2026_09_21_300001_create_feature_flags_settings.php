<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('feature_flags', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('heads_up_enabled', true);
        });
    }
};
