<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('tier_threshold', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('free_alerts', 3);
            $blueprint->add('plus_alerts', 5);
            $blueprint->add('pro_alerts', 10);
        });
    }

    public function down(): void
    {
        $this->migrator->inGroup('tier_threshold', function (SettingsBlueprint $blueprint): void {
            $blueprint->delete('free_alerts');
            $blueprint->delete('plus_alerts');
            $blueprint->delete('pro_alerts');
        });
    }
};
