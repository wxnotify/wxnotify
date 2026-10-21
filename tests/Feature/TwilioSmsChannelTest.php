<?php

use App\Actions\Channels\BeginChannelConnection;
use App\Actions\Channels\DispatchChannelConfirmationCode;
use App\Enums\ChannelTransport;
use App\Enums\Tier;
use App\Filament\Dashboard\Pages\ConnectChannel;
use App\Jobs\DeliverWeatherAlert;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\Contracts\ChannelType;
use App\Support\ChannelTypes\TwilioSmsChannelType;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\Transports\SmsTransport;
use App\Support\PhoneConsent;
use App\Support\PhoneNumber;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Models\Activity;
use ToneGabes\BetterOptions\Forms\Components\RadioCards;

use function Pest\Livewire\livewire;

const CHAN_TWILIO_TEST_SID = 'AC0123456789abcdef0123456789ABCDEF';
const CHAN_TWILIO_TEST_TOKEN = 'fedcba9876543210fedcba9876543210';

beforeEach(function () {
    chanEnablePhoneTypes();
    $this->type = new TwilioSmsChannelType;
});

/**
 * The guided form's valid data for a Twilio SMS channel.
 *
 * @return array<string, mixed>
 */
function chanTwilioSmsFormData(array $overrides = []): array
{
    return [
        'account_sid' => CHAN_TWILIO_TEST_SID,
        'auth_token' => CHAN_TWILIO_TEST_TOKEN,
        'from' => '(555) 234-0001',
        'to' => '555-555-0123',
        'consent' => true,
        ...$overrides,
    ];
}

test('it builds the twilio url from config with digits-only numbers', function () {
    $config = $this->type->buildDestination(chanTwilioSmsFormData())['config'];

    expect($this->type->appriseUrl($config))
        ->toBe('twilio://'.CHAN_TWILIO_TEST_SID.':'.CHAN_TWILIO_TEST_TOKEN.'@15552340001/15555550123')
        ->and($this->type->transport())->toBe(ChannelTransport::Sms)
        ->and($this->type->slug())->toBe('twilio_sms')
        ->and($this->type->label())->toBe('Twilio SMS')
        ->and($this->type->icon())->toBe('heroicon-o-chat-bubble-left-ellipsis');
});

test('it percent-encodes credentials in the url', function () {
    $url = $this->type->appriseUrl([
        'account_sid' => 'AC a/b',
        'auth_token' => 'x@y:z?#%+',
        'from' => '+15552340001',
        'to' => '+15555550123',
    ]);

    expect($url)->toBe('twilio://AC%20a%2Fb:x%40y%3Az%3F%23%25%2B@15552340001/15555550123');
});

test('it validates the account sid, auth token and both numbers', function (string $field, mixed $value, bool $passes, ?string $message = null) {
    $rules = collect($this->type->formSchema())->keyBy(fn ($component) => $component->getName())[$field]->getValidationRules();
    $validator = Validator::make([$field => $value], [$field => $rules]);

    expect($validator->passes())->toBe($passes);

    if ($message !== null) {
        expect($validator->errors()->get($field))->toBe([$message]);
    }
})->with([
    'a valid account sid' => ['account_sid', CHAN_TWILIO_TEST_SID, true],
    'an account sid one character short' => ['account_sid', substr(CHAN_TWILIO_TEST_SID, 0, -1), false],
    'an API key sid instead of an account sid' => ['account_sid', 'SK'.substr(CHAN_TWILIO_TEST_SID, 2), false],
    'an account sid with a non-hex character' => ['account_sid', substr(CHAN_TWILIO_TEST_SID, 0, -1).'g', false],
    'a blank account sid' => ['account_sid', '', false],
    'a valid auth token' => ['auth_token', CHAN_TWILIO_TEST_TOKEN, true],
    'an auth token one character short' => ['auth_token', substr(CHAN_TWILIO_TEST_TOKEN, 0, -1), false],
    'an auth token with a non-hex character' => ['auth_token', substr(CHAN_TWILIO_TEST_TOKEN, 0, -1).'z', false],
    'a blank auth token' => ['auth_token', '', false],
    'a formatted from number' => ['from', '+1 (555) 234-0001', true],
    'a from number with a 1 exchange' => ['from', '5551230001', false, PhoneNumber::INVALID_MESSAGE],
    'a blank from number' => ['from', '', false],
    'a dotted to number' => ['to', '555.555.0123', true],
    'a UK to number' => ['to', '+44 20 7946 0958', false, PhoneNumber::INVALID_MESSAGE],
    'a blank to number' => ['to', '', false],
]);

