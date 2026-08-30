<?php

use App\Enums\Tier;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelTypes\DiscordChannelType;
use App\Support\ChannelTypes\EmailChannelType;
use App\Support\ChannelTypes\NtfyChannelType;
use App\Support\ChannelTypes\SlackChannelType;
use App\Support\ChannelTypes\TelegramChannelType;

test('email builds a mailto url from mail config', function () {
    config([
        'mail.mailers.smtp.host' => 'mailpit',
        'mail.mailers.smtp.port' => 1025,
        'mail.mailers.smtp.username' => null,
        'mail.mailers.smtp.password' => null,
        'mail.mailers.smtp.encryption' => null,
        'services.apprise.from_email' => 'alerts@wxnotify.test',
    ]);

    $url = (new EmailChannelType)->buildAppriseUrl(['to' => 'subscriber@example.com']);

    expect($url)->toBe('mailto://mailpit:1025/?to=subscriber%40example.com&from=alerts%40wxnotify.test&mode=insecure');
});

test('discord extracts the webhook id and token from a pasted url', function () {
    $url = (new DiscordChannelType)->buildAppriseUrl([
        'webhook_url' => 'https://discord.com/api/webhooks/123456789/abcDEF-token_123',
    ]);

    expect($url)->toBe('discord://123456789/abcDEF-token_123/');
});

test('slack extracts the three tokens from an incoming webhook url', function () {
    $url = (new SlackChannelType)->buildAppriseUrl([
        'webhook_url' => 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX',
    ]);

    expect($url)->toBe('slack://T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX/');
});

test('telegram builds a tgram url from bot token and chat id', function () {
    $url = (new TelegramChannelType)->buildAppriseUrl([
        'bot_token' => '123:ABC-token',
        'chat_id' => '-100123456',
    ]);

    expect($url)->toBe('tgram://123%3AABC-token/-100123456');
});

test('ntfy defaults to the public server when none is given', function () {
    $url = (new NtfyChannelType)->buildAppriseUrl(['topic' => 'wxnotify-a1b2c3', 'server' => null]);

    expect($url)->toBe('ntfy://wxnotify-a1b2c3');
});

test('ntfy uses a self-hosted server when one is given', function () {
    $url = (new NtfyChannelType)->buildAppriseUrl([
        'topic' => 'wxnotify-a1b2c3',
        'server' => 'https://ntfy.example.com',
    ]);

    expect($url)->toBe('ntfys://ntfy.example.com/wxnotify-a1b2c3');
});

test('a Free subscriber can add exactly one no-cost channel', function () {
    $user = User::factory()->create();

    expect($user->canAddChannel('discord'))->toBeTrue();

    NotificationChannel::factory()->for($user)->create(['channel_type' => 'discord']);

    expect($user->fresh()->canAddChannel('slack'))->toBeFalse()
        ->and($user->fresh()->canAddChannel('discord'))->toBeFalse();
});

test('email never counts against the Free no-cost slot', function () {
    $user = User::factory()->create();
    NotificationChannel::factory()->for($user)->create(['channel_type' => 'email']);

    expect($user->fresh()->canAddChannel('email'))->toBeTrue()
        ->and($user->fresh()->canAddChannel('discord'))->toBeTrue();
});

test('a Plus subscriber can add more than one no-cost channel', function () {
    $user = User::factory()->create();
    $user->forceFill(['tier_override' => Tier::Plus])->save();

    NotificationChannel::factory()->for($user)->create(['channel_type' => 'discord']);

    expect($user->fresh()->canAddChannel('slack'))->toBeTrue();
});
