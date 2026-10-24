<?php

use App\Actions\Channels\BeginChannelConnection;
use App\Actions\Channels\SendTestNotification;
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
use App\Support\ChannelTypes\PagerDutyChannelType;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\Transports\MessagingTransport;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Models\Activity;
use ToneGabes\BetterOptions\Forms\Components\RadioCards;

use function Pest\Livewire\livewire;

const CHAN_PD_KEY = 'e93facc04764012d7bfb002500d5d1a6';

const CHAN_PD_RULESET_KEY = 'R0ABCDEFGHIJKLMNOPQRSTUVWXYZ0123';

const CHAN_PD_URL_SPECIALS = ['/', '@', ':', '?', '#', '%', ' ', '&', '=', '+', ';', ',', '~', '!', '$', "'", '(', '*', '[', '\\', 'é', "\n", "\t", "\0"];

beforeEach(function () {
    $this->type = new PagerDutyChannelType;
});

/**
 * The exact Apprise URL a PagerDuty channel sends with.
 */
function chanPagerDutyUrl(string $key = CHAN_PD_KEY, string $severity = 'error', string $source = 'WxNotify', string $component = 'weather-alert'): string
{
    return "pagerduty://{$key}@wxnotify/{$source}/{$component}?redirect=no&cto=5&rto=10&region=us&severity={$severity}&image=no";
}

/**
 * Whether one of the PagerDuty fields accepts the value by its own rules, and its errors.
 *
 * @return array{passes: bool, errors: list<string>}
 */
function chanPagerDutyValidate(PagerDutyChannelType $type, string $name, mixed $value): array
{
    $field = collect($type->formSchema())->keyBy(fn ($component) => $component->getName())[$name];
    $validator = Validator::make([$name => $value], [$name => $field->getValidationRules()]);

    return ['passes' => $validator->passes(), 'errors' => $validator->errors()->get($name)];
}

/**
 * Every URL-special character, raw and percent-encoded, spliced into a value.
 *
 * @return array<string, string>
 */
function chanPagerDutySpecials(string $where, Closure $splice): array
{
    $cases = [];

    foreach (CHAN_PD_URL_SPECIALS as $character) {
        $cases["{$where}: raw ".json_encode($character)] = $splice($character);
        $cases["{$where}: encoded ".rawurlencode($character)] = $splice(rawurlencode($character));
    }

    return $cases;
}

test('it builds the config from the form and the type has the messaging flags', function () {
    $destination = $this->type->buildDestination(['integration_key' => '  '.CHAN_PD_KEY.' ', 'severity' => 'critical', 'source' => 'ops.wx-1', 'component' => 'storm_desk']);

    expect($destination)->toBe([
        'config' => ['integration_key' => CHAN_PD_KEY, 'severity' => 'critical', 'source' => 'ops.wx-1', 'component' => 'storm_desk'],
        'label' => 'PagerDuty',
    ])
        ->and($this->type->appriseUrl($destination['config']))->toBe(chanPagerDutyUrl(severity: 'critical', source: 'ops.wx-1', component: 'storm_desk'))
        ->and($this->type->serverUrl($destination['config']))->toBeNull()
        ->and($this->type->requiredConfigKeys())->toBe(['integration_key', 'severity', 'source', 'component'])
        ->and($this->type->transport())->toBe(ChannelTransport::Messaging)
        ->and($this->type->slug())->toBe('pagerduty')
        ->and($this->type->label())->toBe('PagerDuty')
        ->and($this->type->icon())->toBe('heroicon-o-megaphone')
        ->and($this->type->maxLength())->toBe(1000)
        ->and($this->type->supportsInlineCreate())->toBeFalse()
        ->and($this->type->appSetupFields(User::factory()->make()))->toBeNull()
        ->and($this->type->supportsRichContent())->toBeFalse()
        ->and($this->type->countsTowardFreeSlot())->toBeFalse()
        ->and($this->type->isAvailable())->toBeTrue();
});

