<?php

use App\NativeComponents\ChannelCreator;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

function creatorTypes(array $discordOverrides = []): array
{
    return [
        ['slug' => 'email', 'label' => 'Email', 'can_add' => true, 'unavailable_reason' => null, 'fields' => [
            ['key' => 'to', 'label' => 'Email address', 'input' => 'email', 'required' => true, 'help' => null, 'placeholder' => null, 'default' => 'pat@example.com'],
        ]],
        ['slug' => 'discord', 'label' => 'Discord', 'can_add' => true, 'unavailable_reason' => null, 'fields' => [
            ['key' => 'webhook_url', 'label' => 'Webhook URL', 'input' => 'secret', 'required' => true, 'help' => 'From your Discord channel settings.', 'placeholder' => null, 'default' => null],
        ], ...$discordOverrides],
        ['slug' => 'ntfy', 'label' => 'ntfy', 'can_add' => true, 'unavailable_reason' => null, 'fields' => [
            ['key' => 'topic', 'label' => 'Topic', 'input' => 'text', 'required' => true, 'help' => null, 'placeholder' => null, 'default' => null],
            ['key' => 'server', 'label' => 'Server (optional)', 'input' => 'url', 'required' => false, 'help' => null, 'placeholder' => null, 'default' => null],
        ]],
    ];
}

function creatorFakes(array $extra = [], ?array $types = null): void
{
    Http::fake([
        ...$extra,
        'wx.test/api/v1/channel-types' => Http::response(['data' => $types ?? creatorTypes()]),
    ]);
}

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('it lists the types the server offers', function () {
    creatorFakes();

    Native::test(ChannelCreator::class)
        ->assertNavTitle('Add channel')
        ->assertSee('Email')
        ->assertSee('Discord')
        ->assertSee('ntfy');
});

test('a type the plan cannot add now is shown disabled and tapping it says why', function () {
    creatorFakes(types: [
        ...array_slice(creatorTypes(), 0, 1),
        ['slug' => 'discord', 'label' => 'Discord', 'can_add' => false, 'unavailable_reason' => 'channel_limit', 'fields' => []],
    ]);

    Native::test(ChannelCreator::class)
        ->assertSee('Not available on your plan right now')
        ->tap('type-discord')
        ->assertSet('selected', null)
        ->assertSee('Your plan\'s free channel is in use');
});

test('picking a type shows its fields from the descriptors, with the default filled and a secret masked', function () {
    creatorFakes();

    Native::test(ChannelCreator::class)
        ->tap('type-email')
        ->assertSet('values.0', 'pat@example.com')
        ->assertSee('Email address')
        ->assertSee('Send code');

    Native::test(ChannelCreator::class)
        ->tap('type-discord')
        ->assertSee('Webhook URL')
        ->assertSee('From your Discord channel settings.')
        ->assertElement('outlined_text_input', fn (array $input) => ($input['ref'] ?? null) === 'field-webhook_url' && ($input['props']['secure'] ?? false) === true);
});

test('a type with more fields than the app can show is not selectable', function () {
    $fields = array_map(fn (int $n): array => ['key' => "f{$n}", 'label' => "Field {$n}", 'input' => 'text', 'required' => false, 'help' => null, 'placeholder' => null, 'default' => null], range(1, 4));
    creatorFakes(types: [['slug' => 'big', 'label' => 'Big', 'can_add' => true, 'unavailable_reason' => null, 'fields' => $fields]]);

    Native::test(ChannelCreator::class)
        ->tap('type-big')
        ->assertSet('selected', null)
        ->assertSee('This channel type can\'t be set up in the app yet.');
});

