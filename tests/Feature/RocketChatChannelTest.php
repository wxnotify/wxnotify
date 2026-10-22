<?php

use App\Actions\Channels\BeginChannelConnection;
use App\Enums\ChannelTransport;
use App\Enums\Tier;
use App\Filament\Dashboard\Pages\ConnectChannel;
use App\Filament\Dashboard\Resources\NotificationChannels\Pages\ListNotificationChannels;
use App\Jobs\DeliverWeatherAlert;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelTypes\RocketChatChannelType;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\ChatServerGuard;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Livewire\Features\SupportTesting\Testable;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

// goToConnectChannelStep() and chanFakeChatDns() live in tests/Pest.php.

const CHAN_RC_ID = 'aB3dE5fG7hJ9kL2mN';

const CHAN_RC_TOKEN = 'Zx9Cv8Bn7Mq6Wr5Ty4Ui3Op2As1Df0Gh';

const CHAN_RC_OTHER_ID = 'qW1eR2tY3uI4oP5aS';

const CHAN_RC_PUBLIC_IP = '93.184.216.34';

const CHAN_RC_MESSAGE = 'Paste the whole incoming webhook URL from Rocket.Chat; it ends in /hooks/, an ID and a token.';

const CHAN_RC_SUBPATH = 'Rocket.Chat under a sub-path isn\'t supported yet.';

const CHAN_RC_API = 'Paste the incoming webhook URL (Administration → Integrations → New → Incoming), not an API address.';

const CHAN_RC_QUERY = 'redirect=no&cto=5&rto=10&avatar=no';

beforeEach(function () {
    $this->type = new RocketChatChannelType;
    chanFakeChatDns(['chat.example.com' => [CHAN_RC_PUBLIC_IP], 'rc.example.org' => [CHAN_RC_PUBLIC_IP]]);
});

/**
 * Whether the webhook field's own rules accept the value, and its errors.
 *
 * @return array{passes: bool, errors: list<string>}
 */
function chanRocketChatValidate(RocketChatChannelType $type, mixed $value): array
{
    $field = collect($type->formSchema())->keyBy(fn ($component) => $component->getName())['webhook_url'];
    $validator = Validator::make(['webhook_url' => $value], ['webhook_url' => $field->getValidationRules()]);

    return ['passes' => $validator->passes(), 'errors' => $validator->errors()->get('webhook_url')];
}

/**
 * Pick Rocket.Chat in the wizard, paste the URL and submit step 2.
 */
function chanRocketChatSubmit(string $url): Testable
{
    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'rocketchat']);
    goToConnectChannelStep($component, 0);
    $component->fillForm(['webhook_url' => $url]);

    return goToConnectChannelStep($component, 1);
}

test('it parses the incoming webhook url with and without a port', function (string $url, array $config, string $appriseUrl, string $key) {
    $destination = $this->type->buildDestination(['webhook_url' => $url]);

    expect($destination)->toBe(['config' => $config, 'label' => 'Rocket.Chat'])
        ->and(chanRocketChatValidate($this->type, $url))->toBe(['passes' => true, 'errors' => []])
        ->and($this->type->appriseUrl($config))->toBe($appriseUrl.'?'.CHAN_RC_QUERY)
        ->and($this->type->serverUrl($config))->toBe($config['server'])
        ->and($this->type->destinationKey($config))->toBe($key);
})->with([
    'bare' => [
        'https://chat.example.com/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN,
        ['server' => 'https://chat.example.com', 'webhook_id' => CHAN_RC_ID, 'webhook_token' => CHAN_RC_TOKEN],
        'rockets://'.CHAN_RC_ID.'%2F'.CHAN_RC_TOKEN.'@chat.example.com',
        'chat.example.com:443/'.CHAN_RC_ID,
    ],
    'port 3000 and a trailing slash, padded' => [
        '  https://chat.example.com:3000/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN.'/  ',
        ['server' => 'https://chat.example.com:3000', 'webhook_id' => CHAN_RC_ID, 'webhook_token' => CHAN_RC_TOKEN],
        'rockets://'.CHAN_RC_ID.'%2F'.CHAN_RC_TOKEN.'@chat.example.com:3000',
        'chat.example.com:3000/'.CHAN_RC_ID,
    ],
    'mixed case, explicit 443 and a trailing dot are normalised (the id and token keep their case)' => [
        'HTTPS://Chat.Example.COM.:443/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN,
        ['server' => 'https://chat.example.com', 'webhook_id' => CHAN_RC_ID, 'webhook_token' => CHAN_RC_TOKEN],
        'rockets://'.CHAN_RC_ID.'%2F'.CHAN_RC_TOKEN.'@chat.example.com',
        'chat.example.com:443/'.CHAN_RC_ID,
    ],
    'an IPv6 literal keeps its brackets' => [
        'https://[2606:4700:0::1]:8443/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN,
        ['server' => 'https://[2606:4700::1]:8443', 'webhook_id' => CHAN_RC_ID, 'webhook_token' => CHAN_RC_TOKEN],
        'rockets://'.CHAN_RC_ID.'%2F'.CHAN_RC_TOKEN.'@[2606:4700::1]:8443',
        '[2606:4700::1]:8443/'.CHAN_RC_ID,
    ],
]);

