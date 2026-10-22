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
use App\Support\ChannelTypes\MatrixChannelType;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\ChatServerGuard;
use App\Support\Delivery\Transports\MessagingTransport;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

// goToConnectChannelStep(), chanFakeChatDns(), chanChatFieldValidate() and chanChatFormSubmit() live in tests/Pest.php.

const CHAN_MX_TOKEN = 'syt_d3hub3RpZnk_AbCdEfGhIjKlMnOpQrSt_1a2B3c';

const CHAN_MX_OTHER_TOKEN = 'syt_b3RoZXI_ZyXwVuTsRqPoNmLkJiHg_9z8Y7x';

const CHAN_MX_ROOM = '!AbCdEfGhIjKlMn:example.org';

const CHAN_MX_PUBLIC_IP = '93.184.216.34';

const CHAN_MX_ROOM_MESSAGE = 'Enter a room ID (!abc123:example.org) or alias (#weather:example.org).';

const CHAN_MX_QUERY = 'redirect=no&cto=5&rto=10&discovery=no&e2ee=no&mode=off&image=no&hsreq=no';

/**
 * The Matrix form's data, with overrides.
 *
 * @param  array<string, string>  $overrides
 * @return array{homeserver: string, access_token: string, room: string}
 */
function chanMatrixFormData(array $overrides = []): array
{
    return [
        'homeserver' => 'https://matrix.example.org',
        'access_token' => CHAN_MX_TOKEN,
        'room' => CHAN_MX_ROOM,
        ...$overrides,
    ];
}

beforeEach(function () {
    $this->type = new MatrixChannelType;
    chanFakeChatDns(['matrix.example.org' => [CHAN_MX_PUBLIC_IP], 'matrix.example.net' => [CHAN_MX_PUBLIC_IP]]);
});

test('it accepts room ids with and without a server part and aliases', function (string $room, string $encoded) {
    $destination = $this->type->buildDestination(chanMatrixFormData(['room' => "  {$room} "]));
    $config = ['server' => 'https://matrix.example.org', 'token' => CHAN_MX_TOKEN, 'room' => $room];

    expect($destination)->toBe(['config' => $config, 'label' => 'Matrix'])
        ->and(chanChatFieldValidate($this->type, 'room', $room))->toBe(['passes' => true, 'errors' => []])
        ->and($this->type->appriseUrl($config))->toBe('matrixs://'.CHAN_MX_TOKEN."@matrix.example.org/{$encoded}?".CHAN_MX_QUERY);
})->with([
    'an id with a server' => [CHAN_MX_ROOM, '%21AbCdEfGhIjKlMn%3Aexample.org'],
    'a room v12 id with no server part' => ['!31hneApxJ_1o-63DmFrpeqnkFfWppnzWso1JvH3ogLM', '%2131hneApxJ_1o-63DmFrpeqnkFfWppnzWso1JvH3ogLM'],
    'an id with = . _ -' => ['!a.b_c=d-e:matrix-1.example.org', '%21a.b_c%3Dd-e%3Amatrix-1.example.org'],
    'an alias' => ['#weather:example.org', '%23weather%3Aexample.org'],
    'an alias with = . _ -' => ['#wx.alerts_co=1-a:example.org', '%23wx.alerts_co%3D1-a%3Aexample.org'],
]);

test('it refuses @user, a bare name and unicode', function (string $room) {
    $result = chanChatFieldValidate($this->type, 'room', $room);

    expect($result['passes'])->toBeFalse()
        ->and($result['errors'])->toContain(CHAN_MX_ROOM_MESSAGE)
        ->and(fn () => $this->type->buildDestination(chanMatrixFormData(['room' => $room])))
        ->toThrow(InvalidArgumentException::class, CHAN_MX_ROOM_MESSAGE);
})->with([
    'a user' => ['@alice:example.org'],
    'a bare name' => ['weather'],
    'a bare name with a server' => ['weather:example.org'],
    'an alias with no server' => ['#weather'],
    'an alias with an empty server' => ['#weather:'],
    'an id with an empty server' => ['!abc:'],
    'only the sigil' => ['!'],
    'unicode in the alias' => ['#météo:example.org'],
    'unicode in the server' => ['#weather:exämple.org'],
    'a port on the server' => ['#weather:example.org:8448'],
    'two rooms' => ['!abc:example.org,#weather:example.org'],
    'a matrix.to link' => ['https://matrix.to/#/#weather:example.org'],
    'a newline after the room' => ["!abc:example.org\n#weather:example.org"],
    'a NUL' => ["!abc\0:example.org"],
]);

