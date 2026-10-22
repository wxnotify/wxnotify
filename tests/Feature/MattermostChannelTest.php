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
use App\Support\ChannelTypes\MattermostChannelType;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\ChatServerGuard;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use ToneGabes\BetterOptions\Forms\Components\RadioCards;

use function Pest\Livewire\livewire;

// goToConnectChannelStep(), chanFakeChatDns(), chanChatWebhookValidate() and chanChatWebhookSubmit() live in tests/Pest.php.

const CHAN_MM_TOKEN = 'h7kq3m9xw5e8rbt4y6uzi3o5pa';

const CHAN_MM_OTHER_TOKEN = 'q2w3e4r5t6y7u8i9o0p1a2s3d4';

const CHAN_MM_PUBLIC_IP = '93.184.216.34';

const CHAN_MM_MESSAGE = 'Paste the whole incoming webhook URL from Mattermost; it ends in /hooks/ and a 26-character ID.';

const CHAN_MM_QUERY = 'redirect=no&cto=5&rto=10&image=no';

beforeEach(function () {
    $this->type = new MattermostChannelType;
    chanFakeChatDns(['chat.example.com' => [CHAN_MM_PUBLIC_IP], 'mm.example.org' => [CHAN_MM_PUBLIC_IP]]);
});

test('it parses webhook urls with and without a sub-path, port and trailing slash', function (string $url, array $config, string $appriseUrl, string $key) {
    $destination = $this->type->buildDestination(['webhook_url' => $url]);

    expect($destination)->toBe(['config' => $config, 'label' => 'Mattermost'])
        ->and(chanChatWebhookValidate($this->type, $url))->toBe(['passes' => true, 'errors' => []])
        ->and($this->type->appriseUrl($config))->toBe($appriseUrl.'?'.CHAN_MM_QUERY)
        ->and($this->type->serverUrl($config))->toBe($config['server'])
        ->and($this->type->destinationKey($config))->toBe($key);
})->with([
    'bare' => [
        'https://chat.example.com/hooks/'.CHAN_MM_TOKEN,
        ['server' => 'https://chat.example.com', 'path' => '', 'token' => CHAN_MM_TOKEN],
        'mmosts://chat.example.com/'.CHAN_MM_TOKEN,
        'chat.example.com:443/'.CHAN_MM_TOKEN,
    ],
    'sub-path' => [
        'https://chat.example.com/mm/hooks/'.CHAN_MM_TOKEN,
        ['server' => 'https://chat.example.com', 'path' => '/mm', 'token' => CHAN_MM_TOKEN],
        'mmosts://chat.example.com/mm/'.CHAN_MM_TOKEN,
        'chat.example.com:443/mm/'.CHAN_MM_TOKEN,
    ],
    'port 8065, two segments and a trailing slash' => [
        '  https://chat.example.com:8065/team.chat/a_b~c-d/hooks/'.CHAN_MM_TOKEN.'/  ',
        ['server' => 'https://chat.example.com:8065', 'path' => '/team.chat/a_b~c-d', 'token' => CHAN_MM_TOKEN],
        'mmosts://chat.example.com:8065/team.chat/a_b~c-d/'.CHAN_MM_TOKEN,
        'chat.example.com:8065/team.chat/a_b~c-d/'.CHAN_MM_TOKEN,
    ],
    'mixed case, explicit 443 and a trailing dot are normalised' => [
        'HTTPS://Chat.Example.COM.:443/Mm/hooks/'.CHAN_MM_TOKEN,
        ['server' => 'https://chat.example.com', 'path' => '/Mm', 'token' => CHAN_MM_TOKEN],
        'mmosts://chat.example.com/Mm/'.CHAN_MM_TOKEN,
        'chat.example.com:443/Mm/'.CHAN_MM_TOKEN,
    ],
    'an IPv6 literal keeps its brackets' => [
        'https://[2606:4700:0::1]:8443/mm/hooks/'.CHAN_MM_TOKEN,
        ['server' => 'https://[2606:4700::1]:8443', 'path' => '/mm', 'token' => CHAN_MM_TOKEN],
        'mmosts://[2606:4700::1]:8443/mm/'.CHAN_MM_TOKEN,
        '[2606:4700::1]:8443/mm/'.CHAN_MM_TOKEN,
    ],
]);

