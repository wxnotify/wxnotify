<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('integrations', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('translation_enabled', false);
            $blueprint->add('translation_endpoint', null);
            $blueprint->add('short_urls_enabled', false);
            $blueprint->add('short_urls_endpoint', null);
            $blueprint->add('heartbeat_enabled', false);
            $blueprint->add('heartbeat_endpoint', null);
            $blueprint->add('exception_reporting_enabled', false);
            $blueprint->add('archival_enabled', false);
            $blueprint->add('archival_provider', null);
            $blueprint->add('archival_endpoint', null);
            $blueprint->add('archival_bucket', null);
            $blueprint->add('status_page_enabled', false);
            $blueprint->add('status_page_url', null);
            $blueprint->add('email_relay_enabled', true);
            $blueprint->add('email_mailer', null);
            $blueprint->add('email_from_address', null);
            $blueprint->add('email_from_name', null);
        });
    }
};