test('the room\'s ! # : are percent-encoded in the path', function () {
    expect($this->type->appriseUrl(['server' => 'https://matrix.example.org', 'token' => CHAN_MX_TOKEN, 'room' => '!x:ex.org']))
        ->toBe('matrixs://'.CHAN_MX_TOKEN.'@matrix.example.org/%21x%3Aex.org?'.CHAN_MX_QUERY)
        ->and($this->type->appriseUrl(['server' => 'https://matrix.example.org', 'token' => CHAN_MX_TOKEN, 'room' => '#w:ex.org']))
        ->toBe('matrixs://'.CHAN_MX_TOKEN.'@matrix.example.org/%23w%3Aex.org?'.CHAN_MX_QUERY);
});

test('every url-special character is refused in the room and the token, raw and percent-encoded', function (string $character) {
    $encoded = rawurlencode($character);
    $rooms = ["!a{$character}b:example.org", "!a{$encoded}b:example.org", "#a{$character}b:example.org", "#a{$encoded}b:example.org", "#ab:exa{$character}mple.org"];

    foreach ($rooms as $room) {
        expect(chanChatFieldValidate($this->type, 'room', $room)['passes'])->toBeFalse($room)
            ->and(fn () => $this->type->buildDestination(chanMatrixFormData(['room' => $room])))->toThrow(InvalidArgumentException::class);
    }

    // The token may hold a raw / and + (encoded on the way, see the wizard test), never a %.
    $tokens = in_array($character, ['/', '+'], true) ? ["syt_a{$encoded}b"] : ["syt_a{$character}b", "syt_a{$encoded}b"];

    foreach ($tokens as $token) {
        expect(chanChatFieldValidate($this->type, 'access_token', $token)['passes'])->toBeFalse($token)
            ->and(fn () => $this->type->buildDestination(chanMatrixFormData(['access_token' => $token])))->toThrow(InvalidArgumentException::class);
    }
})->with(['/', '@', ':', '?', '#', '%', ' ', 'é', '&', ',', '+']);

test('the url never carries a user and always sets discovery=no and e2ee=no', function () {
    $url = $this->type->appriseUrl(['server' => 'https://matrix.example.org:8448', 'token' => 'abc/def+ghi=~._-', 'room' => CHAN_MX_ROOM]);
    [$userinfo] = explode('@', substr($url, strlen('matrixs://')), 2);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toBe('matrixs://abc%2Fdef%2Bghi%3D~._-@matrix.example.org:8448/%21AbCdEfGhIjKlMn%3Aexample.org?'.CHAN_MX_QUERY)
        ->and($userinfo)->not->toContain(':')
        ->and($query)->toBe(['redirect' => 'no', 'cto' => '5', 'rto' => '10', 'discovery' => 'no', 'e2ee' => 'no', 'mode' => 'off', 'image' => 'no', 'hsreq' => 'no']);
});

test('a homeserver with a path or query is refused', function (string $homeserver, string $message) {
    $result = chanChatFieldValidate($this->type, 'homeserver', $homeserver);

    expect($result['passes'])->toBeFalse()
        ->and($result['errors'])->toContain($message)
        ->and(fn () => $this->type->buildDestination(chanMatrixFormData(['homeserver' => $homeserver])))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'the client api path' => ['https://matrix.example.org/_matrix/client', MatrixChannelType::HOMESERVER_MESSAGE],
    'a sub-path' => ['https://example.org/matrix', MatrixChannelType::HOMESERVER_MESSAGE],
    'two trailing slashes' => ['https://matrix.example.org//', MatrixChannelType::HOMESERVER_MESSAGE],
    'a query' => ['https://matrix.example.org?x=1', ChatServerGuard::NO_QUERY],
    'a query after a slash' => ['https://matrix.example.org/?discovery=yes', ChatServerGuard::NO_QUERY],
    'a fragment' => ['https://matrix.example.org/#x', ChatServerGuard::NO_QUERY],
    'userinfo' => ['https://bot:pw@matrix.example.org', ChatServerGuard::NO_USERINFO],
    'no scheme' => ['matrix.example.org', ChatServerGuard::NEEDS_URL],
    'http in the cloud' => ['http://matrix.example.org', ChatServerGuard::HTTPS_ONLY],
    'a server name, not an address' => ['@bot:matrix.example.org', ChatServerGuard::NEEDS_URL],
    'a newline after the host' => ["https://matrix.example.org\n/", MatrixChannelType::HOMESERVER_MESSAGE],
]);