test('two spellings of the same webhook give one destination key, and a raw config is normalised too', function () {
    $tidy = $this->type->buildDestination(['webhook_url' => 'https://chat.example.com/mm/hooks/'.CHAN_MM_TOKEN])['config'];
    $loose = $this->type->buildDestination(['webhook_url' => 'https://CHAT.example.com.:443/mm/hooks/'.CHAN_MM_TOKEN.'/'])['config'];

    expect($this->type->destinationKey($loose))->toBe($this->type->destinationKey($tidy))
        ->and($this->type->destinationKey(['server' => 'HTTPS://Chat.Example.com.:443/', 'path' => '/mm/', 'token' => CHAN_MM_TOKEN]))
        ->toBe('chat.example.com:443/mm/'.CHAN_MM_TOKEN)
        ->and($this->type->destinationKey(['server' => 'https://chat.example.com:8065', 'path' => '/mm', 'token' => CHAN_MM_TOKEN]))
        ->not->toBe($this->type->destinationKey($tidy))
        ->and($this->type->destinationKey(['server' => 'not a url', 'path' => '', 'token' => CHAN_MM_TOKEN]))->toBe('')
        ->and($this->type->serverUrl(['path' => '', 'token' => CHAN_MM_TOKEN]))->toBeNull()
        ->and($this->type->serverUrl(['server' => '', 'path' => '', 'token' => CHAN_MM_TOKEN]))->toBeNull();
});

