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
use App\Settings\GeneralSettings;
use App\Support\ChannelTypes\IftttChannelType;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\Transports\MessagingTransport;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Models\Activity;
use ToneGabes\BetterOptions\Forms\Components\RadioCards;

use function Pest\Livewire\livewire;

const CHAN_IFTTT_KEY = 'a3nHB7gA9TfBQSqJAHk-od';

const CHAN_IFTTT_LONG_KEY = 'dK_9xQ2-mN4pR6sT8vW0yZ1bC3eF5hJ7kL9nP2qS4uV';

const CHAN_IFTTT_EVENT = 'wxnotify_alert';

const CHAN_IFTTT_URL_SPECIALS = ['/', '@', ':', '?', '#', '%', ' ', '&', '=', '+', ';', ',', '~', '!', '$', "'", '(', '*', '[', '\\', '.', 'é', "\n", "\t", "\0"];

beforeEach(function () {
    $this->type = new IftttChannelType;
});

/**
 * The exact Apprise URL an IFTTT channel sends with.
 */
function chanIftttUrl(string $key = CHAN_IFTTT_KEY, string $event = CHAN_IFTTT_EVENT): string
{
    return "ifttt://{$key}/{$event}?redirect=no&cto=5&rto=10";
}

/**
 * Whether one of the IFTTT fields accepts the value by its own rules, and its errors.
 *
 * @return array{passes: bool, errors: list<string>}
 */
function chanIftttValidate(IftttChannelType $type, string $name, mixed $value): array
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
function chanIftttSpecials(string $where, Closure $splice): array
{
    $cases = [];

    foreach (CHAN_IFTTT_URL_SPECIALS as $character) {
        $cases["{$where}: raw ".json_encode($character)] = $splice($character);
        $cases["{$where}: encoded ".rawurlencode($character)] = $splice(rawurlencode($character));
    }

    return $cases;
}

/**
 * The Apprise requests sent so far, as their JSON data.
 *
 * @return list<array<string, mixed>>
 */
function chanIftttSent(): array
{
    return collect(Http::recorded())->map(fn (array $pair): array => $pair[0]->data())->values()->all();
}

test('it builds the config from the form and the type has the messaging flags', function () {
    $destination = $this->type->buildDestination(['webhooks_key' => '  '.CHAN_IFTTT_KEY.' ', 'event' => ' Storm_Desk_2 ']);

    expect($destination)->toBe([
        'config' => ['webhooks_key' => CHAN_IFTTT_KEY, 'event' => 'Storm_Desk_2'],
        'label' => 'IFTTT',
    ])
        ->and($this->type->appriseUrl($destination['config']))->toBe(chanIftttUrl(event: 'Storm_Desk_2'))
        ->and($this->type->serverUrl($destination['config']))->toBeNull()
        ->and($this->type->requiredConfigKeys())->toBe(['webhooks_key', 'event'])
        ->and($this->type->transport())->toBe(ChannelTransport::Messaging)
        ->and($this->type->slug())->toBe('ifttt')
        ->and($this->type->label())->toBe('IFTTT')
        ->and($this->type->icon())->toBe('heroicon-o-puzzle-piece')
        ->and($this->type->maxLength())->toBe(1000)
        ->and($this->type->supportsInlineCreate())->toBeFalse()
        ->and($this->type->appSetupFields(User::factory()->make()))->toBeNull()
        ->and($this->type->supportsRichContent())->toBeFalse()
        ->and($this->type->countsTowardFreeSlot())->toBeFalse()
        ->and($this->type->isAvailable())->toBeTrue();
});

test('a blank event gets the default and the key field is a revealable password', function () {
    $config = $this->type->buildDestination(['webhooks_key' => CHAN_IFTTT_LONG_KEY, 'event' => '  '])['config'];
    $fields = collect($this->type->formSchema())->keyBy(fn ($component) => $component->getName());

    expect($config)->toBe(['webhooks_key' => CHAN_IFTTT_LONG_KEY, 'event' => 'wxnotify_alert'])
        ->and($this->type->appriseUrl($config))->toBe(chanIftttUrl(CHAN_IFTTT_LONG_KEY))
        ->and($fields->keys()->all())->toBe(['webhooks_key', 'event'])
        ->and($fields['webhooks_key']->isPassword())->toBeTrue()
        ->and($fields['webhooks_key']->isPasswordRevealable())->toBeTrue()
        ->and($fields['webhooks_key']->isRequired())->toBeTrue()
        ->and($fields['event']->isRequired())->toBeFalse();
});