test('the fields cap the homeserver, the token and the room', function () {
    $rules = collect($this->type->formSchema())->mapWithKeys(fn ($field): array => [$field->getName() => $field->getValidationRules()]);

    expect($rules['homeserver'])->toContain('max:300')
        ->and($rules['access_token'])->toContain('max:512')
        ->and($rules['room'])->toContain('max:255')
        ->and(chanChatFieldValidate($this->type, 'access_token', str_repeat('a', 513))['passes'])->toBeFalse()
        ->and(chanChatFieldValidate($this->type, 'access_token', str_repeat('a', 512))['passes'])->toBeTrue()
        ->and(chanChatFieldValidate($this->type, 'room', '!'.str_repeat('a', 255))['passes'])->toBeFalse();
});

test('a homeserver over 300 characters is refused at the field and when building the destination', function () {
    $long = 'https://'.implode('.', array_fill(0, 6, str_repeat('a', 50))).'.example.org';
    expect(strlen($long))->toBeGreaterThan(300);

    expect(chanChatFieldValidate($this->type, 'homeserver', $long)['passes'])->toBeFalse()
        ->and(fn () => $this->type->buildDestination(chanMatrixFormData(['homeserver' => $long])))
        ->toThrow(InvalidArgumentException::class, MatrixChannelType::HOMESERVER_MESSAGE);
});

test('a homeserver spelled two ways is stored and keyed one way', function () {
    $tidy = $this->type->buildDestination(chanMatrixFormData())['config'];
    $loose = $this->type->buildDestination(chanMatrixFormData(['homeserver' => ' HTTPS://Matrix.Example.ORG.:443/ ', 'access_token' => ' '.CHAN_MX_OTHER_TOKEN.' ']))['config'];

    expect($loose)->toBe(['server' => 'https://matrix.example.org', 'token' => CHAN_MX_OTHER_TOKEN, 'room' => CHAN_MX_ROOM])
        ->and($this->type->destinationKey($loose))->toBe($this->type->destinationKey($tidy))
        ->and($this->type->destinationKey($tidy))->toBe('matrix.example.org:443/'.CHAN_MX_ROOM)
        ->and($this->type->destinationKey([...$tidy, 'room' => '!AbCdEfGhIjKlMn:EXAMPLE.org']))->toBe('matrix.example.org:443/'.CHAN_MX_ROOM)
        ->and($this->type->destinationKey([...$tidy, 'room' => '#Weather:Example.ORG']))->toBe('matrix.example.org:443/#weather:example.org')
        ->and($this->type->destinationKey([...$tidy, 'room' => '!abcdefghijklmn:example.org']))->not->toBe($this->type->destinationKey($tidy))
        ->and($this->type->destinationKey([...$tidy, 'server' => 'https://matrix.example.org:8448']))->toBe('matrix.example.org:8448/'.CHAN_MX_ROOM)
        ->and($this->type->destinationKey([...$tidy, 'server' => 'not a url']))->toBe('')
        ->and($this->type->serverUrl($tidy))->toBe('https://matrix.example.org')
        ->and($this->type->serverUrl(['token' => CHAN_MX_TOKEN, 'room' => CHAN_MX_ROOM]))->toBeNull();
});

test('http is allowed with matrix:// only where private networks are allowed', function () {
    config(['wxnotify.mode' => 'selfhost', 'wxnotify.webhooks.allow_private_networks' => true]);
    chanFakeChatDns(['synapse.lan.example' => ['192.168.1.20']]);

    $config = $this->type->buildDestination(chanMatrixFormData(['homeserver' => 'http://synapse.lan.example:8008']))['config'];

    expect($config['server'])->toBe('http://synapse.lan.example:8008')
        ->and($this->type->appriseUrl($config))->toBe('matrix://'.CHAN_MX_TOKEN.'@synapse.lan.example:8008/%21AbCdEfGhIjKlMn%3Aexample.org?'.CHAN_MX_QUERY);
});