test('two spellings of the same webhook give one destination key, and a raw config is normalised too', function () {
    $tidy = $this->type->buildDestination(['webhook_url' => 'https://chat.example.com/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN])['config'];
    $loose = $this->type->buildDestination(['webhook_url' => 'https://CHAT.example.com.:443/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN.'/'])['config'];

    expect($this->type->destinationKey($loose))->toBe($this->type->destinationKey($tidy))
        ->and($this->type->destinationKey(['server' => 'HTTPS://Chat.Example.com.:443/', 'webhook_id' => CHAN_RC_ID, 'webhook_token' => CHAN_RC_TOKEN]))
        ->toBe('chat.example.com:443/'.CHAN_RC_ID)
        ->and($this->type->destinationKey(['server' => 'https://chat.example.com:3000', 'webhook_id' => CHAN_RC_ID, 'webhook_token' => CHAN_RC_TOKEN]))
        ->not->toBe($this->type->destinationKey($tidy))
        ->and($this->type->destinationKey(['server' => 'not a url', 'webhook_id' => CHAN_RC_ID, 'webhook_token' => CHAN_RC_TOKEN]))->toBe('')
        ->and($this->type->serverUrl(['webhook_id' => CHAN_RC_ID, 'webhook_token' => CHAN_RC_TOKEN]))->toBeNull()
        ->and($this->type->serverUrl(['server' => '', 'webhook_id' => CHAN_RC_ID, 'webhook_token' => CHAN_RC_TOKEN]))->toBeNull();
});

test('it refuses a sub-path, an api url, a pasted user token and a bad id', function (string $url, string $message) {
    $result = chanRocketChatValidate($this->type, $url);

    expect($result['passes'])->toBeFalse()
        ->and($result['errors'])->toContain($message)
        ->and(fn () => $this->type->buildDestination(['webhook_url' => $url]))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'a sub-path' => ['https://chat.example.com/rc/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN, CHAN_RC_SUBPATH],
    'a deeper sub-path' => ['https://chat.example.com/a/b/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN, CHAN_RC_SUBPATH],
    'an api login url' => ['https://chat.example.com/api/v1/login', CHAN_RC_API],
    'an api chat.postMessage url' => ['https://chat.example.com/api/v1/chat.postMessage', CHAN_RC_API],
    'an api url that mentions hooks' => ['https://chat.example.com/api/v1/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN, CHAN_RC_API],
    'only the id and token' => [CHAN_RC_ID.'/'.CHAN_RC_TOKEN, CHAN_RC_MESSAGE],
    'a pasted user token with no path' => ['https://chat.example.com/'.CHAN_RC_TOKEN, CHAN_RC_MESSAGE],
    'only an id after hooks' => ['https://chat.example.com/hooks/'.CHAN_RC_ID, CHAN_RC_MESSAGE],
    'an empty token' => ['https://chat.example.com/hooks/'.CHAN_RC_ID.'/', CHAN_RC_MESSAGE],
    'an empty id' => ['https://chat.example.com/hooks//'.CHAN_RC_TOKEN, CHAN_RC_MESSAGE],
    'a bad id (hyphen)' => ['https://chat.example.com/hooks/aB3-dE5/'.CHAN_RC_TOKEN, CHAN_RC_MESSAGE],
    'a bad token (underscore)' => ['https://chat.example.com/hooks/'.CHAN_RC_ID.'/Zx9_Cv8', CHAN_RC_MESSAGE],
    'something after the token' => ['https://chat.example.com/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN.'/extra', CHAN_RC_MESSAGE],
    'two trailing slashes' => ['https://chat.example.com/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN.'//', CHAN_RC_MESSAGE],
    'a double slash before hooks' => ['https://chat.example.com//hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN, CHAN_RC_MESSAGE],
    'a query' => ['https://chat.example.com/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN.'?channel=general', ChatServerGuard::NO_QUERY],
    'a fragment' => ['https://chat.example.com/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN.'#x', ChatServerGuard::NO_QUERY],
    'userinfo' => ['https://user:pw@chat.example.com/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN, ChatServerGuard::NO_USERINFO],
    'a host hidden behind userinfo' => ['https://chat.example.com@10.0.0.5/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN, ChatServerGuard::NO_USERINFO],
    'ftp' => ['ftp://chat.example.com/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN, ChatServerGuard::HTTPS_ONLY],
    'http in the cloud' => ['http://chat.example.com/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN, ChatServerGuard::HTTPS_ONLY],
    'a single-label host' => ['https://apprise/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN, ChatServerGuard::NEEDS_DOMAIN],
    'a newline after the host' => ["https://chat.example.com\n/hooks/".CHAN_RC_ID.'/'.CHAN_RC_TOKEN, CHAN_RC_MESSAGE],
    'a carriage return after the host' => ["https://chat.example.com\r/hooks/".CHAN_RC_ID.'/'.CHAN_RC_TOKEN, CHAN_RC_MESSAGE],
    'a tab in the host' => ["https://chat.\texample.com/hooks/".CHAN_RC_ID.'/'.CHAN_RC_TOKEN, CHAN_RC_MESSAGE],
    'a NUL in the host' => ["https://chat.example.com\0/hooks/".CHAN_RC_ID.'/'.CHAN_RC_TOKEN, CHAN_RC_MESSAGE],
    'a newline before the token' => ['https://chat.example.com/hooks/'.CHAN_RC_ID."/\n".CHAN_RC_TOKEN, CHAN_RC_MESSAGE],
]);

test('the field caps the url at 2048 characters', function () {
    $url = 'https://chat.example.com/hooks/'.CHAN_RC_ID.'/'.str_repeat('a', 2048);

    expect(chanRocketChatValidate($this->type, $url)['passes'])->toBeFalse()
        ->and(collect($this->type->formSchema())->first()->getValidationRules())
        ->toContain('max:2048');
});

test('http is allowed with rocket:// only where private networks are allowed', function () {
    config(['wxnotify.mode' => 'selfhost', 'wxnotify.webhooks.allow_private_networks' => true]);
    chanFakeChatDns(['rc.lan.example' => ['192.168.1.20']]);

    $config = $this->type->buildDestination(['webhook_url' => 'http://rc.lan.example:3000/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN])['config'];

    expect($config['server'])->toBe('http://rc.lan.example:3000')
        ->and($this->type->appriseUrl($config))->toBe('rocket://'.CHAN_RC_ID.'%2F'.CHAN_RC_TOKEN.'@rc.lan.example:3000?'.CHAN_RC_QUERY);
});

test('it encodes the webhook in the userinfo with %2F and builds the authority from the stored server', function () {
    $config = ['server' => 'https://Chat.Example.com.:443', 'webhook_id' => CHAN_RC_ID, 'webhook_token' => CHAN_RC_TOKEN];

    expect($this->type->appriseUrl($config))
        ->toBe('rockets://'.CHAN_RC_ID.'%2F'.CHAN_RC_TOKEN.'@chat.example.com?'.CHAN_RC_QUERY)
        ->and($this->type->appriseUrl(['webhook_id' => CHAN_RC_ID, 'webhook_token' => CHAN_RC_TOKEN]))
        ->toBe('rockets://'.CHAN_RC_ID.'%2F'.CHAN_RC_TOKEN.'@?'.CHAN_RC_QUERY);

    $hostile = $this->type->appriseUrl(['server' => 'https://chat.example.com', 'webhook_id' => 'a@b/c', 'webhook_token' => 'x:y?z#w%v é']);

    expect($hostile)->toBe('rockets://a%40b%2Fc%2Fx%3Ay%3Fz%23w%25v%20%C3%A9@chat.example.com?'.CHAN_RC_QUERY);
});

test('every url-special character is refused in the id and in the token before it can reach apprise', function (string $character) {
    $encoded = rawurlencode($character);
    $urls = [
        "https://chat.example.com/hooks/a{$encoded}b/".CHAN_RC_TOKEN,
        'https://chat.example.com/hooks/'.CHAN_RC_ID."/a{$encoded}b",
    ];

    // A raw slash adds a path segment, which the shape rule refuses as well.
    $urls[] = "https://chat.example.com/hooks/a{$character}b/".CHAN_RC_TOKEN;
    $urls[] = 'https://chat.example.com/hooks/'.CHAN_RC_ID."/a{$character}b";

    foreach ($urls as $url) {
        expect(chanRocketChatValidate($this->type, $url)['passes'])->toBeFalse($url)
            ->and(fn () => $this->type->buildDestination(['webhook_url' => $url]))->toThrow(InvalidArgumentException::class);
    }
})->with(['/', '@', ':', '?', '#', '%', ' ', 'é', '.', '-', '_', '~', '+']);

test('completing the wizard as Plus posts the code', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = chanRocketChatSubmit('https://chat.example.com:3000/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN.'/');
    $component->assertSee('We just posted a 6-digit code to your Rocket.Chat channel.');

    $channel = NotificationChannel::where('user_id', $user->id)->sole();

    expect($channel->channel_type)->toBe('rocketchat')
        ->and($channel->apprise_url)->toBeNull()
        ->and($channel->label)->toBe('Rocket.Chat')
        ->and($channel->isVerified())->toBeFalse()
        ->and($channel->config)->toBe(['server' => 'https://chat.example.com:3000', 'webhook_id' => CHAN_RC_ID, 'webhook_token' => CHAN_RC_TOKEN]);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['urls'] === 'rockets://'.CHAN_RC_ID.'%2F'.CHAN_RC_TOKEN.'@chat.example.com:3000?'.CHAN_RC_QUERY
        && ! array_key_exists('title', $request->data())
        && str_contains($request['body'], "Your WxNotify confirmation code is: {$channel->confirmation_code}"));
});

test('a Free subscriber is refused on a forced nextStep', function () {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Free]));
    Http::fake();

    $component = livewire(ConnectChannel::class)
        ->fillForm(['channel_type' => 'rocketchat'])
        ->fillForm(['webhook_url' => 'https://chat.example.com/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN]);
    goToConnectChannelStep($component, 1);

    $component->assertNotified('Your plan can\'t add that channel');
    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
});

test('a server that resolves privately is refused at save time', function (string $url, array $dns) {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));
    Http::fake();
    chanFakeChatDns($dns);

    chanRocketChatSubmit($url)->assertHasFormErrors(['webhook_url']);

    expect(chanRocketChatValidate($this->type, $url)['errors'])->toContain(ChatServerGuard::PRIVATE_NETWORK)
        ->and(fn () => $this->type->buildDestination(['webhook_url' => $url]))
        ->toThrow(InvalidArgumentException::class, ChatServerGuard::PRIVATE_NETWORK)
        ->and(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'a private answer' => ['https://chat.example.com/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN, ['chat.example.com' => ['10.0.0.5']]],
    'a loopback answer' => ['https://chat.example.com/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN, ['chat.example.com' => ['127.0.0.1']]],
    'the metadata address' => ['https://chat.example.com/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN, ['chat.example.com' => ['169.254.169.254']]],
    'a loopback literal' => ['https://127.0.0.1:3000/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN, []],
    'an IPv6 loopback literal' => ['https://[::1]:3000/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN, []],
    'an internal name' => ['https://rc.internal/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN, ['rc.internal' => [CHAN_RC_PUBLIC_IP]]],
]);

test('private at save and at send time is refused', function () {
    Http::fake();
    $channel = NotificationChannel::factory()->rocketChat()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();
    chanFakeChatDns(['chat.example.com' => ['10.0.0.5']]);

    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe(ChatServerGuard::PRIVATE_NETWORK);
    Http::assertNothingSent();
});

test('a single-label server is refused at send time too', function () {
    Http::fake();
    $channel = NotificationChannel::factory()->rocketChat(server: 'https://apprise')->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe(ChatServerGuard::NEEDS_DOMAIN);
    Http::assertNothingSent();
});

test('messages are cut to 1000 characters with the link kept', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->rocketChat()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();
    $link = 'https://wx.example.com/view-product/kbou-1';

    $result = app(ChannelDispatcher::class)->send($channel, str_repeat('Tornado warning text. ', 200), 'Tornado Warning', ['url' => $link]);

    expect($result->success)->toBeTrue()
        ->and($this->type->maxLength())->toBe(1000);
    Http::assertSent(fn (Request $request): bool => mb_strlen($request['body']) <= 1000
        && str_ends_with($request['body'], "\n\n{$link}")
        && str_starts_with($request['body'], "Tornado Warning\n\n")
        && str_contains($request['body'], '...')
        && $request['urls'] === 'rockets://'.CHAN_RC_ID.'%2F'.CHAN_RC_TOKEN.'@chat.example.com?'.CHAN_RC_QUERY
        && ! array_key_exists('title', $request->data()));
});

test('a 424 records the fixed Rocket.Chat text', function () {
    Http::fake(['*/notify/*' => Http::response(['error' => 'One or more notifications could not be sent'], 424)]);
    $channel = NotificationChannel::factory()->rocketChat()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('Rocket.Chat did not accept the message. Check the webhook URL and that the channel still exists.')
        ->and($this->type->failureMessage())->toBe($result->error);
});

test('an Apprise connection error propagates from the transport', function () {
    Http::fake(['*/notify/*' => Http::failedConnection()]);
    $channel = NotificationChannel::factory()->rocketChat()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();

    expect(fn () => app(ChannelDispatcher::class)->send($channel, 'Tornado Warning'))
        ->toThrow(ConnectionException::class);
});

test('an Apprise connection error during the wizard says the code could not be sent and leaves no channel', function () {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));
    Http::fake(['*/notify/*' => Http::failedConnection()]);

    chanRocketChatSubmit('https://chat.example.com/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN)
        ->assertNotified(Notification::make()
            ->title('Couldn\'t send your confirmation code')
            ->body('We couldn\'t post to that Rocket.Chat webhook. Check the URL and that the server is reachable from the internet.')
            ->danger());

    expect(NotificationChannel::count())->toBe(0);
});

test('the same webhook twice is refused', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $first = NotificationChannel::factory()->for($user)->rocketChat()->create();

    chanRocketChatSubmit('https://CHAT.example.com.:443/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN.'/')
        ->assertNotified('You already have a Rocket.Chat channel for that webhook.');

    expect(NotificationChannel::sole()->id)->toBe($first->id);
    Http::assertNothingSent();

    chanRocketChatSubmit('https://chat.example.com/hooks/'.CHAN_RC_OTHER_ID.'/'.CHAN_RC_TOKEN)
        ->assertNotNotified('You already have a Rocket.Chat channel for that webhook.');
    chanRocketChatSubmit('https://rc.example.org/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN)
        ->assertNotNotified('You already have a Rocket.Chat channel for that webhook.');

    expect(NotificationChannel::count())->toBe(3);
});

test('the secret never appears in config, the activity log, the failure detail or the edit modal', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::sequence()
        ->push(status: 200)
        ->push(['error' => 'One or more notifications could not be sent'], 424)]);

    $channel = app(BeginChannelConnection::class)($user, $this->type, ['webhook_url' => 'https://chat.example.com/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN]);
    $channel->forceFill(['verified_at' => now()])->save();
    $channel->update(['label' => 'Ops room']);

    $raw = (string) DB::table('notification_channels')->where('id', $channel->id)->value('config');
    $log = json_encode(Activity::all()->toArray());

    $rule = AlertRule::factory()->for($user)->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $product = IngestedProduct::factory()->create(['text' => 'Tornado Warning for Boulder County.']);
    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(ChannelDispatcher::class));
    $failure = DeliveryLog::sole();

    $component = livewire(ListNotificationChannels::class)
        ->mountAction(TestAction::make('edit')->table($channel))
        ->assertSchemaComponentHidden('apprise_url_preview');
    $snapshot = json_encode($component->snapshot);

    expect($raw)->not->toContain(CHAN_RC_TOKEN)
        ->and($log)->not->toContain(CHAN_RC_TOKEN)
        ->and(json_encode($failure->toArray()))->not->toContain(CHAN_RC_TOKEN)
        ->and($snapshot)->not->toContain(CHAN_RC_TOKEN)
        ->and($failure->status)->toBe('failure')
        ->and($failure->error_detail)->toBe($this->type->failureMessage())
        ->and($component->instance()->mountedActions[0]['data'])->not->toHaveKeys(['config', 'apprise_url', 'confirmation_code']);
});

test('the type is a dashboard-only messaging type that shows the host and fixed texts', function () {
    $channel = NotificationChannel::factory()->rocketChat(server: 'https://chat.example.com:3000')->create();

    expect($this->type->slug())->toBe('rocketchat')
        ->and($this->type->label())->toBe('Rocket.Chat')
        ->and($this->type->icon())->toBe('fab-rocketchat')
        ->and($this->type->transport())->toBe(ChannelTransport::Messaging)
        ->and($this->type->requiredConfigKeys())->toBe(['server', 'webhook_id', 'webhook_token'])
        ->and($this->type->destinationNoun())->toBe('webhook')
        ->and($this->type->supportsInlineCreate())->toBeFalse()
        ->and($this->type->appSetupFields(User::factory()->make()))->toBeNull()
        ->and($this->type->countsTowardFreeSlot())->toBeFalse()
        ->and($this->type->displaySummary($channel))->toBe('chat.example.com')
        ->and($this->type->displaySummary(NotificationChannel::factory()->rocketChat(server: 'https://[2606:4700::1]')->make()))->toBe('[2606:4700::1]')
        ->and($this->type->displaySummary(NotificationChannel::factory()->make(['channel_type' => 'rocketchat', 'apprise_url' => null, 'config' => []])))->toBe('—')
        ->and($this->type->destinationLabel())->toBe('Rocket.Chat server')
        ->and($this->type->confirmationHint($channel))->toBe('We just posted a 6-digit code to your Rocket.Chat channel.')
        ->and($this->type->codeNotSentHint())->toBe('We couldn\'t post to that Rocket.Chat webhook. Check the URL and that the server is reachable from the internet.');
});

test('the API refuses to create a rocketchat channel', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    Http::fake();

    $this->withToken($user->createToken('Pixel 9', ['app'])->plainTextToken)
        ->postJson('/api/v1/channels', ['channel_type' => 'rocketchat', 'webhook_url' => 'https://chat.example.com/hooks/'.CHAN_RC_ID.'/'.CHAN_RC_TOKEN])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['channel_type']);

    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
});