test('the webhooks key allows only letters, digits, dashes and underscores', function (string $key) {
    $message = IftttChannelType::INVALID_KEY_MESSAGE;

    expect(chanIftttValidate($this->type, 'webhooks_key', $key)['passes'])->toBeFalse()
        ->and(chanIftttValidate($this->type, 'webhooks_key', $key)['errors'])->toContain($message)
        ->and(fn () => $this->type->buildDestination(['webhooks_key' => $key, 'event' => CHAN_IFTTT_EVENT]))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'too short' => 'a3nHB7gA9TfBQSq',
    'too long' => str_repeat('a', 65),
    'the whole url' => 'https://maker.ifttt.com/use/'.CHAN_IFTTT_KEY,
    'a newline after it' => CHAN_IFTTT_KEY."\nx",
    ...chanIftttSpecials('inside', fn (string $character): string => 'a3nHB7gA9T'.$character.'fBQSqJAHkod'),
]);

test('the event name allows only letters, digits and underscores', function (string $event) {
    $message = IftttChannelType::INVALID_EVENT_MESSAGE;

    expect(chanIftttValidate($this->type, 'event', $event)['passes'])->toBeFalse()
        ->and(chanIftttValidate($this->type, 'event', $event)['errors'])->toContain($message)
        ->and(fn () => $this->type->buildDestination(['webhooks_key' => CHAN_IFTTT_KEY, 'event' => $event]))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'too long' => str_repeat('e', 65),
    'a dash' => 'wx-alert',
    'a newline after it' => "wx\nalert",
    ...chanIftttSpecials('inside', fn (string $character): string => 'wx'.$character.'alert'),
]);

test('the allowed characters reach the apprise url unencoded and in their own case', function () {
    expect(chanIftttValidate($this->type, 'webhooks_key', '_-'.str_repeat('Z9', 7))['passes'])->toBeTrue()
        ->and(chanIftttValidate($this->type, 'webhooks_key', str_repeat('k', 64))['passes'])->toBeTrue()
        ->and(chanIftttValidate($this->type, 'event', 'A_1')['passes'])->toBeTrue()
        ->and(chanIftttValidate($this->type, 'event', str_repeat('e', 64))['passes'])->toBeTrue();

    $config = $this->type->buildDestination(['webhooks_key' => CHAN_IFTTT_LONG_KEY, 'event' => 'Front_Door_9'])['config'];

    expect($this->type->appriseUrl($config))->toBe(chanIftttUrl(CHAN_IFTTT_LONG_KEY, 'Front_Door_9'));
});

test('a tampered stored value gives no url and nothing is sent', function (string $key, string $value) {
    Http::fake();
    $channel = NotificationChannel::factory()->ifttt()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();
    $channel->config = [...$channel->config, $key => $value];

    expect($this->type->appriseUrl($channel->config))->toBe('')
        ->and(app(MessagingTransport::class)->send($channel, 'Tornado Warning', 'Alert')->error)->toBe('This channel is missing its settings.')
        ->and(app(MessagingTransport::class)->send($channel, 'code', 'Confirm', ['type' => 'confirmation'])->error)->toBe('This channel is missing its settings.');

    Http::assertNothingSent();
})->with([
    'a slash in the key' => ['webhooks_key', 'a3nHB7gA9TfBQSq/AHk-od'],
    'an encoded slash in the key' => ['webhooks_key', 'a3nHB7gA9TfBQSq%2FAHk-od'],
    'a question mark in the key' => ['webhooks_key', 'a3nHB7gA9TfBQSq?AHk-od'],
    'an at sign in the key' => ['webhooks_key', 'a3nHB7gA9TfBQSq@AHk-od'],
    'a short key' => ['webhooks_key', 'a3nHB7'],
    'a newline after the key' => ['webhooks_key', CHAN_IFTTT_KEY."\n"],
    'a comma in the event' => ['event', 'a,b'],
    'a slash in the event' => ['event', 'a/b'],
    'an encoded slash in the event' => ['event', 'a%2Fb'],
    'a hash in the event' => ['event', 'a#b'],
    'an encoded query in the event' => ['event', 'a%3Fb'],
    'a space in the event' => ['event', 'a b'],
    'a newline after the event' => ['event', "wxnotify_alert\n"],
]);