test('the consent box is required', function () {
    $consent = collect($this->type->formSchema())->keyBy(fn ($component) => $component->getName())['consent'];
    $validates = fn (mixed $value): bool => Validator::make(['consent' => $value], ['consent' => $consent->getValidationRules()])->passes();

    expect($validates(true))->toBeTrue()
        ->and($validates(false))->toBeFalse()
        ->and($consent->getLabel())->toBe(PhoneConsent::text('Twilio', voice: false));

    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'twilio_sms']);
    goToConnectChannelStep($component, 0);
    $component->fillForm(chanTwilioSmsFormData(['consent' => false]));
    goToConnectChannelStep($component, 1);

    $component->assertHasFormErrors(['consent']);
    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
});

test('completing the wizard as Plus stores config and texts the code to the subscriber\'s number', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'twilio_sms']);
    goToConnectChannelStep($component, 0);
    $component->assertWizardCurrentStep(2)->fillForm(chanTwilioSmsFormData());
    goToConnectChannelStep($component, 1);
    $component->assertWizardCurrentStep(3);

    $channel = NotificationChannel::where('user_id', $user->id)->sole();

    expect($channel->channel_type)->toBe('twilio_sms')
        ->and($channel->apprise_url)->toBeNull()
        ->and($channel->label)->toBe('Twilio SMS •••• 0123')
        ->and($channel->isVerified())->toBeFalse()
        ->and($channel->config)->toMatchArray([
            'account_sid' => CHAN_TWILIO_TEST_SID,
            'auth_token' => CHAN_TWILIO_TEST_TOKEN,
            'from' => '+15552340001',
            'to' => '+15555550123',
            'consent_version' => PhoneConsent::VERSION,
        ])
        ->and($channel->config['consented_at'])->not->toBeEmpty();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request['urls'], '@15552340001/15555550123')
        && str_contains($request['body'], $channel->confirmation_code));
});

test('a Free subscriber sees the tile disabled', function () {
    $tile = function (User $user): RadioCards {
        $this->actingAs($user);
        $page = livewire(ConnectChannel::class)->instance();

        return $page->{$page->getDefaultTestingSchemaName()}->getComponent(fn ($component) => $component instanceof RadioCards);
    };

    $free = $tile(User::factory()->create(['tier_override' => Tier::Free]));

    expect($free->getOptions())->toHaveKey('twilio_sms')
        ->and($free->isOptionDisabled('twilio_sms', 'Twilio SMS'))->toBeTrue();

    expect($tile(User::factory()->create(['tier_override' => Tier::Plus]))->isOptionDisabled('twilio_sms', 'Twilio SMS'))->toBeFalse();
});

test('the same number twice is refused but on another phone type it is allowed', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $connect = app(BeginChannelConnection::class);

    $connect($user, $this->type, chanTwilioSmsFormData(['to' => '5552340001']));

    expect(fn () => $connect($user, $this->type, chanTwilioSmsFormData(['to' => '+15552340001'])))
        ->toThrow(InvalidArgumentException::class, 'You already have a Twilio SMS channel for that number.');

    $connect($user, $this->type, chanTwilioSmsFormData(['to' => '5552340002']));
    $connect(User::factory()->create(['tier_override' => Tier::Plus]), $this->type, chanTwilioSmsFormData(['to' => '5552340001']));

    $otherPhoneType = new class extends TwilioSmsChannelType
    {
        public function slug(): string
        {
            return 'other_sms';
        }
    };
    app()->instance(ChannelTypeRegistry::class, new class($otherPhoneType) extends ChannelTypeRegistry
    {
        public function __construct(private readonly ChannelType $otherPhoneType) {}

        public function find(string $slug): ?ChannelType
        {
            return $slug === 'other_sms' ? $this->otherPhoneType : parent::find($slug);
        }
    });

    $other = app(BeginChannelConnection::class)($user, $otherPhoneType, chanTwilioSmsFormData(['to' => '5552340001']));

    expect($other->channel_type)->toBe('other_sms')
        ->and(NotificationChannel::where('user_id', $user->id)->count())->toBe(3);
});