test('completing the wizard as Plus posts the code', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = chanChatFormSubmit('matrix', chanMatrixFormData([
        'homeserver' => 'https://[2606:4700:0::1]:8448/',
        'access_token' => 'syt_abc/def+ghi=~._-',
        'room' => '#wx.alerts_co=1-a:example.org',
    ]));
    $component->assertHasNoFormErrors()->assertSee('We just posted a 6-digit code to your Matrix room.');

    $channel = NotificationChannel::where('user_id', $user->id)->sole();

    expect($channel->channel_type)->toBe('matrix')
        ->and($channel->apprise_url)->toBeNull()
        ->and($channel->label)->toBe('Matrix')
        ->and($channel->isVerified())->toBeFalse()
        ->and($channel->config)->toBe(['server' => 'https://[2606:4700::1]:8448', 'token' => 'syt_abc/def+ghi=~._-', 'room' => '#wx.alerts_co=1-a:example.org']);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['urls'] === 'matrixs://syt_abc%2Fdef%2Bghi%3D~._-@[2606:4700::1]:8448/%23wx.alerts_co%3D1-a%3Aexample.org?'.CHAN_MX_QUERY
        && ! array_key_exists('title', $request->data())
        && str_contains($request['body'], "Your WxNotify confirmation code is: {$channel->confirmation_code}"));
});

test('a homeserver that resolves privately is refused at save time', function (string $homeserver, array $dns, string $message) {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));
    Http::fake();
    chanFakeChatDns($dns);

    chanChatFormSubmit('matrix', chanMatrixFormData(['homeserver' => $homeserver]))->assertHasFormErrors(['homeserver']);

    expect(chanChatFieldValidate($this->type, 'homeserver', $homeserver)['errors'])->toContain($message)
        ->and(fn () => $this->type->buildDestination(chanMatrixFormData(['homeserver' => $homeserver])))
        ->toThrow(InvalidArgumentException::class, $message)
        ->and(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'a private answer' => ['https://matrix.example.org', ['matrix.example.org' => ['10.0.0.5']], ChatServerGuard::PRIVATE_NETWORK],
    'a loopback answer' => ['https://matrix.example.org', ['matrix.example.org' => ['127.0.0.1']], ChatServerGuard::PRIVATE_NETWORK],
    'the metadata address' => ['https://matrix.example.org', ['matrix.example.org' => ['169.254.169.254']], ChatServerGuard::PRIVATE_NETWORK],
    'an IPv4-mapped loopback answer' => ['https://matrix.example.org', ['matrix.example.org' => ['::ffff:7f00:1']], ChatServerGuard::PRIVATE_NETWORK],
    'a loopback literal' => ['https://127.0.0.1:8448', [], ChatServerGuard::PRIVATE_NETWORK],
    'an IPv6 loopback literal' => ['https://[::1]:8448', [], ChatServerGuard::PRIVATE_NETWORK],
    'an internal name' => ['https://synapse.internal', ['synapse.internal' => [CHAN_MX_PUBLIC_IP]], ChatServerGuard::PRIVATE_NETWORK],
    'a single-label name' => ['https://synapse', ['synapse' => [CHAN_MX_PUBLIC_IP]], ChatServerGuard::NEEDS_DOMAIN],
]);

test('a homeserver that resolves to a private address is refused at send time', function () {
    Http::fake();
    $channel = NotificationChannel::factory()->matrix()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();
    chanFakeChatDns(['matrix.example.org' => ['10.0.0.5']]);

    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe(ChatServerGuard::PRIVATE_NETWORK);
    Http::assertNothingSent();
});

test('a single-label homeserver is refused at send time too', function () {
    Http::fake();
    $channel = NotificationChannel::factory()->matrix(server: 'https://apprise')->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe(ChatServerGuard::NEEDS_DOMAIN);
    Http::assertNothingSent();
});

test('a stored token or room outside the allow-list gets no authority, so the transport refuses the send', function (string $key, string $value) {
    Http::fake();
    $config = ['server' => 'https://matrix.example.org', 'token' => CHAN_MX_TOKEN, 'room' => CHAN_MX_ROOM, $key => $value];

    expect($this->type->appriseUrl($config))->not->toContain('matrix.example.org');

    $channel = NotificationChannel::factory()->matrix(token: $config['token'], room: $config['room'])->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();
    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe(MessagingTransport::SERVER_MISMATCH);
    Http::assertNothingSent();
})->with([
    'a user target' => ['room', '@alice:example.org'],
    'a bare name' => ['room', 'weather'],
    'an alias with no server' => ['room', '#weather'],
    'a slash in the room' => ['room', '!a/b:example.org'],
    'two rooms' => ['room', '!a:example.org,#b:example.org'],
    'an @ in the token' => ['token', 'syt_a@evil.example'],
    'a colon in the token' => ['token', 'bot:password'],
    'a space in the token' => ['token', 'syt a'],
    'a token over 512 characters' => ['token', str_repeat('a', 513)],
]);

