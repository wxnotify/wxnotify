<?php

use App\Models\User;
use App\Notifications\PrefixedResetPassword;
use App\Notifications\PrefixedVerifyEmail;
use App\Notifications\PrefixedVerifyEmailChange;
use App\Settings\GeneralSettings;
use App\Support\MessagePrefix;

function setMessagePrefix(bool $enabled, string $prefix = ''): void
{
    $settings = app(GeneralSettings::class);
    $settings->message_prefix_enabled = $enabled;
    $settings->message_prefix = $prefix;
    $settings->save();
}

test('general settings default the prefix to disabled with an empty string', function () {
    $settings = app(GeneralSettings::class);

    expect($settings->message_prefix_enabled)->toBeFalse()
        ->and($settings->message_prefix)->toBe('');
});

test('apply() is a no-op when the setting is disabled, even with text configured', function () {
    setMessagePrefix(false, '[STAGING]');

    expect(MessagePrefix::apply('Severe Thunderstorm Warning'))->toBe('Severe Thunderstorm Warning');
});

test('apply() is a no-op when enabled but the prefix text is blank', function () {
    setMessagePrefix(true, '');

    expect(MessagePrefix::apply('Severe Thunderstorm Warning'))->toBe('Severe Thunderstorm Warning');
});

test('apply() prepends the trimmed prefix and a single space when enabled with text', function () {
    setMessagePrefix(true, '  [STAGING]  ');

    expect(MessagePrefix::apply('Severe Thunderstorm Warning'))->toBe('[STAGING] Severe Thunderstorm Warning');
});

test('apply() fails safe and returns the original text when settings cannot be read', function () {
    app()->bind(GeneralSettings::class, function (): never {
        throw new RuntimeException('settings table unavailable');
    });

    expect(MessagePrefix::apply('Severe Thunderstorm Warning'))->toBe('Severe Thunderstorm Warning');
});

test('PrefixedVerifyEmail prefixes the subject when enabled and leaves it alone when disabled', function () {
    $user = User::factory()->make();

    setMessagePrefix(false);
    $notification = new PrefixedVerifyEmail;
    $notification->url = 'https://example.com/verify';
    expect($notification->toMail($user)->subject)->toBe('Verify your email address');

    setMessagePrefix(true, '[STAGING]');
    $notification = new PrefixedVerifyEmail;
    $notification->url = 'https://example.com/verify';
    expect($notification->toMail($user)->subject)->toBe('[STAGING] Verify your email address');
});

test('PrefixedResetPassword prefixes the subject when enabled', function () {
    $user = User::factory()->make();
    setMessagePrefix(true, '[STAGING]');

    $notification = new PrefixedResetPassword('token123');
    $notification->url = 'https://example.com/reset';

    expect($notification->toMail($user)->subject)->toBe('[STAGING] Reset your password');
});

test('PrefixedVerifyEmailChange prefixes the subject when enabled', function () {
    $user = User::factory()->make();
    setMessagePrefix(true, '[STAGING]');

    $notification = new PrefixedVerifyEmailChange;
    $notification->url = 'https://example.com/verify-change';

    expect($notification->toMail($user)->subject)->toBe('[STAGING] Verify your email address');
});