test('it refuses a url without /hooks/, a bad token, a query, a double slash and userinfo', function (string $url, string $message) {
    $result = chanChatWebhookValidate($this->type, $url);

    expect($result['passes'])->toBeFalse()
        ->and($result['errors'])->toContain($message)
        ->and(fn () => $this->type->buildDestination(['webhook_url' => $url]))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'no /hooks/' => ['https://chat.example.com/'.CHAN_MM_TOKEN, CHAN_MM_MESSAGE],
    'a REST path' => ['https://chat.example.com/api/v4/posts', CHAN_MM_MESSAGE],
    'only the token' => [CHAN_MM_TOKEN, CHAN_MM_MESSAGE],
    'a short token' => ['https://chat.example.com/hooks/abc123', CHAN_MM_MESSAGE],
    'an upper-case token' => ['https://chat.example.com/hooks/'.strtoupper(CHAN_MM_TOKEN), CHAN_MM_MESSAGE],
    'a 27-character token' => ['https://chat.example.com/hooks/'.CHAN_MM_TOKEN.'x', CHAN_MM_MESSAGE],
    'something after the token' => ['https://chat.example.com/hooks/'.CHAN_MM_TOKEN.'/extra', CHAN_MM_MESSAGE],
    'a query' => ['https://chat.example.com/hooks/'.CHAN_MM_TOKEN.'?channel=town-square', ChatServerGuard::NO_QUERY],
    'an empty query' => ['https://chat.example.com/hooks/'.CHAN_MM_TOKEN.'?', ChatServerGuard::NO_QUERY],
    'a fragment' => ['https://chat.example.com/hooks/'.CHAN_MM_TOKEN.'#x', ChatServerGuard::NO_QUERY],
    'a double slash before hooks' => ['https://chat.example.com//hooks/'.CHAN_MM_TOKEN, CHAN_MM_MESSAGE],
    'a double slash in the sub-path' => ['https://chat.example.com/mm//hooks/'.CHAN_MM_TOKEN, CHAN_MM_MESSAGE],
    'two trailing slashes' => ['https://chat.example.com/hooks/'.CHAN_MM_TOKEN.'//', CHAN_MM_MESSAGE],
    'userinfo' => ['https://user:pw@chat.example.com/hooks/'.CHAN_MM_TOKEN, ChatServerGuard::NO_USERINFO],
    'a host hidden behind userinfo' => ['https://chat.example.com@10.0.0.5/hooks/'.CHAN_MM_TOKEN, ChatServerGuard::NO_USERINFO],
    'an encoded slash in a segment' => ['https://chat.example.com/a%2Fb/hooks/'.CHAN_MM_TOKEN, CHAN_MM_MESSAGE],
    'an encoded space in a segment' => ['https://chat.example.com/a%20b/hooks/'.CHAN_MM_TOKEN, CHAN_MM_MESSAGE],
    'a unicode segment' => ['https://chat.example.com/café/hooks/'.CHAN_MM_TOKEN, CHAN_MM_MESSAGE],
    'a dot-dot segment' => ['https://chat.example.com/mm/../hooks/'.CHAN_MM_TOKEN, CHAN_MM_MESSAGE],
    'a single-dot segment' => ['https://chat.example.com/./hooks/'.CHAN_MM_TOKEN, CHAN_MM_MESSAGE],
    'ftp' => ['ftp://chat.example.com/hooks/'.CHAN_MM_TOKEN, ChatServerGuard::HTTPS_ONLY],
    'http in the cloud' => ['http://chat.example.com/hooks/'.CHAN_MM_TOKEN, ChatServerGuard::HTTPS_ONLY],
    'a single-label host' => ['https://apprise/hooks/'.CHAN_MM_TOKEN, ChatServerGuard::NEEDS_DOMAIN],
    'a newline after the host' => ["https://chat.example.com\n/hooks/".CHAN_MM_TOKEN, CHAN_MM_MESSAGE],
    'a carriage return after the host' => ["https://chat.example.com\r/hooks/".CHAN_MM_TOKEN, CHAN_MM_MESSAGE],
    'a tab in the host' => ["https://chat.\texample.com/hooks/".CHAN_MM_TOKEN, CHAN_MM_MESSAGE],
    'a NUL in the host' => ["https://chat.example.com\0/hooks/".CHAN_MM_TOKEN, CHAN_MM_MESSAGE],
    'a newline before the token' => ["https://chat.example.com/hooks/\n".CHAN_MM_TOKEN, CHAN_MM_MESSAGE],
    'a newline inside the path' => ["https://chat.example.com/mm\n/hooks/".CHAN_MM_TOKEN, CHAN_MM_MESSAGE],
]);

test('the field caps the url at 2048 characters', function () {
    $url = 'https://chat.example.com/'.str_repeat('a', 2048).'/hooks/'.CHAN_MM_TOKEN;

    expect(chanChatWebhookValidate($this->type, $url)['passes'])->toBeFalse()
        ->and(collect($this->type->formSchema())->first()->getValidationRules())
        ->toContain('max:2048');
});

test('http is allowed with mmost:// only where private networks are allowed', function () {
    config(['wxnotify.mode' => 'selfhost', 'wxnotify.webhooks.allow_private_networks' => true]);
    chanFakeChatDns(['mm.lan.example' => ['192.168.1.20']]);

    $config = $this->type->buildDestination(['webhook_url' => 'http://mm.lan.example:8065/hooks/'.CHAN_MM_TOKEN])['config'];

    expect($config['server'])->toBe('http://mm.lan.example:8065')
        ->and($this->type->appriseUrl($config))->toBe('mmost://mm.lan.example:8065/'.CHAN_MM_TOKEN.'?'.CHAN_MM_QUERY)
        ->and($this->type->buildDestination(['webhook_url' => 'http://mm.lan.example:80/hooks/'.CHAN_MM_TOKEN])['config']['server'])
        ->toBe('http://mm.lan.example');
});

