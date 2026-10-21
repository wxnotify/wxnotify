<?php

use App\Enums\ChannelTransport;
use App\Enums\Tier;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\Contracts\ChannelType;
use App\Support\ChannelTypes\DiscordChannelType;
use App\Support\ChannelTypes\EmailChannelType;
use App\Support\ChannelTypes\NtfyChannelType;
use App\Support\ChannelTypes\PushoverChannelType;
use App\Support\ChannelTypes\SlackChannelType;
use App\Support\ChannelTypes\TeamsChannelType;
use App\Support\ChannelTypes\TelegramChannelType;
use App\Support\ChannelTypes\WebhookChannelType;
use App\Support\ChannelTypes\WebPushChannelType;
use Illuminate\Support\Facades\Validator;

test('email keeps its address in config and sends through the mail transport', function () {
    $type = new EmailChannelType;

    expect($type->transport())->toBe(ChannelTransport::Mail)
        ->and($type->buildDestination(['to' => 'subscriber@example.com']))->toBe(['config' => ['to' => 'subscriber@example.com']])
        ->and($type->supportsInlineCreate())->toBeTrue()
        ->and($type->isAvailable())->toBeTrue()
        ->and($type->countsTowardFreeSlot())->toBeFalse();
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

    expect($fields->keys()->all())->toBe(['user_key', 'api_token', 'devices', 'priority', 'sound', 'retry_interval', 'expire']);

    foreach (['user_key', 'api_token'] as $name) {
        $validator = Validator::make([$name => 'too-short'], [$name => $fields[$name]->getValidationRules()]);
        expect($validator->fails())->toBeTrue();

        $validator = Validator::make([$name => 'abcdefghijklmnopqrstuvwxyz1234'], [$name => $fields[$name]->getValidationRules()]);
        expect($validator->passes())->toBeTrue();
    }
});

test('every registered channel type implements the transport contract', function () {
    $sharedData = ['topic' => 'x', 'to' => 'a@b.co', 'bot_token' => '1:a', 'chat_id' => '1', 'user_key' => str_repeat('a', 30), 'api_token' => str_repeat('b', 30)];
    $perType = [
        'discord' => ['webhook_url' => 'https://discord.com/api/webhooks/1/a'],
        'slack' => ['webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXXX'],
        'msteams' => ['webhook_url' => 'https://prod-00.westus.logic.azure.com:443/workflows/'.str_repeat('a', 32).'/triggers/manual/paths/invoke?sig=abc_DEF-123'],
    ];

    foreach (['ntfy', 'discord', 'slack', 'telegram', 'pushover', 'msteams'] as $slug) {
        $type = app(ChannelTypeRegistry::class)->find($slug);

        expect($type->transport())->toBe(ChannelTransport::Apprise)
            ->and($type->supportsInlineCreate())->toBeTrue()
            ->and($type->isAvailable())->toBeTrue()
            ->and($type->buildDestination([...$sharedData, ...($perType[$slug] ?? [])]))->toHaveKey('apprise_url')
            ->not->toHaveKey('config');
    }
});

$pushoverBase = ['user_key' => 'abcdefghijklmnopqrstuvwxyz1234', 'api_token' => 'zyxwvutsrqponmlkjihgfedcba4321'];

test('pushover adds devices, priority and sound to the pover url only when they differ from the defaults', function () use ($pushoverBase) {
    $type = new PushoverChannelType;
    $base = 'pover://abcdefghijklmnopqrstuvwxyz1234@zyxwvutsrqponmlkjihgfedcba4321';

    expect($type->buildAppriseUrl($pushoverBase + ['devices' => '', 'priority' => '0', 'sound' => 'pushover']))->toBe($base)
        ->and($type->buildAppriseUrl($pushoverBase + ['devices' => "iphone, pixel_7\nipad-2 iphone", 'priority' => '-1', 'sound' => 'siren']))
        ->toBe($base.'/iphone/pixel_7/ipad-2?priority=-1&sound=siren');
});

test('pushover only sends the emergency retry interval and expiry for emergency priority', function () use ($pushoverBase) {
    $type = new PushoverChannelType;
    $base = 'pover://abcdefghijklmnopqrstuvwxyz1234@zyxwvutsrqponmlkjihgfedcba4321';

    expect($type->buildAppriseUrl($pushoverBase + ['priority' => '2', 'retry_interval' => 900, 'expire' => 3600]))->toBe($base.'?priority=2')
        ->and($type->buildAppriseUrl($pushoverBase + ['priority' => '2', 'retry_interval' => 60, 'expire' => 7200]))
        ->toBe($base.'?priority=2&interval=60&expire=7200')
        ->and($type->buildAppriseUrl($pushoverBase + ['priority' => '1', 'retry_interval' => 60, 'expire' => 7200]))
        ->toBe($base.'?priority=1');
});

test('email\'s destination summary shows the full address -- it is not a secret', function () {
    $record = NotificationChannel::factory()->email('subscriber@example.com')->make();

    expect((new EmailChannelType)->displaySummary($record))->toBe('subscriber@example.com');
});

test('telegram\'s destination summary shows the chat id in full but never the bot token', function () {
    $record = NotificationChannel::factory()->make(['apprise_url' => 'tgram://123%3AABC-token/-100123456']);

    expect((new TelegramChannelType)->displaySummary($record))
        ->toBe('-100123456')
        ->not->toContain('ABC-token');
});

