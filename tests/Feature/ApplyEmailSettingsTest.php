<?php

use App\Providers\AppServiceProvider;
use App\Settings\IntegrationSettings;
use App\Support\ApplyEmailSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Health\Health;

function setEmailSettings(array $values): void
{
    $settings = app(IntegrationSettings::class);

    foreach ($values as $property => $value) {
        $settings->{$property} = $value;
    }

    $settings->save();
}

function removeIntegrationSettingsRows(): void
{
    DB::table('settings')->where('group', 'integrations')->delete();
    Cache::flush();
    app()->forgetInstance(IntegrationSettings::class);
}

test('the defaults leave the .env mail configuration untouched', function () {
    $default = config('mail.default');
    $from = config('mail.from');

    app(ApplyEmailSettings::class)();

    expect(config('mail.default'))->toBe($default)
        ->and(config('mail.from'))->toBe($from);
});

test('a configured mailer replaces the default mailer', function () {
    setEmailSettings(['email_mailer' => 'smtp']);

    app(ApplyEmailSettings::class)();

    expect(config('mail.default'))->toBe('smtp');
});

test('an unknown mailer is ignored', function () {
    $default = config('mail.default');
    setEmailSettings(['email_mailer' => 'bogus']);

    app(ApplyEmailSettings::class)();

    expect(config('mail.default'))->toBe($default);
});

test('a dotted mailer name that points inside a mailer is ignored', function () {
    $default = config('mail.default');
    setEmailSettings(['email_mailer' => 'smtp.host']);

    app(ApplyEmailSettings::class)();

    expect(config('mail.default'))->toBe($default);
});

test('turning relaying off falls back to the log mailer even when a mailer is set', function () {
    setEmailSettings(['email_relay_enabled' => false, 'email_mailer' => 'smtp']);

    app(ApplyEmailSettings::class)();

    expect(config('mail.default'))->toBe('log');
});

test('the from address and name are overridden independently', function () {
    $name = config('mail.from.name');
    setEmailSettings(['email_from_address' => 'alerts@example.test']);

    app(ApplyEmailSettings::class)();

    expect(config('mail.from.address'))->toBe('alerts@example.test')
        ->and(config('mail.from.name'))->toBe($name);

    setEmailSettings(['email_from_name' => 'WxNotify Alerts']);

    app(ApplyEmailSettings::class)();

    expect(config('mail.from.name'))->toBe('WxNotify Alerts');
});

test('missing settings rows leave the .env configuration untouched and throw nothing', function () {
    $default = config('mail.default');
    $from = config('mail.from');
    removeIntegrationSettingsRows();

    app(ApplyEmailSettings::class)();

    expect(config('mail.default'))->toBe($default)
        ->and(config('mail.from'))->toBe($from);
});

test('a failing settings read leaves the .env configuration untouched and throws nothing', function () {
    $default = config('mail.default');
    app()->bind(IntegrationSettings::class, fn () => throw new RuntimeException('settings store down'));

    app(ApplyEmailSettings::class)();

    expect(config('mail.default'))->toBe($default);
});

test('the app applies the email settings when it boots', function () {
    setEmailSettings(['email_mailer' => 'smtp']);

    // Re-running the provider's boot is enough to prove the wiring; re-registering its
    // observers and listeners for the rest of this one test is harmless. Spatie Health
    // appends checks and rejects duplicate names, so the checks boot already registered
    // are cleared first.
    app(Health::class)->clearChecks();
    $this->app->getProvider(AppServiceProvider::class)->boot();

    expect(config('mail.default'))->toBe('smtp');
});

test('config caching commands leave the .env mail configuration untouched', function (string $command) {
    $default = config('mail.default');
    $from = config('mail.from');
    setEmailSettings([
        'email_relay_enabled' => false,
        'email_mailer' => 'smtp',
        'email_from_address' => 'alerts@example.test',
    ]);

    $originalArgv = $_SERVER['argv'] ?? null;
    $_SERVER['argv'] = ['artisan', $command];

    try {
        app(ApplyEmailSettings::class)();
    } finally {
        $_SERVER['argv'] = $originalArgv;
    }

    expect(config('mail.default'))->toBe($default)
        ->and(config('mail.from'))->toBe($from);
})->with(['config:cache', 'optimize']);

test('other console commands still apply the email settings', function () {
    setEmailSettings(['email_relay_enabled' => false]);

    $originalArgv = $_SERVER['argv'] ?? null;
    $_SERVER['argv'] = ['artisan', 'queue:work'];

    try {
        app(ApplyEmailSettings::class)();
    } finally {
        $_SERVER['argv'] = $originalArgv;
    }

    expect(config('mail.default'))->toBe('log');
});

test('a stored excluded mailer is ignored', function (string $mailer) {
    $default = config('mail.default');
    setEmailSettings(['email_mailer' => $mailer]);

    app(ApplyEmailSettings::class)();

    expect(config('mail.default'))->toBe($default);
})->with(['array', 'log']);