test('a blank source, component or severity gets the defaults', function () {
    $config = $this->type->buildDestination(['integration_key' => CHAN_PD_RULESET_KEY, 'severity' => null, 'source' => '  ', 'component' => null])['config'];

    expect($config)->toBe(['integration_key' => CHAN_PD_RULESET_KEY, 'severity' => 'error', 'source' => 'WxNotify', 'component' => 'weather-alert'])
        ->and($this->type->appriseUrl($config))->toBe(chanPagerDutyUrl(CHAN_PD_RULESET_KEY));

    foreach (['severity' => 'error', 'source' => null, 'component' => null] as $name => $default) {
        $field = collect($this->type->formSchema())->keyBy(fn ($component) => $component->getName())[$name];
        expect($field->getDefaultState())->toBe($default);
    }
});

test('the integration key must be 32 letters and digits', function (string $key) {
    $message = PagerDutyChannelType::INVALID_KEY_MESSAGE;

    expect(chanPagerDutyValidate($this->type, 'integration_key', $key)['passes'])->toBeFalse()
        ->and(chanPagerDutyValidate($this->type, 'integration_key', $key)['errors'])->toContain($message)
        ->and(fn () => $this->type->buildDestination(['integration_key' => $key, 'severity' => 'error']))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'too short' => substr(CHAN_PD_KEY, 0, 31),
    'too long' => CHAN_PD_KEY.'a',
    'a url' => 'https://events.pagerduty.com/v2/enqueue',
    'a newline after it' => CHAN_PD_KEY."\nx",
    ...chanPagerDutySpecials('last', fn (string $character): string => substr(CHAN_PD_KEY, 0, 31).$character),
    ...chanPagerDutySpecials('first', fn (string $character): string => $character.substr(CHAN_PD_KEY, 1)),
]);

test('the source and component allow only letters, digits, dots, dashes and underscores', function (string $name, string $value) {
    $message = PagerDutyChannelType::INVALID_NAME_MESSAGE;

    expect(chanPagerDutyValidate($this->type, $name, $value)['passes'])->toBeFalse()
        ->and(chanPagerDutyValidate($this->type, $name, $value)['errors'])->toContain($message)
        ->and(fn () => $this->type->buildDestination(['integration_key' => CHAN_PD_KEY, 'severity' => 'error', $name => $value]))
        ->toThrow(InvalidArgumentException::class, $message);
})->with(['source', 'component'])->with([
    'a dot' => '.',
    'two dots' => '..',
    'too long' => str_repeat('a', 65),
    'a newline after it' => "ops\nx",
    ...chanPagerDutySpecials('inside', fn (string $character): string => 'ops'.$character.'desk'),
]);

test('the allowed characters reach the apprise url unencoded', function () {
    expect(chanPagerDutyValidate($this->type, 'source', 'a.B_c-9')['passes'])->toBeTrue()
        ->and(chanPagerDutyValidate($this->type, 'component', str_repeat('z', 64))['passes'])->toBeTrue()
        ->and(chanPagerDutyValidate($this->type, 'integration_key', CHAN_PD_RULESET_KEY)['passes'])->toBeTrue();

    $config = $this->type->buildDestination(['integration_key' => CHAN_PD_RULESET_KEY, 'severity' => 'warning', 'source' => 'a.B_c-9', 'component' => '..x'])['config'];

    expect($this->type->appriseUrl($config))->toBe(chanPagerDutyUrl(CHAN_PD_RULESET_KEY, 'warning', 'a.B_c-9', '..x'));
});

test('the severity must be one of PagerDuty\'s four', function (string $severity) {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));
    Http::fake();

    chanChatFormSubmit('pagerduty', ['integration_key' => CHAN_PD_KEY, 'severity' => $severity])
        ->assertHasFormErrors(['severity']);

    expect(fn () => $this->type->buildDestination(['integration_key' => CHAN_PD_KEY, 'severity' => $severity]))
        ->toThrow(InvalidArgumentException::class, PagerDutyChannelType::INVALID_SEVERITY_MESSAGE)
        ->and(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
})->with(['crit', 'Critical', 'debug', 'error&region=eu', 'error ']);