test('a stored path or token outside the parser\'s set gets no authority, so the transport refuses the send', function (string $component, string $value) {
    Http::fake();
    $config = ['server' => 'https://chat.example.com', 'path' => '/mm/team', 'token' => CHAN_MM_TOKEN, $component => $value];

    expect($this->type->appriseUrl($config))->toStartWith('mmosts:///')
        ->and($this->type->appriseUrl(['server' => 'https://chat.example.com', 'path' => '/mm/team', 'token' => CHAN_MM_TOKEN]))
        ->toBe('mmosts://chat.example.com/mm/team/'.CHAN_MM_TOKEN.'?'.CHAN_MM_QUERY);

    $channel = NotificationChannel::factory()->mattermost(path: $config['path'], token: $config['token'])->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();
    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert');

    expect($result->success)->toBeFalse();
    Http::assertNothingSent();
})->with([
    'a slash that Apprise would decode into a segment (token)' => ['token', 'h7kq3m9xw5e8rbt4y6uzi3o5/a'],
    'a short token' => ['token', 'abc'],
    'an upper-case token' => ['token', strtoupper(CHAN_MM_TOKEN)],
    'a question mark in the path' => ['path', '/mm/a?b'],
    'an at sign in the path' => ['path', '/mm/a@b'],
    'a percent in the path' => ['path', '/mm/a%2Fb'],
    'a space in the path' => ['path', '/mm/a b'],
    'a unicode segment' => ['path', '/mm/é'],
    'a dot-dot segment' => ['path', '/mm/../x'],
    'a single-dot segment' => ['path', '/mm/./x'],
]);

test('every url-special character is refused in a segment and in the token before it can reach apprise', function (string $character) {
    $encoded = rawurlencode($character);

    $urls = ["https://chat.example.com/a{$encoded}b/hooks/".CHAN_MM_TOKEN, 'https://chat.example.com/hooks/'.substr(CHAN_MM_TOKEN, 0, 25).$encoded];

    // A raw slash only splits the sub-path into two segments, which is allowed.
    if ($character !== '/') {
        $urls[] = "https://chat.example.com/a{$character}b/hooks/".CHAN_MM_TOKEN;
        $urls[] = 'https://chat.example.com/hooks/'.substr(CHAN_MM_TOKEN, 0, 25).$character;
    }

    foreach ($urls as $url) {
        expect(chanChatWebhookValidate($this->type, $url)['passes'])->toBeFalse()
            ->and(fn () => $this->type->buildDestination(['webhook_url' => $url]))->toThrow(InvalidArgumentException::class);
    }
})->with(['/', '@', ':', '?', '#', '%', ' ', 'é']);

test('the allowed sub-path characters round-trip to apprise unencoded', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    chanChatWebhookSubmit('mattermost', 'https://chat.example.com/a.b_c~d-e/hooks/'.CHAN_MM_TOKEN);

    Http::assertSent(fn (Request $request): bool => $request['urls'] === 'mmosts://chat.example.com/a.b_c~d-e/'.CHAN_MM_TOKEN.'?'.CHAN_MM_QUERY);
});

test('completing the wizard as Plus posts the code to the webhook', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = chanChatWebhookSubmit('mattermost', 'https://chat.example.com:8065/mm/hooks/'.CHAN_MM_TOKEN.'/');
    $component->assertSee('We just posted a 6-digit code to your Mattermost channel.');

    $channel = NotificationChannel::where('user_id', $user->id)->sole();

    expect($channel->channel_type)->toBe('mattermost')
        ->and($channel->apprise_url)->toBeNull()
        ->and($channel->label)->toBe('Mattermost')
        ->and($channel->isVerified())->toBeFalse()
        ->and($channel->config)->toBe(['server' => 'https://chat.example.com:8065', 'path' => '/mm', 'token' => CHAN_MM_TOKEN]);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['urls'] === 'mmosts://chat.example.com:8065/mm/'.CHAN_MM_TOKEN.'?'.CHAN_MM_QUERY
        && ! array_key_exists('title', $request->data())
        && str_contains($request['body'], "Your WxNotify confirmation code is: {$channel->confirmation_code}"));
});