test('completing the wizard as Plus stores config only and fires the code as value2 with a title for value1', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'ifttt']);
    goToConnectChannelStep($component, 0);
    $component->assertWizardCurrentStep(2)
        ->assertSee('If Webhooks receives a web request')
        ->assertSee('wxnotify_alert')
        ->fillForm(['webhooks_key' => CHAN_IFTTT_KEY, 'event' => 'storm_desk']);
    goToConnectChannelStep($component, 1);
    $component->assertWizardCurrentStep(3)
        ->assertSee('We just triggered your IFTTT event with a 6-digit code as Value2.');

    $channel = NotificationChannel::where('user_id', $user->id)->sole();

    expect($channel->channel_type)->toBe('ifttt')
        ->and($channel->apprise_url)->toBeNull()
        ->and($channel->label)->toBe('IFTTT')
        ->and($channel->isVerified())->toBeFalse()
        ->and($channel->config)->toBe(['webhooks_key' => CHAN_IFTTT_KEY, 'event' => 'storm_desk']);

    expect(chanIftttSent())->toBe([[
        'urls' => chanIftttUrl(event: 'storm_desk'),
        'body' => "Your WxNotify confirmation code is: {$channel->confirmation_code}\n\nEnter this code in the dashboard to finish connecting this channel. It expires in 15 minutes.",
        'title' => 'Confirm your WxNotify channel',
    ]]);

    livewire(ConnectChannel::class)
        ->fillForm(['channel_id' => $channel->id, 'confirmation_code' => $channel->confirmation_code])
        ->call('connect');

    expect($channel->fresh()->isVerified())->toBeTrue();
});

test('a test and an alert send the title as value1 and the summary with the whole link as value2', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->ifttt()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();
    $link = 'https://wx.example.com/view-product/kbou-a_b_c';

    expect(app(SendTestNotification::class)($channel))->toBeTrue()
        ->and(app(ChannelDispatcher::class)->send($channel, "Tornado Warning for\nBoulder County. *bold* & <b>", 'Tornado Warning', ['url' => $link])->success)->toBeTrue();

    expect(chanIftttSent())->toBe([
        [
            'urls' => chanIftttUrl(),
            'body' => 'This is a test notification from WxNotify. If you received this, "IFTTT" is working correctly.',
            'title' => 'WxNotify test notification',
        ],
        [
            'urls' => chanIftttUrl(),
            'body' => "Tornado Warning for\nBoulder County. *bold* & <b>\n\n{$link}",
            'title' => 'Tornado Warning',
        ],
    ]);
});

test('value1 keeps the admin message prefix', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $settings = app(GeneralSettings::class);
    $settings->message_prefix_enabled = true;
    $settings->message_prefix = '[WX]';
    $settings->save();
    $channel = NotificationChannel::factory()->ifttt()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();

    app(ChannelDispatcher::class)->send($channel, 'Tornado Warning for Boulder County.', 'Tornado Warning');

    expect(chanIftttSent()[0])->toBe(['urls' => chanIftttUrl(), 'body' => 'Tornado Warning for Boulder County.', 'title' => '[WX] Tornado Warning']);
});

test('value2 is capped at 1000 characters with the link whole, and value1 at 250 on one line', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->ifttt()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();
    $link = 'https://wx.example.com/view-product/kbou-a_b_c';

    app(ChannelDispatcher::class)->send($channel, str_repeat('Severe storms moving east. ', 80), "Severe Thunderstorm\nWarning ".str_repeat('long ', 80), ['url' => $link]);
    app(ChannelDispatcher::class)->send($channel, 'Flood Watch.', null, ['url' => 'https://wx.example.com/view-product/'.str_repeat('x', 1000)]);

    [$long, $untitled] = chanIftttSent();

    expect(mb_strlen($long['body']))->toBeLessThanOrEqual(1000)->toBeGreaterThan(950)
        ->and($long['body'])->toStartWith('Severe storms moving east.')->toEndWith("...\n\n{$link}")
        ->and(mb_strlen($long['title']))->toBeLessThanOrEqual(250)->toBeGreaterThan(240)
        ->and($long['title'])->toStartWith('Severe Thunderstorm Warning long')->toEndWith('...')
        ->and($long)->not->toHaveKey('format')
        ->and($untitled)->toBe(['urls' => chanIftttUrl(), 'body' => 'Flood Watch.', 'title' => 'WxNotify']);
});

