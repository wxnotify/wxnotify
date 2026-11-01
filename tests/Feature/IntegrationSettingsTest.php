<?php

use App\Enums\Integration;
use App\Enums\PipelineFeed;
use App\Settings\IntegrationSettings;
use App\Support\Integrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

test('integrations without a backend default to off and email relaying defaults to on', function () {
    $settings = app(IntegrationSettings::class);

    expect($settings->translation_enabled)->toBeFalse()
        ->and($settings->short_urls_enabled)->toBeFalse()
        ->and($settings->heartbeat_enabled)->toBeFalse()
        ->and($settings->exception_reporting_enabled)->toBeFalse()
        ->and($settings->archival_enabled)->toBeFalse()
        ->and($settings->status_page_enabled)->toBeFalse()
        ->and($settings->email_relay_enabled)->toBeTrue();
});

test('reverse geocoding defaults to on with no endpoint override', function () {
    $settings = app(IntegrationSettings::class);

    expect($settings->reverse_geocoding_enabled)->toBeTrue()->and($settings->reverse_geocoding_endpoint)->toBeNull();
});

test('endpoints, provider and mail overrides default to null', function () {
    $settings = app(IntegrationSettings::class);

    expect($settings->translation_endpoint)->toBeNull()
        ->and($settings->short_urls_endpoint)->toBeNull()
        ->and($settings->heartbeat_nwws_url)->toBeNull()->and($settings->heartbeat_emwin_url)->toBeNull()->and($settings->heartbeat_emwin_http_url)->toBeNull()
        ->and($settings->archival_provider)->toBeNull()
        ->and($settings->archival_endpoint)->toBeNull()
        ->and($settings->archival_bucket)->toBeNull()
        ->and($settings->status_page_url)->toBeNull()
        ->and($settings->email_mailer)->toBeNull()
        ->and($settings->email_from_address)->toBeNull()
        ->and($settings->email_from_name)->toBeNull();
});

test('enabled() follows the saved setting for every integration', function (Integration $integration) {
    $onByDefault = in_array($integration, [Integration::EmailRelay, Integration::ReverseGeocoding], true);

    expect(Integrations::enabled($integration))->toBe($onByDefault);

    setIntegrationSetting($integration->enabledProperty(), ! $onByDefault);

    expect(Integrations::enabled($integration))->toBe(! $onByDefault);
})->with(Integration::cases());

test('each integration maps to its expected properties', function (Integration $integration, string $enabled, ?string $endpoint) {
    expect($integration->enabledProperty())->toBe($enabled)
        ->and($integration->endpointProperty())->toBe($endpoint);
})->with([
    'translation' => [Integration::Translation, 'translation_enabled', 'translation_endpoint'],
    'short urls' => [Integration::ShortUrls, 'short_urls_enabled', 'short_urls_endpoint'],
    'reverse geocoding' => [Integration::ReverseGeocoding, 'reverse_geocoding_enabled', 'reverse_geocoding_endpoint'],
    'heartbeat' => [Integration::Heartbeat, 'heartbeat_enabled', null],
    'exception reporting' => [Integration::ExceptionReporting, 'exception_reporting_enabled', null],
    'archival' => [Integration::Archival, 'archival_enabled', 'archival_endpoint'],
    'status page' => [Integration::StatusPage, 'status_page_enabled', 'status_page_url'],
    'email relay' => [Integration::EmailRelay, 'email_relay_enabled', null],
    'map tiles' => [Integration::MapTiles, 'map_tiles_enabled', null],
]);

test('endpoint() returns the stored short urls endpoint', function () {
    setIntegrationSetting('short_urls_endpoint', 'https://s.example.test');

    expect(Integrations::endpoint(Integration::ShortUrls))->toBe('https://s.example.test')
        ->and(Integrations::endpoint(Integration::Heartbeat))->toBeNull();
});

test('endpoint() returns the stored endpoint, and null when blank or not applicable', function () {
    expect(Integrations::endpoint(Integration::Translation))->toBeNull();

    setIntegrationSetting('translation_endpoint', 'https://translate.example.test');
    setIntegrationSetting('status_page_url', 'https://status.example.test');
    setIntegrationSetting('archival_endpoint', '');

    expect(Integrations::endpoint(Integration::Translation))->toBe('https://translate.example.test')
        ->and(Integrations::endpoint(Integration::StatusPage))->toBe('https://status.example.test')
        ->and(Integrations::endpoint(Integration::Archival))->toBeNull()
        ->and(Integrations::endpoint(Integration::ExceptionReporting))->toBeNull()
        ->and(Integrations::endpoint(Integration::EmailRelay))->toBeNull();
});

test('every integration maps to real properties on the settings class', function () {
    $properties = array_keys(app(IntegrationSettings::class)->toArray());

    foreach (Integration::cases() as $integration) {
        expect($properties)->toContain($integration->enabledProperty());

        if ($integration->endpointProperty() !== null) {
            expect($properties)->toContain($integration->endpointProperty());
        }
    }
});

test('each feed maps to its own heartbeat url property', function () {
    expect(PipelineFeed::Nwws->heartbeatUrlProperty())->toBe('heartbeat_nwws_url')
        ->and(PipelineFeed::Emwin->heartbeatUrlProperty())->toBe('heartbeat_emwin_url')
        ->and(PipelineFeed::EmwinHttp->heartbeatUrlProperty())->toBe('heartbeat_emwin_http_url');
});

test('heartbeatUrl returns each feed url only while heartbeat is enabled', function () {
    setIntegrationSetting('heartbeat_nwws_url', 'https://hc.example.test/ping/nwws');
    setIntegrationSetting('heartbeat_emwin_url', 'https://hc.example.test/ping/emwin');
    setIntegrationSetting('heartbeat_emwin_http_url', 'https://hc.example.test/ping/http');

    expect(Integrations::heartbeatUrl(PipelineFeed::Nwws))->toBeNull();

    setIntegrationSetting('heartbeat_enabled', true);

    expect(Integrations::heartbeatUrl(PipelineFeed::Nwws))->toBe('https://hc.example.test/ping/nwws')
        ->and(Integrations::heartbeatUrl(PipelineFeed::Emwin))->toBe('https://hc.example.test/ping/emwin')
        ->and(Integrations::heartbeatUrl(PipelineFeed::EmwinHttp))->toBe('https://hc.example.test/ping/http');
});

test('heartbeatUrl is null for a blank url', function () {
    setIntegrationSetting('heartbeat_enabled', true);
    setIntegrationSetting('heartbeat_emwin_url', '');

    expect(Integrations::heartbeatUrl(PipelineFeed::Emwin))->toBeNull()
        ->and(Integrations::heartbeatUrl(PipelineFeed::Nwws))->toBeNull();
});

test('heartbeatUrl fails open when the integration settings cannot be read', function () {
    DB::table('settings')->where('group', 'integrations')->delete();
    Cache::flush();
    app()->forgetInstance(IntegrationSettings::class);

    expect(Integrations::heartbeatUrl(PipelineFeed::Nwws))->toBeNull();
});
