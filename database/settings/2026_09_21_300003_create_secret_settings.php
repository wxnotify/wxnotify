<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('secrets', function (SettingsBlueprint $blueprint): void {
            $blueprint->addEncrypted('stripe_secret_key', null);
            $blueprint->addEncrypted('stripe_webhook_secret', null);
            $blueprint->addEncrypted('paddle_api_key', null);
            $blueprint->addEncrypted('paddle_webhook_secret', null);
            $blueprint->addEncrypted('firebase_service_account', null);
            $blueprint->addEncrypted('traccar_polling_token', null);
        });
    }
};
