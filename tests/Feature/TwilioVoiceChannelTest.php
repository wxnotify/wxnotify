<?php

use App\Actions\Channels\BeginChannelConnection;
use App\Actions\Channels\DispatchChannelConfirmationCode;
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
use App\Support\ChannelTypes\TwilioSmsChannelType;
use App\Support\ChannelTypes\TwilioVoiceChannelType;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\PhoneConsent;
use App\Support\PhoneNumber;
use Database\Seeders\ChannelTypeSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Models\Activity;
use ToneGabes\BetterOptions\Forms\Components\RadioCards;

use function Pest\Livewire\livewire;

// goToConnectChannelStep(), chanEnablePhoneTypes() and chanFakePauser() live in tests/Pest.php.

const CHAN_TWILIO_VOICE_SID = 'AC0123456789abcdef0123456789ABCDEF';

const CHAN_TWILIO_VOICE_TOKEN = 'fedcba9876543210fedcba9876543210';

const CHAN_TWILIO_VOICE_NOT_SENT = 'We couldn\'t place the call. Check your Twilio credentials and both numbers, then try again.';

/**
 * The guided form's valid data for a Twilio voice channel.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function chanTwilioVoiceFormData(array $overrides = []): array
{
    return [
        'account_sid' => CHAN_TWILIO_VOICE_SID,
        'auth_token' => CHAN_TWILIO_VOICE_TOKEN,
        'from' => '(555) 234-0001',
        'to' => '555-555-0123',
        'consent' => true,
        ...$overrides,
    ];
}

/**
 * Twilio's Calls endpoint answering 201 Created, counting the calls placed.
 */
function chanFakeTwilioCalls(int $status = 201): ArrayObject
{
    $calls = new ArrayObject;

    Http::fake(['api.twilio.com/*' => function (Request $request) use ($calls, $status) {
        $calls[] = $request;

        return Http::response($status === 201 ? ['sid' => 'CA'.str_repeat('0', 32), 'status' => 'queued'] : ['code' => 20003, 'message' => 'Authenticate', 'status' => $status], $status);
    }]);

    return $calls;
}

/**
 * The code read out in a call's TwiML, as VoiceScript says it ("1. 2. 3. 4. 5. 6").
 */
function chanSpokenCode(string $code): string
{
    return implode('. ', str_split($code));
}

beforeEach(function () {
    chanEnablePhoneTypes();
    $this->type = new TwilioVoiceChannelType;
});

test('the type is a dashboard-only phone type that places calls through the voice transport', function () {
    $channel = NotificationChannel::factory()->twilioVoice('+15552344567')->create();

    expect($this->type->slug())->toBe('twilio_voice')
        ->and($this->type->label())->toBe('Twilio voice call')
        ->and($this->type->icon())->toBe('heroicon-o-phone')
        ->and($this->type->providerLabel())->toBe('Twilio')
        ->and($this->type->transport())->toBe(ChannelTransport::TwilioVoice)
        ->and($this->type->optOutHint())->toBeNull()
        ->and($this->type->destinationLabel())->toBe('Call number')
        ->and($this->type->displaySummary($channel))->toBe('•••• 4567')
        ->and($this->type->confirmationHint($channel))->toBe('We\'re calling •••• 4567 now. Answer and listen for the 6-digit code.')
        ->and($this->type->codeNotSentHint())->toBe(CHAN_TWILIO_VOICE_NOT_SENT)
        ->and($this->type->supportsInlineCreate())->toBeFalse()
        ->and($this->type->appSetupFields(User::factory()->make()))->toBeNull()
        ->and($this->type->supportsRichContent())->toBeFalse()
        ->and($this->type->countsTowardFreeSlot())->toBeFalse();
});

test('it validates the account sid, auth token and both numbers with the twilio sms rules', function (string $field, mixed $value, bool $passes, ?string $message = null) {
    $rules = collect($this->type->formSchema())->keyBy(fn ($component) => $component->getName())[$field]->getValidationRules();
    $validator = Validator::make([$field => $value], [$field => $rules]);

    expect($validator->passes())->toBe($passes);

    if ($message !== null) {
        expect($validator->errors()->get($field))->toBe([$message]);
    }
})->with([
    'a valid account sid' => ['account_sid', CHAN_TWILIO_VOICE_SID, true],
    'an account sid one character short' => ['account_sid', substr(CHAN_TWILIO_VOICE_SID, 0, -1), false],
    'an API key sid instead of an account sid' => ['account_sid', 'SK'.substr(CHAN_TWILIO_VOICE_SID, 2), false],
    'a blank account sid' => ['account_sid', '', false],
    'a valid auth token' => ['auth_token', CHAN_TWILIO_VOICE_TOKEN, true],
    'an auth token with a non-hex character' => ['auth_token', substr(CHAN_TWILIO_VOICE_TOKEN, 0, -1).'z', false],
    'a blank auth token' => ['auth_token', '', false],
    'a formatted from number' => ['from', '+1 (555) 234-0001', true],
    'a from number with a 1 exchange' => ['from', '5551230001', false, PhoneNumber::INVALID_MESSAGE],
    'a dotted to number' => ['to', '555.234.0123', true],
    'a UK to number' => ['to', '+44 20 7946 0958', false, PhoneNumber::INVALID_MESSAGE],
    'a blank to number' => ['to', '', false],
]);