test('a second channel for the same key and event is refused, other events and keys in another case are not', function (string $key, string $event) {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    NotificationChannel::factory()->for($user)->ifttt()->create();

    expect(fn () => app(BeginChannelConnection::class)($user, $this->type, ['webhooks_key' => $key, 'event' => $event]))
        ->toThrow(InvalidArgumentException::class, 'You already have a IFTTT channel for that Webhooks key and event.');

    app(BeginChannelConnection::class)($user, $this->type, ['webhooks_key' => CHAN_IFTTT_KEY, 'event' => 'other_event']);
    app(BeginChannelConnection::class)($user, $this->type, ['webhooks_key' => CHAN_IFTTT_KEY, 'event' => 'WxNotify_Alert']);
    app(BeginChannelConnection::class)($user, $this->type, ['webhooks_key' => strtoupper(CHAN_IFTTT_KEY), 'event' => CHAN_IFTTT_EVENT]);

    expect(NotificationChannel::count())->toBe(4)
        ->and($this->type->destinationKey(['webhooks_key' => ' '.CHAN_IFTTT_KEY, 'event' => CHAN_IFTTT_EVENT.' ']))->toBe(CHAN_IFTTT_KEY.'/'.CHAN_IFTTT_EVENT);
    Http::assertSentCount(3);
})->with([
    'as stored' => [CHAN_IFTTT_KEY, CHAN_IFTTT_EVENT],
    'padded' => ['  '.CHAN_IFTTT_KEY."\t", ' '.CHAN_IFTTT_EVENT.' '],
    'blank event means the default' => [CHAN_IFTTT_KEY, ''],
]);

test('a Free subscriber sees the tile disabled and is refused on a forced nextStep', function () {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Free]));
    Http::fake();

    $page = livewire(ConnectChannel::class)->instance();
    $tile = $page->{$page->getDefaultTestingSchemaName()}->getComponent(fn ($component) => $component instanceof RadioCards);

    expect($tile->getOptions())->toHaveKey('ifttt')
        ->and($tile->isOptionDisabled('ifttt', 'IFTTT'))->toBeTrue();

    chanChatFormSubmit('ifttt', ['webhooks_key' => CHAN_IFTTT_KEY, 'event' => CHAN_IFTTT_EVENT])
        ->assertNotified('Your plan can\'t add that channel');

    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
});

test('a downgraded subscriber\'s channel is kept and refused before any HTTP, and an unconfirmed one gets nothing', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $downgraded = NotificationChannel::factory()->for(User::factory()->create())->ifttt()->create();
    $unconfirmed = NotificationChannel::factory()->for(User::factory()->create(['tier_override' => Tier::Plus]))->ifttt()->unverified()->create();

    expect(app(ChannelDispatcher::class)->send($downgraded, 'Tornado Warning', 'Alert'))
        ->success->toBeFalse()->error->toBe(ChannelDispatcher::TIER_REFUSAL)
        ->and(app(ChannelDispatcher::class)->send($downgraded, 'code', 'Confirm', ['type' => 'confirmation']))
        ->success->toBeFalse()->error->toBe(ChannelDispatcher::TIER_REFUSAL)
        ->and(app(ChannelDispatcher::class)->send($unconfirmed, 'Tornado Warning', 'Alert'))
        ->success->toBeFalse()->error->toBe(ChannelDispatcher::UNCONFIRMED_REFUSAL)
        ->and(app(SendTestNotification::class)($unconfirmed))->toBeFalse()
        ->and($downgraded->fresh())->not->toBeNull();
    Http::assertNothingSent();
});

