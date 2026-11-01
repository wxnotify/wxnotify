<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * WxNotify's own production credentials. Every property is encrypted by the
 * settings package, both in the settings table and in the settings cache.
 * Never fill this class into form state or log its values.
 */
class SecretSettings extends Settings
{
    public ?string $stripe_secret_key;

    public ?string $stripe_webhook_secret;

    public ?string $paddle_api_key;

    public ?string $paddle_webhook_secret;

    public ?string $firebase_service_account;

    public ?string $traccar_polling_token;

    /**
     * The separate map tiler's key. Not a Secret case: it has no .env
     * fallback, is edited in the Integrations tab's Map tiles section, and is
     * read only through App\Support\MapTiles.
     */
    public ?string $map_tiles_api_key;

    public static function group(): string
    {
        return 'secrets';
    }

    /**
     * @return array<int, string>
     */
    public static function encrypted(): array
    {
        return [
            'stripe_secret_key',
            'stripe_webhook_secret',
            'paddle_api_key',
            'paddle_webhook_secret',
            'firebase_service_account',
            'traccar_polling_token',
            'map_tiles_api_key',
        ];
    }
}