test('the numbers are tel inputs, consent names calls, and the setup step says each alert is a billed call', function () {
    $fields = collect($this->type->formSchema())->keyBy(fn ($component) => $component->getName());
    $validates = fn (mixed $value): bool => Validator::make(['consent' => $value], ['consent' => $fields['consent']->getValidationRules()])->passes();

    expect($fields->keys()->all())->toBe(['account_sid', 'auth_token', 'from', 'to', 'consent'])
        ->and($fields['from']->getType())->toBe('tel')
        ->and($fields['to']->getType())->toBe('tel')
        ->and($fields['consent']->getLabel())->toBe(PhoneConsent::text('Twilio', voice: true))
        ->and($fields['consent']->getLabel())->toContain('calls')->not->toContain('STOP')
        ->and($validates(true))->toBeTrue()
        ->and($validates(false))->toBeFalse();

    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));
    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'twilio_voice']);

    goToConnectChannelStep($component, 0)
        ->assertSee('A voice-capable number on your Twilio account')
        ->assertSee('Each alert is a billed call on your Twilio account');
});

test('completing the wizard as Plus places one call that speaks the code', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    $calls = chanFakeTwilioCalls();

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'twilio_voice']);
    goToConnectChannelStep($component, 0);
    $component->assertWizardCurrentStep(2)->fillForm(chanTwilioVoiceFormData());
    goToConnectChannelStep($component, 1);

    $channel = NotificationChannel::where('user_id', $user->id)->sole();

    $component->assertWizardCurrentStep(3)
        ->assertSee('We&#039;re calling •••• 0123 now. Answer and listen for the 6-digit code.', false);

    expect($channel->channel_type)->toBe('twilio_voice')
        ->and($channel->apprise_url)->toBeNull()
        ->and($channel->label)->toBe('Twilio voice call •••• 0123')
        ->and($channel->isVerified())->toBeFalse()
        ->and($channel->config)->toMatchArray([
            'account_sid' => CHAN_TWILIO_VOICE_SID,
            'auth_token' => CHAN_TWILIO_VOICE_TOKEN,
            'from' => '+15552340001',
            'to' => '+15555550123',
            'consent_version' => PhoneConsent::VERSION,
        ])
        ->and($calls)->toHaveCount(1)
        ->and($calls[0]->url())->toBe('https://api.twilio.com/2010-04-01/Accounts/'.CHAN_TWILIO_VOICE_SID.'/Calls.json')
        ->and($calls[0]['To'])->toBe('+15555550123')
        ->and($calls[0]['From'])->toBe('+15552340001')
        ->and($calls[0]['Twiml'])->toContain('Your WxNotify confirmation code is '.chanSpokenCode($channel->confirmation_code).'.');
});

test('a failed call removes the new channel and says to check the credentials', function () {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));
    $calls = chanFakeTwilioCalls(401);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'twilio_voice']);
    goToConnectChannelStep($component, 0);
    $component->fillForm(chanTwilioVoiceFormData());
    goToConnectChannelStep($component, 1);

    $component->assertNotified(Notification::make()
        ->title('Couldn\'t send your confirmation code')
        ->body(CHAN_TWILIO_VOICE_NOT_SENT)
        ->danger());
    expect(NotificationChannel::count())->toBe(0)
        ->and($calls)->toHaveCount(1);
});

test('resending the code places exactly one new call, and the phone throttle stops further calls', function () {
    chanFakePauser();
    $calls = chanFakeTwilioCalls();
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    $channel = NotificationChannel::factory()->twilioVoice()->for($user)->unverified()->create();

    livewire(ListNotificationChannels::class)->callAction(TestAction::make('resend')->table($channel))
        ->assertNotified('Confirmation code resent');

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['Twiml'])->toContain(chanSpokenCode($channel->fresh()->confirmation_code));

    foreach (range(2, DispatchChannelConfirmationCode::CODES_PER_NUMBER_PER_HOUR) as $ignored) {
        livewire(ListNotificationChannels::class)->callAction(TestAction::make('resend')->table($channel));
    }

    livewire(ListNotificationChannels::class)->callAction(TestAction::make('resend')->table($channel))
        ->assertNotified('Too many confirmation codes');

    expect($calls)->toHaveCount(DispatchChannelConfirmationCode::CODES_PER_NUMBER_PER_HOUR)
        ->and(RateLimiter::attempts(DispatchChannelConfirmationCode::numberLimitKey($user->id, '+15555550123')))
        ->toBe(DispatchChannelConfirmationCode::CODES_PER_NUMBER_PER_HOUR);
});

