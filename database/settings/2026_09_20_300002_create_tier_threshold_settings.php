<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('tier_threshold', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('free_locations', 1);
            $blueprint->add('plus_locations', 5);
            $blueprint->add('free_no_cost_channels', 1);
            $blueprint->add('free_traccar_servers', 0);
            $blueprint->add('plus_traccar_servers', 1);
            $blueprint->add('pro_traccar_servers', 3);
            $blueprint->add('free_traccar_devices_per_server', 0);
            $blueprint->add('plus_traccar_devices_per_server', 5);
            $blueprint->add('pro_traccar_devices_per_server', 10);
            $blueprint->add('lightning_min_radius_miles', 10);
        });
    }
};