test('an alert pages with the chosen severity, the confirmation code and a test always as info', function (string $severity) {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->pagerDuty(severity: $severity)->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();
    $dispatcher = app(ChannelDispatcher::class);

    expect($dispatcher->send($channel, 'Tornado Warning', 'Alert')->success)->toBeTrue()
        ->and($dispatcher->send($channel, 'Your WxNotify confirmation code is: 123456', 'Confirm', ['type' => 'confirmation', 'code' => '123456'])->success)->toBeTrue()
        ->and(app(SendTestNotification::class)($channel))->toBeTrue();

    expect(collect(Http::recorded())->map(fn (array $pair): string => $pair[0]['urls'])->all())->toBe([
        chanPagerDutyUrl(severity: $severity),
        chanPagerDutyUrl(severity: 'info'),
        chanPagerDutyUrl(severity: 'info'),
    ]);
})->with(['info', 'warning', 'error', 'critical']);

test('the url always names the US region, and a stored region is never passed through', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->pagerDuty()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();
    $channel->config = [...$channel->config, 'region' => 'eu'];
    $channel->save();

    expect($this->type->appriseUrl($channel->config))->toBe(chanPagerDutyUrl())
        ->and(collect($this->type->formSchema())->map(fn ($component) => $component->getName())->all())->not->toContain('region')
        ->and(app(ChannelDispatcher::class)->send($channel->fresh(), 'Tornado Warning', 'Alert')->success)->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request['urls'] === chanPagerDutyUrl()
        && substr_count($request['urls'], 'region=') === 1
        && ! str_contains($request['urls'], 'eu'));
});

test('a tampered stored value gives no url and nothing is sent', function (string $key, string $value) {
    Http::fake();
    $channel = NotificationChannel::factory()->pagerDuty()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();
    $channel->config = [...$channel->config, $key => $value];

    expect($this->type->appriseUrl($channel->config))->toBe('')
        ->and(app(MessagingTransport::class)->send($channel, 'Tornado Warning', 'Alert')->error)->toBe('This channel is missing its settings.')
        ->and(app(MessagingTransport::class)->send($channel, 'code', null, ['type' => 'confirmation'])->error)->toBe('This channel is missing its settings.');

    Http::assertNothingSent();
})->with([
    'a slash in the key' => ['integration_key', 'e93facc04764012d7bfb002500d5d1/6'],
    'an encoded slash in the key' => ['integration_key', 'e93facc04764012d7bfb002500d%2F6'],
    'an at sign in the key' => ['integration_key', 'e93facc04764012d7bfb002500d5d@a6'],
    'a short key' => ['integration_key', 'e93facc0'],
    'a newline after the key' => ['integration_key', CHAN_PD_KEY."\n"],
    'a query in the source' => ['source', 'ops?region=eu'],
    'an encoded slash in the source' => ['source', 'a%2Fb'],
    'a dot-only source' => ['source', '..'],
    'a hash in the component' => ['component', 'a#b'],
    'a slash in the component' => ['component', 'a/b'],
    'an unknown severity' => ['severity', 'error&region=eu'],
    'a prefix severity' => ['severity', 'crit'],
]);

test('completing the wizard as Plus stores config only and opens the code as an info incident', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'pagerduty']);
    goToConnectChannelStep($component, 0);
    $component->assertWizardCurrentStep(2)
        ->assertSchemaStateSet(['severity' => 'error'])
        ->assertSee('US-region PagerDuty accounts only; EU accounts are not supported.')
        ->assertSee('The confirmation code and test messages are always sent as Info.')
        ->fillForm(['integration_key' => CHAN_PD_KEY]);
    goToConnectChannelStep($component, 1);
    $component->assertWizardCurrentStep(3)
        ->assertSee('We just sent a 6-digit code to your PagerDuty service. It opens an incident there: read the code from it, then resolve the incident.');

    $channel = NotificationChannel::where('user_id', $user->id)->sole();

    expect($channel->channel_type)->toBe('pagerduty')
        ->and($channel->apprise_url)->toBeNull()
        ->and($channel->label)->toBe('PagerDuty')
        ->and($channel->isVerified())->toBeFalse()
        ->and($channel->config)->toBe(['integration_key' => CHAN_PD_KEY, 'severity' => 'error', 'source' => 'WxNotify', 'component' => 'weather-alert']);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['urls'] === chanPagerDutyUrl(severity: 'info')
        && ! array_key_exists('title', $request->data())
        && ! array_key_exists('format', $request->data())
        && str_contains($request['body'], "Your WxNotify confirmation code is: {$channel->confirmation_code}"));

    livewire(ConnectChannel::class)
        ->fillForm(['channel_id' => $channel->id, 'confirmation_code' => $channel->confirmation_code])
        ->call('connect');

    expect($channel->fresh()->isVerified())->toBeTrue();
});

