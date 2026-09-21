<?php

use App\Enums\Integration;
use App\Settings\IntegrationSettings;
use App\Support\Integrations;

function setIntegrationSetting(string $property, mixed $value): void
{
    $settings = app(IntegrationSettings::class);
    $settings->{$property} = $value;
    $settings->save();
}

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

test('endpoints, provider and mail overrides default to null', function () {
    $settings = app(IntegrationSettings::class);

    expect($settings->translation_endpoint)->toBeNull()
        ->and($settings->short_urls_endpoint)->toBeNull()
        ->and($settings->heartbeat_endpoint)->toBeNull()
        ->and($settings->archival_provider)->toBeNull()
        ->and($settings->archival_endpoint)->toBeNull()
        ->and($settings->archival_bucket)->toBeNull()
        ->and($settings->status_page_url)->toBeNull()
        ->and($settings->email_mailer)->toBeNull()
        ->and($settings->email_from_address)->toBeNull()
        ->and($settings->email_from_name)->toBeNull();
});

test('enabled() follows the saved setting for every integration', function (Integration $integration) {
    expect(Integrations::enabled($integration))->toBe($integration === Integration::EmailRelay);

    setIntegrationSetting($integration->enabledProperty(), $integration !== Integration::EmailRelay);

    expect(Integrations::enabled($integration))->toBe($integration !== Integration::EmailRelay);
})->with(Integration::cases());

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