test('a 424 records the fixed IFTTT text and the wizard says the code was not sent', function () {
    Http::fake(['*/notify/*' => Http::response(['error' => 'One or more notifications could not be sent'], 424)]);
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $channel = NotificationChannel::factory()->ifttt()->for($user)->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('IFTTT did not accept the event. Check the Webhooks key and the event name, and that the Webhooks service is still connected to your IFTTT account.')
        ->and($this->type->failureMessage())->toBe($result->error);

    $this->actingAs($user);
    chanChatFormSubmit('ifttt', ['webhooks_key' => CHAN_IFTTT_LONG_KEY, 'event' => CHAN_IFTTT_EVENT])
        ->assertNotified(Notification::make()
            ->title('Couldn\'t send your confirmation code')
            ->body('We couldn\'t trigger that IFTTT event. Check the Webhooks key and the event name.')
            ->danger());
    expect(NotificationChannel::count())->toBe(1);
});

test('an Apprise connection error propagates from the transport without the key', function () {
    Http::fake(['*/notify/*' => Http::failedConnection()]);
    $channel = NotificationChannel::factory()->ifttt()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();

    try {
        app(ChannelDispatcher::class)->send($channel, 'Tornado Warning');
        $this->fail('Expected a ConnectionException.');
    } catch (ConnectionException $exception) {
        expect(str_contains($exception->getMessage(), CHAN_IFTTT_KEY))->toBeFalse()
            ->and(str_contains((string) $exception, CHAN_IFTTT_KEY))->toBeFalse();
    }
});

test('the webhooks key never appears outside the encrypted config', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });
    Http::fake(['*/notify/*' => Http::sequence()
        ->push(status: 200)
        ->push(['error' => 'One or more notifications could not be sent'], 424)
        ->push(status: 500)]);

    $channel = app(BeginChannelConnection::class)($user, $this->type, ['webhooks_key' => CHAN_IFTTT_KEY, 'event' => CHAN_IFTTT_EVENT]);
    $channel->forceFill(['verified_at' => now()])->save();
    $channel->update(['label' => 'Storm desk']);

    $rule = AlertRule::factory()->for($user)->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $product = IngestedProduct::factory()->create(['text' => 'Tornado Warning for Boulder County.']);
    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(ChannelDispatcher::class));
    $failure = DeliveryLog::sole();
    app(SendTestNotification::class)($channel->fresh());

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
        expect(str_contains(strtolower($content), strtolower(CHAN_IFTTT_KEY)))->toBeFalse("the key leaked into the {$place}");
    }

    expect($failure->status)->toBe('failure')
        ->and($failure->error_detail)->toBe($this->type->failureMessage())
        ->and($api->json('data.0.destination'))->toBe('wxnotify_alert · ••••k-od')
        ->and($api->json('data.0.destination_label'))->toBe('IFTTT Webhooks event')
        ->and($component->instance()->mountedActions[0]['data'])->not->toHaveKeys(['config', 'apprise_url', 'confirmation_code']);
});

test('the summary masks the key and the texts are fixed', function () {
    $channel = NotificationChannel::factory()->ifttt()->create();
    $help = IftttChannelType::KEY_HELP.' '.IftttChannelType::EVENT_HELP;

    expect($this->type->displaySummary($channel))->toBe('wxnotify_alert · ••••k-od')
        ->and($this->type->displaySummary(NotificationChannel::factory()->make(['channel_type' => 'ifttt', 'apprise_url' => null, 'config' => []])))->toBe('—')
        ->and($this->type->destinationLabel())->toBe('IFTTT Webhooks event')
        ->and($this->type->destinationNoun())->toBe('Webhooks key and event')
        ->and($this->type->confirmationHint($channel))->toBe('We just triggered your IFTTT event with a 6-digit code as Value2. Read it wherever your applet delivers it.')
        ->and($help)->toContain('https://ifttt.com/maker_webhooks')
        ->toContain('If Webhooks receives a web request')
        ->toContain('Value1 is the alert title')
        ->toContain('Value3 is always "info"')
        ->toContain('No alerts are sent until the channel is confirmed')
        ->toContain('wxnotify_alert');
});

test('the API refuses to create an ifttt channel', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    Http::fake();

    $this->withToken($user->createToken('Pixel 9', ['app'])->plainTextToken)
        ->postJson('/api/v1/channels', ['channel_type' => 'ifttt', 'webhooks_key' => CHAN_IFTTT_KEY, 'event' => CHAN_IFTTT_EVENT])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['channel_type']);

    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
});