test('the wizard shows the field\'s own error for a bad key and creates nothing', function () {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));
    Http::fake();

    chanChatFormSubmit('pagerduty', ['integration_key' => 'not-a-key', 'severity' => 'error'])
        ->assertHasFormErrors(['integration_key']);

    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
});

test('a second channel for the same integration key is refused in any spelling', function (string $spelling) {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    NotificationChannel::factory()->for($user)->pagerDuty(CHAN_PD_RULESET_KEY)->create();

    expect(fn () => app(BeginChannelConnection::class)($user, $this->type, ['integration_key' => $spelling, 'severity' => 'critical', 'source' => 'other']))
        ->toThrow(InvalidArgumentException::class, 'You already have a PagerDuty channel for that integration key.');

    app(BeginChannelConnection::class)($user, $this->type, ['integration_key' => CHAN_PD_KEY, 'severity' => 'error']);

    expect(NotificationChannel::count())->toBe(2)
        ->and($this->type->destinationKey(['integration_key' => CHAN_PD_RULESET_KEY]))->toBe(strtolower(CHAN_PD_RULESET_KEY));
    Http::assertSentCount(1);
})->with([
    'as stored' => CHAN_PD_RULESET_KEY,
    'lower case' => strtolower(CHAN_PD_RULESET_KEY),
    'padded' => '  '.CHAN_PD_RULESET_KEY."\t",
]);

test('a Free subscriber sees the tile disabled and is refused on a forced nextStep', function () {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Free]));
    Http::fake();

    $page = livewire(ConnectChannel::class)->instance();
    $tile = $page->{$page->getDefaultTestingSchemaName()}->getComponent(fn ($component) => $component instanceof RadioCards);

    expect($tile->getOptions())->toHaveKey('pagerduty')
        ->and($tile->isOptionDisabled('pagerduty', 'PagerDuty'))->toBeTrue();

    chanChatFormSubmit('pagerduty', ['integration_key' => CHAN_PD_KEY, 'severity' => 'error'])
        ->assertNotified('Your plan can\'t add that channel');

    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
});

test('a downgraded subscriber\'s channel is kept and refused with the tier text before any HTTP', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $user = User::factory()->create();
    $channel = NotificationChannel::factory()->for($user)->pagerDuty()->create();

    expect(app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert'))
        ->success->toBeFalse()->error->toBe(ChannelDispatcher::TIER_REFUSAL)
        ->and($channel->fresh())->not->toBeNull();
    Http::assertNothingSent();
});

test('a 424 records the fixed PagerDuty text and the wizard says the code was not sent', function () {
    Http::fake(['*/notify/*' => Http::response(['error' => 'One or more notifications could not be sent'], 424)]);
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $channel = NotificationChannel::factory()->pagerDuty()->for($user)->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('PagerDuty did not accept the event. Check the integration key, and that it is an Events API v2 key on a US-region PagerDuty account.')
        ->and($this->type->failureMessage())->toBe($result->error);

    $this->actingAs($user);
    chanChatFormSubmit('pagerduty', ['integration_key' => CHAN_PD_RULESET_KEY, 'severity' => 'error'])
        ->assertNotified(Notification::make()
            ->title('Couldn\'t send your confirmation code')
            ->body('We couldn\'t send to that PagerDuty integration. Check the integration key, and that it is an Events API v2 integration on a US-region PagerDuty account.')
            ->danger());
    expect(NotificationChannel::count())->toBe(1);
});

test('an Apprise connection error propagates from the transport without the key', function () {
    Http::fake(['*/notify/*' => Http::failedConnection()]);
    $channel = NotificationChannel::factory()->pagerDuty()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();

    try {
        app(ChannelDispatcher::class)->send($channel, 'Tornado Warning');
        $this->fail('Expected a ConnectionException.');
    } catch (ConnectionException $exception) {
        expect($exception->getMessage())->not->toContain(CHAN_PD_KEY)
            ->and((string) $exception)->not->toContain(CHAN_PD_KEY);
    }
});