test('a 424 records the fixed Matrix text', function () {
    Http::fake(['*/notify/*' => Http::response(['error' => 'One or more notifications could not be sent'], 424)]);
    $channel = NotificationChannel::factory()->matrix()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('Matrix did not accept the message. Check the homeserver address, the access token and that the bot has joined the room.')
        ->and($this->type->failureMessage())->toBe($result->error);
});

test('the same room twice is refused even with another token', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $first = NotificationChannel::factory()->for($user)->matrix()->create();

    chanChatFormSubmit('matrix', chanMatrixFormData(['homeserver' => 'HTTPS://Matrix.Example.ORG.:443/', 'access_token' => CHAN_MX_OTHER_TOKEN, 'room' => '!AbCdEfGhIjKlMn:Example.ORG']))
        ->assertNotified('You already have a Matrix channel for that room.');

    expect(NotificationChannel::sole()->id)->toBe($first->id);
    Http::assertNothingSent();

    chanChatFormSubmit('matrix', chanMatrixFormData(['room' => '#weather:example.org']))
        ->assertNotNotified('You already have a Matrix channel for that room.');
    chanChatFormSubmit('matrix', chanMatrixFormData(['homeserver' => 'https://matrix.example.net']))
        ->assertNotNotified('You already have a Matrix channel for that room.');

    expect(NotificationChannel::count())->toBe(3);
});

test('the access token never appears in config, the activity log, the failure detail or the edit modal', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::sequence()
        ->push(status: 200)
        ->push(['error' => 'One or more notifications could not be sent'], 424)]);

    $channel = app(BeginChannelConnection::class)($user, $this->type, chanMatrixFormData());
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

    expect($raw)->not->toContain(CHAN_MX_TOKEN)
        ->and($log)->not->toContain(CHAN_MX_TOKEN)
        ->and(json_encode($failure->toArray()))->not->toContain(CHAN_MX_TOKEN)
        ->and($snapshot)->not->toContain(CHAN_MX_TOKEN)
        ->and($failure->status)->toBe('failure')
        ->and($failure->error_detail)->toBe($this->type->failureMessage())
        ->and($component->instance()->mountedActions[0]['data'])->not->toHaveKeys(['config', 'apprise_url', 'confirmation_code']);
});

test('the type is a dashboard-only messaging type that shows the room and fixed texts', function () {
    $channel = NotificationChannel::factory()->matrix(room: '#weather:example.org')->create();

    expect($this->type->slug())->toBe('matrix')
        ->and($this->type->label())->toBe('Matrix')
        ->and($this->type->icon())->toBe('fab-matrix')
        ->and($this->type->transport())->toBe(ChannelTransport::Messaging)
        ->and($this->type->maxLength())->toBe(4000)
        ->and($this->type->requiredConfigKeys())->toBe(['server', 'token', 'room'])
        ->and($this->type->destinationNoun())->toBe('room')
        ->and($this->type->supportsInlineCreate())->toBeFalse()
        ->and($this->type->appSetupFields(User::factory()->make()))->toBeNull()
        ->and($this->type->countsTowardFreeSlot())->toBeFalse()
        ->and($this->type->displaySummary($channel))->toBe('#weather:example.org')
        ->and($this->type->displaySummary(NotificationChannel::factory()->make(['channel_type' => 'matrix', 'apprise_url' => null, 'config' => []])))->toBe('—')
        ->and($this->type->destinationLabel())->toBe('Matrix room')
        ->and($this->type->confirmationHint($channel))->toBe('We just posted a 6-digit code to your Matrix room.')
        ->and($this->type->codeNotSentHint())->toBe('We couldn\'t post to that room. Check the homeserver address and access token, and that the bot has joined the room.');
});

test('the wizard step tells the subscriber how to set the bot up', function () {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'matrix']);

    goToConnectChannelStep($component, 0)
        ->assertSee('On matrix.org, enter https://matrix-client.matrix.org.')
        ->assertSee('Invite the bot account to the room and join it as the bot.')
        ->assertSee('The room must already exist')
        ->assertSee('Use an unencrypted room: WxNotify does not encrypt messages, and in an encrypted room they show as unencrypted or may be hidden.')
        ->assertSee('Settings → Help & About → Advanced → Access Token');
});

test('the API refuses to create a matrix channel', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    Http::fake();

    $this->withToken($user->createToken('Pixel 9', ['app'])->plainTextToken)
        ->postJson('/api/v1/channels', ['channel_type' => 'matrix', ...chanMatrixFormData()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['channel_type']);

    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
});
