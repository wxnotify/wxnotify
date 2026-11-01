<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class IntegrationSettings extends Settings
{
    public bool $translation_enabled;

    public ?string $translation_endpoint;

    public bool $short_urls_enabled;

    public ?string $short_urls_endpoint;

    public bool $reverse_geocoding_enabled;

    public ?string $reverse_geocoding_endpoint;

    public bool $heartbeat_enabled;

    public ?string $heartbeat_nwws_url;

    public ?string $heartbeat_emwin_url;

    public ?string $heartbeat_emwin_http_url;

    public bool $exception_reporting_enabled;

    public bool $archival_enabled;

    public ?string $archival_provider;

    public ?string $archival_endpoint;

    public ?string $archival_bucket;

    public bool $status_page_enabled;

    public ?string $status_page_url;

    public bool $email_relay_enabled;

    public ?string $email_mailer;

    public ?string $email_from_address;

    public ?string $email_from_name;

    public bool $map_tiles_enabled;

    public ?string $map_tiles_provider;

    public ?string $map_tiles_style;

    public static function group(): string
    {
        return 'integrations';
    }
}
