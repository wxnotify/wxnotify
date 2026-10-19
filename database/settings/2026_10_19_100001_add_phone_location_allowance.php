<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('tier_threshold', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('free_phone_locations', 1);
            $blueprint->add('plus_phone_locations', 2);
        });
    }

    public function down(): void
    {
        $this->migrator->inGroup('tier_threshold', function (SettingsBlueprint $blueprint): void {
            $blueprint->delete('free_phone_locations');
            $blueprint->delete('plus_phone_locations');
        });
    }
};
