<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('integrations', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('map_tiles_enabled', false);
            $blueprint->add('map_tiles_provider', null);
            $blueprint->add('map_tiles_style', null);
        });

        $this->migrator->inGroup('secrets', function (SettingsBlueprint $blueprint): void {
            $blueprint->addEncrypted('map_tiles_api_key', null);
        });
    }

    public function down(): void
    {
        $this->migrator->inGroup('integrations', function (SettingsBlueprint $blueprint): void {
            $blueprint->delete('map_tiles_enabled');
            $blueprint->delete('map_tiles_provider');
            $blueprint->delete('map_tiles_style');
        });

        $this->migrator->inGroup('secrets', function (SettingsBlueprint $blueprint): void {
            $blueprint->delete('map_tiles_api_key');
        });
    }
};