test('a resend through the API places one call and a throttled one places none', function () {
    $calls = chanFakeTwilioCalls();
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $channel = NotificationChannel::factory()->twilioVoice()->for($user)->unverified()->create();
    $token = $user->createToken('Pixel 9', ['app'])->plainTextToken;

    $this->withToken($token)->postJson("/api/v1/channels/{$channel->id}/resend")->assertNoContent();

    expect($calls)->toHaveCount(1);

    foreach (range(2, DispatchChannelConfirmationCode::CODES_PER_NUMBER_PER_HOUR) as $ignored) {
        RateLimiter::hit(DispatchChannelConfirmationCode::numberLimitKey($user->id, '+15555550123'), 3600);
    }

    $this->withToken($token)->postJson("/api/v1/channels/{$channel->id}/resend")
        ->assertStatus(429)
        ->assertJsonPath('error', 'too_many_codes');

    expect($calls)->toHaveCount(1);
});

test('the same number may have both a twilio sms and a twilio voice channel, but not two voice channels', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $calls = chanFakeTwilioCalls();
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $connect = app(BeginChannelConnection::class);

    $sms = $connect($user, new TwilioSmsChannelType, chanTwilioVoiceFormData());
    $voice = $connect($user, $this->type, chanTwilioVoiceFormData(['to' => '+1 555 555 0123']));

    expect($sms->channel_type)->toBe('twilio_sms')
        ->and($voice->channel_type)->toBe('twilio_voice')
        ->and($voice->config['to'])->toBe($sms->config['to'])
        ->and($calls)->toHaveCount(1);

    $voice->forceFill(['verified_at' => now()])->save();

    expect(fn () => $connect($user, $this->type, chanTwilioVoiceFormData(['to' => '5555550123'])))
        ->toThrow(InvalidArgumentException::class, 'You already have a Twilio voice call channel for that number.');
    expect(NotificationChannel::where('user_id', $user->id)->count())->toBe(2);
});

test('a verified voice channel on a rule is called once for a matching alert and the log row has no secret', function () {
    $settings = app(GeneralSettings::class);
    $settings->message_prefix_enabled = true;
    $settings->message_prefix = '[WxNotify]';
    $settings->save();
    $calls = chanFakeTwilioCalls();
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->for($user)->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $channel = NotificationChannel::factory()->twilioVoice()->for($user)->create();
    $rule->notificationChannels()->attach($channel);
    $product = IngestedProduct::factory()->create([
        'text' => 'A tornado was reported near St. Louis. Take cover now.',
        'view_slug' => 'klsx-torlsx-271622',
    ]);

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(ChannelDispatcher::class));

    $log = DeliveryLog::sole();
    $twiml = $calls[0]['Twiml'];

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['To'])->toBe('+15555550123')
        ->and($twiml)->toContain('WxNotify weather alert. '.htmlspecialchars($log->subject, ENT_XML1 | ENT_QUOTES))
        ->not->toContain('[WxNotify]')
        ->not->toContain('http')
        ->and($log->status)->toBe('success')
        ->and($log->channel_type)->toBe('twilio_voice')
        ->and($log->error_detail)->toBeNull();

    foreach ([CHAN_TWILIO_VOICE_SID, $channel->config['account_sid'], $channel->config['auth_token'], '5555550123', '2340001'] as $secret) {
        expect(json_encode($log->toArray()))->not->toContain($secret);
    }
});

test('a Free subscriber cannot pick voice and a downgraded one is refused at send time', function () {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Free]));
    $page = livewire(ConnectChannel::class)->instance();
    $tile = $page->{$page->getDefaultTestingSchemaName()}->getComponent(fn ($component) => $component instanceof RadioCards);

    expect($tile->getOptions())->toHaveKey('twilio_voice')
        ->and($tile->isOptionDisabled('twilio_voice', 'Twilio voice call'))->toBeTrue();

    Http::fake();
    $owner = User::factory()->create(['tier_override' => Tier::Plus]);
    $channel = NotificationChannel::factory()->twilioVoice()->for($owner)->create();
    $owner->forceFill(['tier_override' => Tier::Free])->save();

    $alert = app(ChannelDispatcher::class)->send($channel->fresh(), 'Tornado Warning', 'Alert');
    $code = app(ChannelDispatcher::class)->send($channel->fresh(), 'code', 'Confirm', ['type' => 'confirmation', 'code' => '123456']);

    expect($alert->error)->toBe(ChannelDispatcher::TIER_REFUSAL)
        ->and($code->error)->toBe(ChannelDispatcher::TIER_REFUSAL)
        ->and($owner->fresh()->canAddChannel('twilio_voice'))->toBeFalse();
    Http::assertNothingSent();
});