test('config is encrypted, absent from the activity log, and the consent entry has no number', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $user = User::factory()->create(['tier_override' => Tier::Plus]);

    $channel = app(BeginChannelConnection::class)($user, $this->type, chanTwilioSmsFormData());
    $channel->update(['label' => 'Phone']);

    $raw = (string) DB::table('notification_channels')->where('id', $channel->id)->value('config');
    $consent = Activity::query()->where('event', 'phone_consent')->sole();
    $log = json_encode(Activity::all()->toArray());

    expect($raw)->not->toContain(CHAN_TWILIO_TEST_TOKEN)->not->toContain('5555550123')
        ->and($consent->subject->is($channel))->toBeTrue()
        ->and($consent->properties->all())->toBe(['channel_type' => 'twilio_sms', 'version' => PhoneConsent::VERSION])
        ->and($log)->not->toContain(CHAN_TWILIO_TEST_TOKEN)
        ->not->toContain(CHAN_TWILIO_TEST_SID)
        ->not->toContain('5555550123')
        ->not->toContain('2340001');
});

test('a rejected send records the fixed Twilio failure text', function () {
    Http::fake(['*/notify/*' => Http::response(['error' => 'One or more notifications could not be sent'], 424)]);
    $channel = NotificationChannel::factory()->twilioSms()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('Twilio did not accept the text message. Check the Account SID, auth token and From number, and that your number can receive texts.')
        ->and($result->error)->toBe($this->type->failureMessage());
});

test('an Apprise connection error still propagates from the transport', function () {
    Http::fake(['*/notify/*' => Http::failedConnection()]);
    $channel = NotificationChannel::factory()->twilioSms()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();

    expect(fn () => app(ChannelDispatcher::class)->send($channel, 'Tornado Warning'))
        ->toThrow(ConnectionException::class);
});

test('the transport refuses a channel missing its settings or of a non-SMS type without calling Apprise', function () {
    Http::fake();

    $blank = NotificationChannel::factory()->twilioSms()->create();
    $blank->config = [...$blank->config, 'auth_token' => ''];

    $absent = NotificationChannel::factory()->twilioSms()->create();
    $absent->config = collect($absent->config)->except('account_sid')->all();

    $notSms = NotificationChannel::factory()->create(['channel_type' => 'discord']);

    expect(app(SmsTransport::class)->send($blank, 'body', null)->error)->toBe('This SMS channel is missing its settings.')
        ->and(app(SmsTransport::class)->send($absent, 'body', null)->error)->toBe('This SMS channel is missing its settings.')
        ->and(app(SmsTransport::class)->send($notSms, 'body', null)->error)->toBe('This channel cannot send text messages.');

    Http::assertNothingSent();
});

test('the transport checks only the type\'s required keys, so an unrelated blank key does not block a send', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->twilioSms()->create();
    $channel->config = [...$channel->config, 'note' => ''];

    expect($this->type->requiredConfigKeys())->toBe(['account_sid', 'auth_token', 'from', 'to'])
        ->and(app(SmsTransport::class)->send($channel, 'body', null)->success)->toBeTrue();
});

