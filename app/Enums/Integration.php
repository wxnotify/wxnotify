<?php

namespace App\Enums;

enum Integration: string
{
    case Translation = 'translation';
    case ShortUrls = 'short_urls';
    case ReverseGeocoding = 'reverse_geocoding';
    case Heartbeat = 'heartbeat';
    case ExceptionReporting = 'exception_reporting';
    case Archival = 'archival';
    case StatusPage = 'status_page';
    case EmailRelay = 'email_relay';

    /**
     * The boolean property on IntegrationSettings that switches this integration.
     */
    public function enabledProperty(): string
    {
        return "{$this->value}_enabled";
    }

    /**
     * The property holding this integration's endpoint or URL, if it has one.
     */
    public function endpointProperty(): ?string
    {
        return match ($this) {
            self::Translation => 'translation_endpoint',
            self::ShortUrls => 'short_urls_endpoint',
            self::ReverseGeocoding => 'reverse_geocoding_endpoint',
            self::Archival => 'archival_endpoint',
            self::StatusPage => 'status_page_url',
            self::ExceptionReporting, self::EmailRelay, self::Heartbeat => null,
        };
    }
}