test('an unverified voice channel gets no alert and no test call', function () {
    Http::fake();
    $channel = NotificationChannel::factory()->twilioVoice()->unverified()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();

    $alert = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert');
    $test = app(ChannelDispatcher::class)->send($channel, 'This is a test.', 'Test', ['type' => 'test']);

    expect($alert->error)->toBe('This number has not been confirmed yet.')
        ->and($test->error)->toBe('This number has not been confirmed yet.');
    Http::assertNothingSent();
});

test('the credentials and numbers never appear in config, logs, the failure detail, the edit modal or the API', function () {
    Log::spy();
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    $sends = 0;
    Http::fake(['api.twilio.com/*' => function () use (&$sends) {
        $sends++;

        return $sends === 1
            ? Http::response(status: 201)
            : Http::response(['code' => 21211, 'message' => 'Invalid To '.CHAN_TWILIO_VOICE_SID.' +15555550123'], 400);
    }]);

    $channel = app(BeginChannelConnection::class)($user, $this->type, chanTwilioVoiceFormData());
    $channel->forceFill(['verified_at' => now()])->save();
    $channel->update(['label' => 'My phone']);

    $raw = (string) DB::table('notification_channels')->where('id', $channel->id)->value('config');

    $rule = AlertRule::factory()->for($user)->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $product = IngestedProduct::factory()->create(['text' => 'Tornado Warning for Boulder County.']);
    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(ChannelDispatcher::class));
    $failure = DeliveryLog::sole();

    $component = livewire(ListNotificationChannels::class)
        ->mountAction(TestAction::make('edit')->table($channel))
        ->assertSchemaComponentHidden('apprise_url_preview');
    $snapshot = json_encode($component->snapshot);

    $api = $this->withToken($user->createToken('Pixel 9', ['app'])->plainTextToken)
        ->getJson('/api/v1/channels')
        ->assertOk();

    $log = json_encode(Activity::all()->toArray());

    foreach ([CHAN_TWILIO_VOICE_SID, CHAN_TWILIO_VOICE_TOKEN, '5555550123', '2340001'] as $secret) {
        expect($raw)->not->toContain($secret)
            ->and($log)->not->toContain($secret)
            ->and(json_encode($failure->toArray()))->not->toContain($secret)
            ->and($snapshot)->not->toContain($secret)
            ->and($api->getContent())->not->toContain($secret);
    }

    expect($failure->status)->toBe('failure')
        ->and($failure->error_detail)->toBe('Twilio cannot call this number.')
        ->and(Activity::query()->where('event', 'phone_consent')->sole()->properties->all())
        ->toBe(['channel_type' => 'twilio_voice', 'version' => PhoneConsent::VERSION])
        ->and($component->instance()->mountedActions[0]['data'])->not->toHaveKeys(['config', 'apprise_url', 'confirmation_code'])
        ->and(collect($api->json('data'))->firstWhere('id', $channel->id))->toMatchArray([
            'destination' => '•••• 0123',
            'destination_label' => 'Call number',
        ]);

    Log::shouldNotHaveReceived('error');
});

test('the API refuses to create a twilio_voice channel', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    Http::fake();

    $this->withToken($user->createToken('Pixel 9', ['app'])->plainTextToken)
        ->postJson('/api/v1/channels', ['channel_type' => 'twilio_voice', ...chanTwilioVoiceFormData()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['channel_type']);

    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
});

test('the voice catalog row ships plus-only and switched off, from the migration and the seeder', function () {
    $expected = [['slug' => 'twilio_voice', 'min_tier' => 'plus', 'sort_order' => 14, 'enabled' => false]];
    $migration = require database_path('migrations/2026_10_22_100002_add_twilio_voice_channel_type.php');
    $rows = fn (): array => DB::table('channel_types')->where('slug', 'twilio_voice')->get(['slug', 'min_tier', 'sort_order', 'enabled'])
        ->map(fn (object $row): array => [
            'slug' => $row->slug, 'min_tier' => $row->min_tier, 'sort_order' => (int) $row->sort_order, 'enabled' => (bool) $row->enabled,
        ])->all();

    expect(array_values(collect(ChannelTypeSeeder::SHIPPED)->where('slug', 'twilio_voice')->all()))->toBe($expected);

    $migration->down();
    expect($rows())->toBe([]);

    $migration->up();
    $migration->up();
    expect($rows())->toBe($expected);
});