test('the destination summary masks the number and the caption is fixed', function () {
    $channel = NotificationChannel::factory()->twilioSms('+15552340001')->create();

    expect($this->type->displaySummary($channel))->toBe('•••• 0001')
        ->and($this->type->destinationLabel())->toBe('Text number')
        ->and($this->type->optOutHint())->toBe('Reply STOP to opt out.')
        ->and($this->type->providerLabel())->toBe('Twilio');
});

test('the API refuses to create a twilio_sms channel', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    Http::fake();

    $this->withToken($user->createToken('Pixel 9', ['app'])->plainTextToken)
        ->postJson('/api/v1/channels', ['channel_type' => 'twilio_sms', ...chanTwilioSmsFormData()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['channel_type']);

    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
});

test('an alert from DeliverWeatherAlert arrives as the composed SMS and logs success', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->for($user)->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $channel = NotificationChannel::factory()->twilioSms()->for($user)->create();
    $product = IngestedProduct::factory()->create([
        'text' => "Tornado Warning for\nBoulder County until 5 PM.\n\nTake shelter now — it’s [urgent].",
        'view_slug' => 'kbou-torbou-271622',
    ]);

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(ChannelDispatcher::class));

    $log = DeliveryLog::sole();

    expect($log->status)->toBe('success')
        ->and($log->error_detail)->toBeNull()
        ->and($log->channel_type)->toBe('twilio_sms');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => ! array_key_exists('title', $request->data())
        && $request['body'] === $log->subject."\nTornado Warning for Boulder County until 5 PM. Take shelter now - it's (urgent).\n".$product->viewUrl()
        && str_ends_with($request['urls'], '@15552340001/15555550123'));
});

test('a failed alert logs the fixed Twilio text, never the url or number', function () {
    Http::fake(['*/notify/*' => Http::response(['error' => 'One or more notifications could not be sent'], 424)]);
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->for($user)->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $channel = NotificationChannel::factory()->twilioSms()->for($user)->create();
    $product = IngestedProduct::factory()->create(['text' => 'Tornado Warning for Boulder County.']);

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(ChannelDispatcher::class));

    $log = DeliveryLog::sole();

    expect($log->status)->toBe('failure')
        ->and($log->error_detail)->toBe($this->type->failureMessage())
        ->and(json_encode($log->toArray()))
        ->not->toContain('twilio://')
        ->not->toContain('5555550123')
        ->not->toContain('2340001')
        ->not->toContain($channel->config['auth_token']);
});

test('the wizard says where the code went and, on failure, to check the credentials', function () {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));
    Http::fake(['*/notify/*' => Http::sequence()
        ->push(status: 200)
        ->push(['error' => 'One or more notifications could not be sent'], 424)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'twilio_sms']);
    goToConnectChannelStep($component, 0);
    $component->fillForm(chanTwilioSmsFormData());
    goToConnectChannelStep($component, 1);

    $component->assertWizardCurrentStep(3)
        ->assertSee('We just texted a 6-digit code to •••• 0123.')
        ->assertDontSee('We just sent a 6-digit code to the channel you configured.');

    $failing = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'twilio_sms']);
    goToConnectChannelStep($failing, 0);
    $failing->fillForm(chanTwilioSmsFormData(['to' => '5552340002']));
    goToConnectChannelStep($failing, 1);

    $failing->assertNotified(Notification::make()
        ->title('Couldn\'t send your confirmation code')
        ->body('We couldn\'t send the code. Check your Twilio credentials and both numbers, then try again.')
        ->danger());
    expect(NotificationChannel::where('label', 'like', '%0002')->exists())->toBeFalse();
});

test('a code that cannot reach Apprise logs only the exception class', function () {
    Log::spy();
    Http::fake(['*/notify/*' => Http::failedConnection('cURL error 7: Failed to connect to apprise-api port 8000 for http://apprise-api:8000/notify/')]);
    $channel = NotificationChannel::factory()->twilioSms()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create(['verified_at' => null]);

    expect(app(DispatchChannelConfirmationCode::class)($channel))->toBeFalse();

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context === [
        'notification_channel_id' => $channel->id,
        'exception' => ConnectionException::class,
    ]);
});