test('a required field left empty is refused locally and nothing is sent', function () {
    creatorFakes();

    Native::test(ChannelCreator::class)
        ->tap('type-discord')
        ->tap('send-code')
        ->assertSee('Webhook URL is required.')
        ->assertNoNavigation();

    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

test('sending posts the type and the trimmed fields without empty optional ones, clears them and opens the channel to confirm', function () {
    creatorFakes(['wx.test/api/v1/channels' => Http::response(['data' => ['id' => 9, 'label' => 'ntfy']], 201)]);

    $screen = Native::test(ChannelCreator::class)
        ->tap('type-ntfy')
        ->input('field-topic', ' wx-topic ')
        ->tap('send-code')
        ->assertReplacedWith('/channels/9');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === ['channel_type' => 'ntfy', 'topic' => 'wx-topic']);
    $screen->assertSet('values', []);
});

test('a name given is sent as the label', function () {
    creatorFakes(['wx.test/api/v1/channels' => Http::response(['data' => ['id' => 9]], 201)]);

    Native::test(ChannelCreator::class)
        ->tap('type-ntfy')
        ->input('field-topic', 'wx')
        ->input('field-label', 'Phone alerts')
        ->tap('send-code');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === ['channel_type' => 'ntfy', 'topic' => 'wx', 'label' => 'Phone alerts']);
});

test('the server\'s per-field messages show under the field, and the typed value stays', function () {
    creatorFakes(['wx.test/api/v1/channels' => Http::response(['message' => 'Invalid.', 'errors' => ['webhook_url' => ['The webhook url format is invalid.']]], 422)]);

    Native::test(ChannelCreator::class)
        ->tap('type-discord')
        ->input('field-webhook_url', 'https://example.com/hook')
        ->tap('send-code')
        ->assertSee('The webhook url format is invalid.')
        ->assertSet('values.0', 'https://example.com/hook')
        ->assertNoNavigation();
});

test('the limit, an undeliverable code, offline and maintenance each say so beside the button and keep the form', function (callable $fake, string $text) {
    $fake();

    Native::test(ChannelCreator::class)
        ->tap('type-ntfy')
        ->input('field-topic', 'wx')
        ->tap('send-code')
        ->assertSee($text)
        ->assertSet('values.0', 'wx')
        ->assertNoNavigation();
})->with([
    'limit' => [fn () => creatorFakes(['wx.test/api/v1/channels' => Http::response(['error' => 'channel_limit', 'message' => 'Your plan\'s free channel is in use. Remove a channel, or upgrade, to add another.'], 422)]), 'free channel is in use'],
    'code not sent' => [fn () => creatorFakes(['wx.test/api/v1/channels' => Http::response(['error' => 'code_not_sent', 'message' => 'x'], 502)]), 'couldn\'t send the confirmation code'],
    'maintenance' => [fn () => creatorFakes(['wx.test/api/v1/channels' => Http::response('Back soon.', 503, ['Content-Type' => 'text/plain'])]), 'Back soon.'],
    'a server error' => [fn () => creatorFakes(['wx.test/api/v1/channels' => Http::response([], 500)]), 'Couldn\'t add the channel'],
    'too many sends' => [fn () => creatorFakes(['wx.test/api/v1/channels' => Http::response(['message' => 'Too Many Attempts.'], 429)]), 'You\'ve sent too many codes or tests. Try again in a little while.'],
]);

test('offline it says to reconnect, both when opening and when sending', function () {
    $this->network->connected = false;

    Native::test(ChannelCreator::class)->assertSee('Connect to the internet to add a channel.')->assertSee('Try again');

    $this->network->connected = true;
    creatorFakes();
    $screen = Native::test(ChannelCreator::class)->tap('type-ntfy')->input('field-topic', 'wx');
    $this->network->connected = false;

    $screen->tap('send-code')->assertSee('You\'re offline. Reconnect to send the code.')->assertSet('values.0', 'wx');
});

test('another type can be chosen again without carrying over the old values', function () {
    creatorFakes();

    Native::test(ChannelCreator::class)
        ->tap('type-discord')
        ->input('field-webhook_url', 'https://discord.com/api/webhooks/1/a')
        ->tap('Choose another type')
        ->assertSet('selected', null)
        ->assertSet('values', [])
        ->tap('type-email')
        ->assertSet('values', ['pat@example.com']);
});

test('a signed-out visitor is sent to Sign in', function () {
    (new TokenStore)->forget();

    Native::test(ChannelCreator::class)->assertReplacedWith('/sign-in');
});