test('a Free subscriber sees the tile disabled and is refused on a forced nextStep', function () {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Free]));
    Http::fake();

    $page = livewire(ConnectChannel::class)->instance();
    $tile = $page->{$page->getDefaultTestingSchemaName()}->getComponent(fn ($component) => $component instanceof RadioCards);

    expect($tile->isOptionDisabled('mattermost', 'Mattermost'))->toBeTrue();

    $component = livewire(ConnectChannel::class)
        ->fillForm(['channel_type' => 'mattermost'])
        ->fillForm(['webhook_url' => 'https://chat.example.com/hooks/'.CHAN_MM_TOKEN]);
    goToConnectChannelStep($component, 1);

    $component->assertNotified('Your plan can\'t add that channel');
    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
});

test('a server that resolves privately is refused at save time', function (string $url, array $dns) {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));
    Http::fake();
    chanFakeChatDns($dns);

    chanChatWebhookSubmit('mattermost', $url)->assertHasFormErrors(['webhook_url']);

    expect(chanChatWebhookValidate($this->type, $url)['errors'])->toContain(ChatServerGuard::PRIVATE_NETWORK)
        ->and(fn () => $this->type->buildDestination(['webhook_url' => $url]))
        ->toThrow(InvalidArgumentException::class, ChatServerGuard::PRIVATE_NETWORK)
        ->and(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'a private answer' => ['https://chat.example.com/hooks/'.CHAN_MM_TOKEN, ['chat.example.com' => ['10.0.0.5']]],
    'a loopback answer' => ['https://chat.example.com/hooks/'.CHAN_MM_TOKEN, ['chat.example.com' => ['127.0.0.1']]],
    'the metadata address' => ['https://chat.example.com/hooks/'.CHAN_MM_TOKEN, ['chat.example.com' => ['169.254.169.254']]],
    'a loopback literal' => ['https://127.0.0.1:8065/hooks/'.CHAN_MM_TOKEN, []],
    'an IPv6 loopback literal' => ['https://[::1]:8065/hooks/'.CHAN_MM_TOKEN, []],
    'an internal name' => ['https://mm.internal/hooks/'.CHAN_MM_TOKEN, ['mm.internal' => [CHAN_MM_PUBLIC_IP]]],
]);

test('a server that turns private after saving is refused at send time without calling Apprise', function () {
    Http::fake();
    $channel = NotificationChannel::factory()->mattermost()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();
    chanFakeChatDns(['chat.example.com' => ['10.0.0.5']]);

    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe(ChatServerGuard::PRIVATE_NETWORK);
    Http::assertNothingSent();
});

test('an alert is posted as the composed chat text with no title', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->mattermost()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning for Boulder County.', 'Tornado Warning', ['url' => 'https://wx.example.com/view-product/kbou-1']);

    expect($result->success)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request['urls'] === 'mmosts://chat.example.com/'.CHAN_MM_TOKEN.'?'.CHAN_MM_QUERY
        && ! array_key_exists('title', $request->data())
        && $request['body'] === "Tornado Warning\n\nTornado Warning for Boulder County.\n\nhttps://wx.example.com/view-product/kbou-1");
});

test('a 424 records the fixed Mattermost text', function () {
    Http::fake(['*/notify/*' => Http::response(['error' => 'One or more notifications could not be sent'], 424)]);
    $channel = NotificationChannel::factory()->mattermost()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('Mattermost did not accept the message. Check the webhook URL and that the channel still exists.')
        ->and($this->type->failureMessage())->toBe($result->error);
});

test('an Apprise connection error propagates from the transport', function () {
    Http::fake(['*/notify/*' => Http::failedConnection()]);
    $channel = NotificationChannel::factory()->mattermost()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();

    expect(fn () => app(ChannelDispatcher::class)->send($channel, 'Tornado Warning'))
        ->toThrow(ConnectionException::class);
});

test('an Apprise connection error during the wizard says the code could not be sent and leaves no channel', function () {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));
    Http::fake(['*/notify/*' => Http::failedConnection()]);

    chanChatWebhookSubmit('mattermost', 'https://chat.example.com/hooks/'.CHAN_MM_TOKEN)
        ->assertNotified(Notification::make()
            ->title('Couldn\'t send your confirmation code')
            ->body('We couldn\'t post to that Mattermost webhook. Check the URL and that the server is reachable from the internet.')
            ->danger());

    expect(NotificationChannel::count())->toBe(0);
});