test('every channel type captions its destination value with a fixed, secret-free caption', function () {
    $captions = app(ChannelTypeRegistry::class)->all()
        ->mapWithKeys(fn ($type): array => [$type->slug() => $type->destinationLabel()])
        ->all();

    expect($captions)->toEqual([
        'discord' => 'Discord webhook',
        'email' => 'Email address',
        'mobile_push' => 'Mobile device',
        'msteams' => 'Teams workflow',
        'ntfy' => 'ntfy server and topic',
        'pushover' => 'Pushover user key',
        'slack' => 'Slack webhook',
        'telegram' => 'Telegram chat ID',
        'twilio_sms' => 'Text number',
        'bulkvs_sms' => 'Text number',
        'webhook' => 'Webhook host',
        'webpush' => 'Push service',
    ]);

    foreach ($captions as $caption) {
        expect($caption)->not->toContain('://')->not->toContain('•');
    }
});

test('discord\'s destination summary masks the webhook token to its last 4 characters', function () {
    $record = NotificationChannel::factory()->make(['apprise_url' => 'discord://123456789/abcDEF-token_123/']);

    expect((new DiscordChannelType)->displaySummary($record))
        ->toBe('••••_123')
        ->not->toContain('abcDEF');
});

test('slack\'s destination summary masks the final token to its last 4 characters', function () {
    $record = NotificationChannel::factory()->make(['apprise_url' => 'slack://T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXWXYZ/']);

    expect((new SlackChannelType)->displaySummary($record))
        ->toBe('••••WXYZ')
        ->not->toContain('T00000000');
});

test('teams\'s destination summary masks the signature to its last 4 characters', function () {
    $record = NotificationChannel::factory()->make(['apprise_url' => 'workflows://prod-12.westus.logic.azure.com/0123456789abcdef0123456789abcdef/SIGNATUREabc-_123']);

    expect((new TeamsChannelType)->displaySummary($record))
        ->toBe('••••_123')
        ->not->toContain('SIGNATURE');
});

test('pushover\'s destination summary masks the user key to its last 4 characters and never the api token', function () {
    $record = NotificationChannel::factory()->make(['apprise_url' => 'pover://abcdefghijklmnopqrstuvwxyz1234@zyxwvutsrqponmlkjihgfedcba4321']);

    expect((new PushoverChannelType)->displaySummary($record))
        ->toBe('••••1234')
        ->not->toContain('zyxwvutsrqponmlkjihgfedcba4321');
});

test('ntfy\'s destination summary masks the topic since it is functionally a shared secret', function () {
    $public = NotificationChannel::factory()->make(['apprise_url' => 'ntfy://wxnotify-a1b2c3']);
    $selfHosted = NotificationChannel::factory()->make(['apprise_url' => 'ntfys://ntfy.example.com/wxnotify-a1b2c3']);

    expect((new NtfyChannelType)->displaySummary($public))->toBe('••••b2c3')
        ->and((new NtfyChannelType)->displaySummary($selfHosted))->toBe('ntfy.example.com ••••b2c3');
});

test('webhook\'s destination summary shows only the host, never the path or the signing secret', function () {
    $record = NotificationChannel::factory()->webhook('https://hooks.example.com/wx/secret-path?token=abc', 's3cret')->make();

    expect((new WebhookChannelType)->displaySummary($record))
        ->toBe('hooks.example.com')
        ->not->toContain('secret-path')
        ->not->toContain('token')
        ->not->toContain('s3cret');
});

test('web push\'s destination summary shows only the push service host, never the auth keys', function () {
    $record = NotificationChannel::factory()->webPush()->make();

    expect((new WebPushChannelType)->displaySummary($record))
        ->toBe('fcm.googleapis.com')
        ->not->toContain(str_repeat('B', 87))
        ->not->toContain(str_repeat('a', 22));
});

test('the registry resolves displaySummary through find() for every registered type', function () {
    $record = NotificationChannel::factory()->email()->make();

    expect(app(ChannelTypeRegistry::class)->find('email')?->displaySummary($record))->toBe('subscriber@example.com');
});

test('pushover validates device names, priority, sound and emergency bounds', function () {
    $fields = collect((new PushoverChannelType)->formSchema())->keyBy(fn ($f) => $f->getName());
    $passes = fn (string $name, mixed $value): bool => Validator::make([$name => $value], [$name => $fields[$name]->getValidationRules()])->passes();

    expect($passes('devices', "iphone, pixel_7\nipad-2"))->toBeTrue()
        ->and($passes('devices', 'bad device!'))->toBeFalse()
        ->and($passes('devices', str_repeat('a', 26)))->toBeFalse()
        ->and($passes('retry_interval', 29))->toBeFalse()
        ->and($passes('retry_interval', 30))->toBeTrue()
        ->and($passes('retry_interval', 10801))->toBeFalse()
        ->and($passes('expire', 10800))->toBeTrue()
        ->and($passes('expire', 10801))->toBeFalse()
        ->and($passes('expire', 1.5))->toBeFalse()
        ->and(array_map('strval', array_keys($fields['priority']->getOptions())))->toBe(['-2', '-1', '0', '1', '2'])
        ->and($fields['priority']->getDefaultState())->toBe('0')
        ->and($fields['sound']->getDefaultState())->toBe('pushover')
        ->and(array_keys($fields['sound']->getOptions()))->toHaveCount(23)
        ->and($fields['retry_interval']->getDefaultState())->toBe(900)
        ->and($fields['expire']->getDefaultState())->toBe(3600);
});

test('only the email channel type supports rich content', function () {
    $rich = app(ChannelTypeRegistry::class)->all()
        ->filter(fn (ChannelType $type): bool => $type->supportsRichContent())
        ->map(fn (ChannelType $type): string => $type->slug())
        ->values()
        ->all();

    expect($rich)->toBe(['email']);
});
