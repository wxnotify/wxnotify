<?php

use App\Enums\Tier;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\DiscordChannelType;
use App\Support\ChannelTypes\EmailChannelType;
use App\Support\ChannelTypes\NtfyChannelType;
use App\Support\ChannelTypes\PushoverChannelType;
use App\Support\ChannelTypes\SlackChannelType;
use App\Support\ChannelTypes\TeamsChannelType;
use App\Support\ChannelTypes\TelegramChannelType;
use Illuminate\Support\Facades\Validator;

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

test('pushover builds a pover url from user key and application token', function () {
    $url = (new PushoverChannelType)->buildAppriseUrl([
        'user_key' => 'abcdefghijklmnopqrstuvwxyz1234',
        'api_token' => 'zyxwvutsrqponmlkjihgfedcba4321',
    ]);

    expect($url)->toBe('pover://abcdefghijklmnopqrstuvwxyz1234@zyxwvutsrqponmlkjihgfedcba4321');
});

test('teams converts a power automate workflows url to an apprise workflows url', function () {
    $url = (new TeamsChannelType)->buildAppriseUrl([
        'webhook_url' => 'https://prod-12.westus.logic.azure.com:443/workflows/0123456789abcdef0123456789abcdef/triggers/manual/paths/invoke?api-version=2016-06-01&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig=SIGNATUREabc-_123',
    ]);

    expect($url)->toBe('workflows://prod-12.westus.logic.azure.com/0123456789abcdef0123456789abcdef/SIGNATUREabc-_123');
});

test('teams keeps a non-default port', function () {
    $url = (new TeamsChannelType)->buildAppriseUrl([
        'webhook_url' => 'https://prod-12.westus.logic.azure.com:8443/workflows/0123456789abcdef0123456789abcdef/triggers/manual/paths/invoke?sig=abc123',
    ]);

    expect($url)->toBe('workflows://prod-12.westus.logic.azure.com:8443/0123456789abcdef0123456789abcdef/abc123');
});

test('teams rejects retired office 365 connector urls and other hosts', function (string $url) {
    expect(TeamsChannelType::parseWorkflowUrl($url))->toBeNull();
})->with([
    'legacy connector' => 'https://contoso.webhook.office.com/webhookb2/aaaa@bbbb/IncomingWebhook/cccc/dddd',
    'outlook connector' => 'https://outlook.office.com/webhook/aaaa@bbbb/IncomingWebhook/cccc/dddd',
    'wrong host' => 'https://example.com/workflows/0123456789abcdef0123456789abcdef/triggers/manual/paths/invoke?sig=abc',
    'http' => 'http://prod-12.westus.logic.azure.com/workflows/0123456789abcdef0123456789abcdef/triggers/manual/paths/invoke?sig=abc',
    'no signature' => 'https://prod-12.westus.logic.azure.com/workflows/0123456789abcdef0123456789abcdef/triggers/manual/paths/invoke',
]);

test('the picker offers pushover and teams and they count toward the free slot', function () {
    $slugs = app(ChannelTypeRegistry::class)->forPicker()->map(fn ($row) => $row['type']->slug())->all();

    expect($slugs)->toContain('pushover')->toContain('msteams')
        ->and((new PushoverChannelType)->countsTowardFreeSlot())->toBeTrue()
        ->and((new TeamsChannelType)->countsTowardFreeSlot())->toBeTrue();
});

test('pushover validates the key and token shape', function () {
    $fields = collect((new PushoverChannelType)->formSchema())->keyBy(fn ($f) => $f->getName());

    expect($fields->keys()->all())->toBe(['user_key', 'api_token']);

    foreach (['user_key', 'api_token'] as $name) {
        $validator = Validator::make([$name => 'too-short'], [$name => $fields[$name]->getValidationRules()]);
        expect($validator->fails())->toBeTrue();

        $validator = Validator::make([$name => 'abcdefghijklmnopqrstuvwxyz1234'], [$name => $fields[$name]->getValidationRules()]);
        expect($validator->passes())->toBeTrue();
    }
});