test('an alert is the chat text capped at 1000 characters with the link whole, sent with no title or format', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->pagerDuty()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();
    $link = 'https://wx.example.com/view-product/kbou-a_b_c';

    app(ChannelDispatcher::class)->send($channel, "Tornado Warning for\nBoulder County. *bold* & <b>", 'Tornado Warning', ['url' => $link]);
    app(ChannelDispatcher::class)->send($channel, str_repeat('Severe storms moving east. ', 80), 'Severe Thunderstorm Warning', ['url' => $link]);

    $bodies = collect(Http::recorded())->map(fn (array $pair): array => $pair[0]->data());

    expect($bodies[0])->toBe(['urls' => chanPagerDutyUrl(), 'body' => "Tornado Warning\n\nTornado Warning for\nBoulder County. *bold* & <b>\n\n{$link}"])
        ->and(mb_strlen($bodies[1]['body']))->toBeLessThanOrEqual(1000)->toBeGreaterThan(950)
        ->and($bodies[1]['body'])->toStartWith("Severe Thunderstorm Warning\n\n")->toEndWith("...\n\n{$link}")
        ->and($bodies[1])->not->toHaveKeys(['title', 'format']);
});

test('the integration key never appears outside the encrypted config', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });
    Http::fake(['*/notify/*' => Http::sequence()
        ->push(status: 200)
        ->push(['error' => 'One or more notifications could not be sent'], 424)]);

    $channel = app(BeginChannelConnection::class)($user, $this->type, ['integration_key' => CHAN_PD_KEY, 'severity' => 'error']);
    $channel->forceFill(['verified_at' => now()])->save();
    $channel->update(['label' => 'Storm desk']);

    $rule = AlertRule::factory()->for($user)->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $product = IngestedProduct::factory()->create(['text' => 'Tornado Warning for Boulder County.']);
    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(ChannelDispatcher::class));
    $failure = DeliveryLog::sole();

    $component = livewire(ListNotificationChannels::class)
        ->mountAction(TestAction::make('edit')->table($channel))
        ->assertSchemaComponentHidden('apprise_url_preview');
    $api = $this->withToken($user->createToken('Pixel 9', ['app'])->plainTextToken)->getJson('/api/v1/channels')->assertOk();

    $places = [
        'apprise_url' => (string) $channel->fresh()->apprise_url,
        'activity log' => json_encode(Activity::all()->toArray()),
        'delivery log' => json_encode($failure->toArray()),
        'edit modal' => json_encode($component->snapshot),
        'api' => $api->getContent(),
        'logs' => implode("\n", $logged),
    ];

    foreach ($places as $place => $content) {
        expect(str_contains(strtolower($content), CHAN_PD_KEY))->toBeFalse("the key leaked into the {$place}");
    }

    expect($failure->status)->toBe('failure')
        ->and($failure->error_detail)->toBe($this->type->failureMessage())
        ->and($api->json('data.0.destination'))->toBe('••••d1a6')
        ->and($component->instance()->mountedActions[0]['data'])->not->toHaveKeys(['config', 'apprise_url', 'confirmation_code']);
});

test('the summary masks the key and the texts are fixed', function () {
    $channel = NotificationChannel::factory()->pagerDuty()->create();
    $help = PagerDutyChannelType::KEY_HELP;

    expect($this->type->displaySummary($channel))->toBe('••••d1a6')
        ->and($this->type->displaySummary(NotificationChannel::factory()->make(['channel_type' => 'pagerduty', 'apprise_url' => null, 'config' => []])))->toBe('—')
        ->and($this->type->destinationLabel())->toBe('PagerDuty service')
        ->and($this->type->destinationNoun())->toBe('integration key')
        ->and((string) $help)->toContain('Events API V2')
        ->toContain('US-region PagerDuty accounts only; EU accounts are not supported.')
        ->toContain('Every message opens its own incident, including the confirmation code and test messages');
});

test('the API refuses to create a pagerduty channel', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    Http::fake();

    $this->withToken($user->createToken('Pixel 9', ['app'])->plainTextToken)
        ->postJson('/api/v1/channels', ['channel_type' => 'pagerduty', 'integration_key' => CHAN_PD_KEY])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['channel_type']);

    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
});
