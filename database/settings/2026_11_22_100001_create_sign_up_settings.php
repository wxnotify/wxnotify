<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('sign_up', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('policy', 'open');
        });
    }

    public function down(): void
    {
        $this->migrator->inGroup('sign_up', function (SettingsBlueprint $blueprint): void {
            $blueprint->delete('policy');
        });
    }
};
