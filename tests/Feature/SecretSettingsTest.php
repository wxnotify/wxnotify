<?php

use App\Enums\Secret;
use App\Settings\SecretSettings;
use App\Support\Secrets;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\SettingsMapper;

const FAKE_STRIPE_KEY = 'sk_test_FAKE_1234';

function storeSecret(string $property, ?string $value): void
{
    $settings = app(SecretSettings::class);
    $settings->{$property} = $value;
    $settings->save();
}

test('every slot defaults to null and every slot is flagged encrypted', function () {
    $settings = app(SecretSettings::class);

    foreach (Secret::cases() as $secret) {
        expect($settings->{$secret->settingsProperty()})->toBeNull()
            ->and(SecretSettings::encrypted())->toContain($secret->settingsProperty());
    }

    // Plus map_tiles_api_key, which is encrypted here but is not a Secret case (no .env fallback).
    expect(SecretSettings::encrypted())->toHaveCount(count(Secret::cases()) + 1)
        ->toContain('map_tiles_api_key');
});

test('the enum maps each secret to its property and fallback config key', function (Secret $secret, string $property, string $configKey) {
    expect($secret->settingsProperty())->toBe($property)
        ->and($secret->configKey())->toBe($configKey)
        ->and($secret->label())->not->toBeEmpty();
})->with([
    [Secret::StripeSecretKey, 'stripe_secret_key', 'services.stripe.secret'],
    [Secret::StripeWebhookSecret, 'stripe_webhook_secret', 'services.stripe.webhook_secret'],
    [Secret::PaddleApiKey, 'paddle_api_key', 'services.paddle.api_key'],
    [Secret::PaddleWebhookSecret, 'paddle_webhook_secret', 'services.paddle.webhook_secret'],
    [Secret::FirebaseServiceAccount, 'firebase_service_account', 'services.firebase.service_account'],
    [Secret::TraccarPollingToken, 'traccar_polling_token', 'services.traccar.polling_token'],
]);

test('every fallback config key exists in config/services.php', function () {
    foreach (Secret::cases() as $secret) {
        expect(Arr::has(config()->all(), $secret->configKey()))->toBeTrue();
    }
});

test('a stored secret is encrypted in the settings table and decrypts to the value', function () {
    storeSecret('stripe_secret_key', FAKE_STRIPE_KEY);

    $payload = DB::table('settings')->where('group', 'secrets')->where('name', 'stripe_secret_key')->value('payload');

    expect($payload)->not->toContain(FAKE_STRIPE_KEY)
        ->and(decrypt(json_decode($payload)))->toBe(FAKE_STRIPE_KEY);
});

test('the settings cache entry does not contain the plaintext', function () {
    storeSecret('stripe_secret_key', FAKE_STRIPE_KEY);
    Cache::flush();
    app()->forgetInstance(SecretSettings::class);
    app()->forgetInstance(SettingsMapper::class);

    expect(app(SecretSettings::class)->stripe_secret_key)->toBe(FAKE_STRIPE_KEY);

    $cached = Cache::get('settings.'.SecretSettings::cacheKey());

    expect($cached)->toBeString()->not->toContain(FAKE_STRIPE_KEY);
});

test('the resolver prefers the stored value over the .env fallback', function () {
    config(['services.stripe.secret' => 'env-value']);
    storeSecret('stripe_secret_key', FAKE_STRIPE_KEY);

    expect(Secrets::get(Secret::StripeSecretKey))->toBe(FAKE_STRIPE_KEY)
        ->and(Secrets::isStored(Secret::StripeSecretKey))->toBeTrue();
});

test('the resolver falls back to the .env config when nothing is stored', function () {
    config(['services.stripe.secret' => 'env-value']);

    expect(Secrets::get(Secret::StripeSecretKey))->toBe('env-value')
        ->and(Secrets::isStored(Secret::StripeSecretKey))->toBeFalse();
});

test('the resolver returns null when neither is set, and a blank stored value counts as unset', function () {
    config(['services.paddle.api_key' => null]);
    expect(Secrets::get(Secret::PaddleApiKey))->toBeNull();

    storeSecret('paddle_api_key', '');
    expect(Secrets::isStored(Secret::PaddleApiKey))->toBeFalse();
});

test('the resolver falls back to config and never throws when the settings rows are missing', function () {
    config(['services.stripe.secret' => 'env-value']);
    DB::table('settings')->where('group', 'secrets')->delete();
    Cache::flush();
    app()->forgetInstance(SecretSettings::class);

    expect(Secrets::get(Secret::StripeSecretKey))->toBe('env-value')
        ->and(Secrets::isStored(Secret::StripeSecretKey))->toBeFalse();
});

test('a non-string config fallback yields null', function () {
    config(['services.stripe.secret' => ['not', 'a string']]);

    expect(Secrets::get(Secret::StripeSecretKey))->toBeNull();
});
