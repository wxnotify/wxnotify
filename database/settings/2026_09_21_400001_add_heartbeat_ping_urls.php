<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('integrations', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('heartbeat_nwws_url', null);
            $blueprint->add('heartbeat_emwin_url', null);
            $blueprint->add('heartbeat_emwin_http_url', null);
            $blueprint->delete('heartbeat_endpoint');
        });
    }

    public function down(): void
    {
        $this->migrator->inGroup('integrations', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('heartbeat_endpoint', null);
            $blueprint->delete('heartbeat_nwws_url');
            $blueprint->delete('heartbeat_emwin_url');
            $blueprint->delete('heartbeat_emwin_http_url');
        });
    }
};
