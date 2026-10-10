<?php

use App\Models\User;
use App\Support\ChannelTypes\ChannelSetupField;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use Illuminate\Support\Facades\Validator;

/**
 * Whether the type's app descriptors accept the input, validated the way the API validates it.
 *
 * @param  array<string, mixed>  $input
 */
function setupFieldsAccept(string $slug, array $input): bool
{
    $fields = app(ChannelTypeRegistry::class)->find($slug)->appSetupFields(User::factory()->make());
    $rules = [];

    foreach ($fields as $field) {
        $rules[$field->key] = [$field->required ? 'required' : 'nullable', 'string', ...$field->rules];
    }

    return ! Validator::make($input, $rules)->fails();
}

test('exactly six types can be created in the app', function () {
    $user = User::factory()->make();
    $registry = app(ChannelTypeRegistry::class);

    expect($registry->creatableInApp($user)->map->slug()->all())
        ->toEqualCanonicalizing(['email', 'ntfy', 'discord', 'slack', 'telegram', 'msteams']);

    foreach (['pushover', 'webhook', 'webpush', 'mobile_push'] as $slug) {
        expect($registry->find($slug)->appSetupFields($user))->toBeNull();
    }
});

test('each type accepts a valid setup and refuses an invalid one', function (string $slug, array $valid, array $invalid) {
    foreach ($valid as $input) {
        expect(setupFieldsAccept($slug, $input))->toBeTrue(json_encode($input));
    }

    foreach ($invalid as $input) {
        expect(setupFieldsAccept($slug, $input))->toBeFalse(json_encode($input));
    }
})->with([
    'email' => ['email', [['to' => 'pat@example.com']], [['to' => 'not-an-email'], ['to' => '']]],
    'ntfy' => ['ntfy', [['topic' => 'wx-abc_123'], ['topic' => 'wx', 'server' => 'https://ntfy.example.com']], [['topic' => 'has space'], ['topic' => ''], ['topic' => 'wx', 'server' => 'notaurl']]],
    'discord' => ['discord', [['webhook_url' => 'https://discord.com/api/webhooks/123456789/abcDEF-token_123']], [['webhook_url' => 'https://example.com/hook'], ['webhook_url' => 'http://discord.com/api/webhooks/1/a'], ['webhook_url' => '']]],
    'slack' => ['slack', [['webhook_url' => 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX']], [['webhook_url' => 'https://hooks.slack.com/services/T0/B0'], ['webhook_url' => 'https://example.com/services/a/b/c']]],
    'telegram' => ['telegram', [['bot_token' => '123456:ABC-def', 'chat_id' => '-1001234']], [['bot_token' => '123456:ABC-def'], ['chat_id' => '-1001234']]],
    'teams' => ['msteams', [['webhook_url' => 'https://prod-12.westus.logic.azure.com:443/workflows/0123456789abcdef0123456789abcdef/triggers/manual/paths/invoke?api-version=2016-06-01&sig=abc_DEF-123']], [['webhook_url' => 'https://example.com/workflows/0123456789abcdef0123456789abcdef/triggers?sig=abc'], ['webhook_url' => 'https://outlook.office.com/webhook/abc']]],
]);

test('the email address defaults to the subscriber\'s own', function () {
    $user = User::factory()->make(['email' => 'pat@example.com']);

    $fields = app(ChannelTypeRegistry::class)->find('email')->appSetupFields($user);

    expect($fields[0]->default)->toBe('pat@example.com')
        ->and($fields[0]->input)->toBe(ChannelSetupField::EMAIL);
});

test('a descriptor publishes everything except its rules, and webhook urls and bot tokens are secrets', function () {
    $user = User::factory()->make();
    $registry = app(ChannelTypeRegistry::class);

    expect(array_keys($registry->find('discord')->appSetupFields($user)[0]->toArray()))
        ->toBe(['key', 'label', 'input', 'required', 'help', 'placeholder', 'default']);

    foreach (['discord' => 0, 'slack' => 0, 'msteams' => 0, 'telegram' => 0] as $slug => $index) {
        expect($registry->find($slug)->appSetupFields($user)[$index]->input)->toBe(ChannelSetupField::SECRET);
    }
});