test('the same webhook twice is refused', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $first = NotificationChannel::factory()->for($user)->mattermost(path: '/mm')->create();

    chanChatWebhookSubmit('mattermost', 'https://CHAT.example.com.:443/mm/hooks/'.CHAN_MM_TOKEN.'/')
        ->assertNotified('You already have a Mattermost channel for that webhook.');

    expect(NotificationChannel::sole()->id)->toBe($first->id);
    Http::assertNothingSent();

    chanChatWebhookSubmit('mattermost', 'https://chat.example.com/mm/hooks/'.CHAN_MM_OTHER_TOKEN)
        ->assertNotNotified('You already have a Mattermost channel for that webhook.');
    chanChatWebhookSubmit('mattermost', 'https://mm.example.org/mm/hooks/'.CHAN_MM_TOKEN)
        ->assertNotNotified('You already have a Mattermost channel for that webhook.');

    expect(NotificationChannel::count())->toBe(3);
});

test('the secret never appears in config, the activity log, the failure detail or the edit modal', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::sequence()
        ->push(status: 200)
        ->push(['error' => 'One or more notifications could not be sent'], 424)]);

    $channel = app(BeginChannelConnection::class)($user, $this->type, ['webhook_url' => 'https://chat.example.com/mm/hooks/'.CHAN_MM_TOKEN]);
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

    expect($raw)->not->toContain(CHAN_MM_TOKEN)
        ->and($log)->not->toContain(CHAN_MM_TOKEN)
        ->and(json_encode($failure->toArray()))->not->toContain(CHAN_MM_TOKEN)
        ->and($snapshot)->not->toContain(CHAN_MM_TOKEN)
        ->and($failure->status)->toBe('failure')
        ->and($failure->error_detail)->toBe($this->type->failureMessage())
        ->and($component->instance()->mountedActions[0]['data'])->not->toHaveKeys(['config', 'apprise_url', 'confirmation_code']);
});

test('the type is a dashboard-only messaging type that shows the host and fixed texts', function () {
    $channel = NotificationChannel::factory()->mattermost(server: 'https://chat.example.com:8065')->create();

    expect($this->type->slug())->toBe('mattermost')
        ->and($this->type->label())->toBe('Mattermost')
        ->and($this->type->icon())->toBe('fab-mattermost')
        ->and($this->type->transport())->toBe(ChannelTransport::Messaging)
        ->and($this->type->requiredConfigKeys())->toBe(['server', 'token'])
        ->and($this->type->destinationNoun())->toBe('webhook')
        ->and($this->type->supportsInlineCreate())->toBeFalse()
        ->and($this->type->appSetupFields(User::factory()->make()))->toBeNull()
        ->and($this->type->countsTowardFreeSlot())->toBeFalse()
        ->and($this->type->displaySummary($channel))->toBe('chat.example.com')
        ->and($this->type->displaySummary(NotificationChannel::factory()->mattermost(server: 'https://[2606:4700::1]')->make()))->toBe('[2606:4700::1]')
        ->and($this->type->displaySummary(NotificationChannel::factory()->make(['channel_type' => 'mattermost', 'apprise_url' => null, 'config' => []])))->toBe('—')
        ->and($this->type->destinationLabel())->toBe('Mattermost server')
        ->and($this->type->confirmationHint($channel))->toBe('We just posted a 6-digit code to your Mattermost channel.')
        ->and($this->type->codeNotSentHint())->toBe('We couldn\'t post to that Mattermost webhook. Check the URL and that the server is reachable from the internet.');
});

test('the API refuses to create a mattermost channel', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    Http::fake();

    $this->withToken($user->createToken('Pixel 9', ['app'])->plainTextToken)
        ->postJson('/api/v1/channels', ['channel_type' => 'mattermost', 'webhook_url' => 'https://chat.example.com/hooks/'.CHAN_MM_TOKEN])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['channel_type']);

    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
});
