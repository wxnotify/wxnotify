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
use App\Support\ChannelTypes\WhatsAppChannelType;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\MessagingMessage;
use App\Support\PhoneConsent;
use App\Support\PhoneNumber;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Activitylog\Models\Activity;
use ToneGabes\BetterOptions\Forms\Components\RadioCards;

use function Pest\Livewire\livewire;

// goToConnectChannelStep(), chanEnableWhatsApp(), chanChatFieldValidate(), chanChatFormSubmit() and
// chanFakePauser() live in tests/Pest.php.

const CHAN_WA_TOKEN = 'EAABsbCS1iHgBO7ZBx9Kd2LmNoPq7RsTuVwXyZ0aBcDeFgHiJk';

const CHAN_WA_PHONE_ID = '123456789012';

const CHAN_WA_URL = 'whatsapp://wx_alert:'.CHAN_WA_TOKEN.'@'.CHAN_WA_PHONE_ID.'/525512345678?lang=es_MX&:body=1';

const CHAN_WA_FAILURE = 'WhatsApp did not accept the message. Check the access token, phone number ID, that the template name and language match an approved template with one variable, and that your number opted in.';

const CHAN_WA_NOT_SENT = 'We couldn\'t send the WhatsApp message. Check the access token, phone number ID, and that the template name and language match an approved template with one variable.';

/**
 * The WhatsApp form's valid data, with overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function chanWhatsAppFormData(array $overrides = []): array
{
    return [
        'access_token' => CHAN_WA_TOKEN,
        'phone_number_id' => CHAN_WA_PHONE_ID,
        'template' => 'wx_alert',
        'template_language' => 'es_MX',
        'to' => '+52 55 1234 5678',
        'consent' => true,
        ...$overrides,
    ];
}

/**
 * Stored WhatsApp config, with overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function chanWhatsAppConfig(array $overrides = []): array
{
    return [
        'access_token' => CHAN_WA_TOKEN,
        'phone_number_id' => CHAN_WA_PHONE_ID,
        'template' => 'wx_alert',
        'template_language' => 'es_MX',
        'to' => '+525512345678',
        ...$overrides,
    ];
}

beforeEach(function () {
    $this->type = new WhatsAppChannelType;
});

test('it builds the template url with encoded parts', function (array $config, string $url) {
    expect($this->type->appriseUrl(chanWhatsAppConfig($config)))->toBe($url);
})->with([
    'a Mexican number in Spanish (Mexico)' => [[], CHAN_WA_URL],
    'a US number in English (US)' => [
        ['to' => '+15552345678', 'template_language' => 'en_US'],
        'whatsapp://wx_alert:'.CHAN_WA_TOKEN.'@'.CHAN_WA_PHONE_ID.'/15552345678?lang=en_US&:body=1',
    ],
    'a UK number in English' => [
        ['to' => '+447700900123', 'template_language' => 'en', 'template' => 'weather_alert_2'],
        'whatsapp://weather_alert_2:'.CHAN_WA_TOKEN.'@'.CHAN_WA_PHONE_ID.'/447700900123?lang=en&:body=1',
    ],
    'a Guatemalan number in Spanish' => [
        ['to' => '+50223456789', 'template_language' => 'es', 'phone_number_id' => '100200'],
        'whatsapp://wx_alert:'.CHAN_WA_TOKEN.'@100200/50223456789?lang=es&:body=1',
    ],
]);

test('every url-special character is refused in the template name, phone number id and token', function (string $character) {
    foreach (['template' => 'wx_alert', 'phone_number_id' => CHAN_WA_PHONE_ID, 'access_token' => CHAN_WA_TOKEN] as $field => $valid) {
        foreach (array_unique([substr($valid, 0, 4).$character.substr($valid, 4), substr($valid, 0, 4).rawurlencode($character).substr($valid, 4)]) as $value) {
            expect(chanChatFieldValidate($this->type, $field, $value)['passes'])->toBeFalse("{$field}: {$value}")
                ->and(fn () => $this->type->buildDestination(chanWhatsAppFormData([$field => $value])))->toThrow(InvalidArgumentException::class)
                ->and($this->type->appriseUrl(chanWhatsAppConfig([$field => $value])))->toBe('');
        }
    }
})->with(['/', '@', ':', '?', '#', '%', ' ', 'é', '&', '=', '+', ',', "\n"]);

test('a stored language or number outside the allow-list gives no url', function (string $key, string $value) {
    expect($this->type->appriseUrl(chanWhatsAppConfig([$key => $value])))->toBe('');
})->with([
    'another language' => ['template_language', 'fr'],
    'a language with a query' => ['template_language', 'es&token=x'],
    'a number with a slash' => ['to', '+52/5512345678'],
    'a number that is not E.164' => ['to', '5512345678'],
    'a group id' => ['to', '+12036312345678901'],
]);

test('it validates token, phone number id, template name, language and number through the fields', function (string $field, mixed $value, bool $passes, ?string $message = null) {
    $result = chanChatFieldValidate($this->type, $field, $value);

    expect($result['passes'])->toBe($passes);

    if ($message !== null) {
        expect($result['errors'])->toBe([$message]);
    }
})->with([
    'a token' => ['access_token', CHAN_WA_TOKEN, true],
    'a 1024 character token' => ['access_token', str_repeat('a', 1024), true],
    'a 1025 character token' => ['access_token', str_repeat('a', 1025), false],
    'a token with a dash' => ['access_token', 'EAAB-x', false],
    'a token with a trailing newline' => ['access_token', CHAN_WA_TOKEN."\n", false],
    'a blank token' => ['access_token', '', false],
    'a six digit phone number id' => ['phone_number_id', '123456', true],
    'a twenty digit phone number id' => ['phone_number_id', str_repeat('1', 20), true],
    'a five digit phone number id' => ['phone_number_id', '12345', false],
    'a 21 digit phone number id' => ['phone_number_id', str_repeat('1', 21), false],
    'a phone number instead of its id' => ['phone_number_id', '+15552345678', false],
    'arabic-indic digits in the id' => ['phone_number_id', '١٢٣٤٥٦٧', false],
    'a template name' => ['template', 'wx_alert', true],
    'a 512 character template name' => ['template', str_repeat('a', 512), true],
    'a 513 character template name' => ['template', str_repeat('a', 513), false],
    'an upper-case template name' => ['template', 'WX_Alert', false],
    'a template name with a dash' => ['template', 'wx-alert', false],
    'a blank template name' => ['template', '', false],
    'a Mexican mobile with the 1' => ['to', '+52 1 55 1234 5678', true],
    'a Mexican number with 00' => ['to', '0052 55 1234 5678', true],
    'a UK mobile' => ['to', '+44 7700 900123', true],
    'a ten digit US number' => ['to', '(555) 234-5678', true],
    'a +1 number with a 1 exchange' => ['to', '+1 555 123 4567', false, PhoneNumber::INTERNATIONAL_INVALID_MESSAGE],
    'a seven digit string' => ['to', '1234567', false, PhoneNumber::INTERNATIONAL_INVALID_MESSAGE],
    'a blank number' => ['to', '', false],
]);

test('the language select takes only the four offered languages', function (?string $language, bool $passes) {
    chanEnableWhatsApp();
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = chanChatFormSubmit('whatsapp', chanWhatsAppFormData(['template_language' => $language]));

    if ($passes) {
        $component->assertHasNoFormErrors();
        expect(NotificationChannel::sole()->config['template_language'])->toBe($language);
    } else {
        $component->assertHasFormErrors(['template_language']);
        expect(NotificationChannel::count())->toBe(0);
    }
})->with([
    'English (US)' => ['en_US', true],
    'English' => ['en', true],
    'Spanish' => ['es', true],
    'Spanish (Mexico)' => ['es_MX', true],
    'French' => ['fr', false],
    'a language with a query' => ['es&token=x', false],
    'none' => [null, false],
]);

test('the language defaults to English (US) when the type is picked', function () {
    chanEnableWhatsApp();
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));

    livewire(ConnectChannel::class)->fillForm(['channel_type' => 'whatsapp'])
        ->assertSchemaStateSet(['template_language' => 'en_US']);
});

test('the type has no From number, and its number field takes the international rule', function () {
    $fields = collect($this->type->formSchema())->map(fn ($component) => $component->getName())->all();

    expect($fields)->toBe(['access_token', 'phone_number_id', 'template', 'template_language', 'to', 'consent']);
});

test('the consent box is required and recorded without the number', function () {
    $consent = collect($this->type->formSchema())->keyBy(fn ($component) => $component->getName())['consent'];

    expect(chanChatFieldValidate($this->type, 'consent', true)['passes'])->toBeTrue()
        ->and(chanChatFieldValidate($this->type, 'consent', false)['passes'])->toBeFalse()
        ->and($consent->getLabel())->toBe(PhoneConsent::whatsappText());

    chanEnableWhatsApp();
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    chanChatFormSubmit('whatsapp', chanWhatsAppFormData(['consent' => false]))->assertHasFormErrors(['consent']);
    expect(NotificationChannel::count())->toBe(0)
        ->and(fn () => $this->type->buildDestination(chanWhatsAppFormData(['consent' => false])))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();

    $channel = app(BeginChannelConnection::class)($user, $this->type, chanWhatsAppFormData());
    $log = json_encode(Activity::all()->toArray());
    $entry = Activity::query()->where('event', 'phone_consent')->sole();

    expect($entry->subject->is($channel))->toBeTrue()
        ->and($entry->properties->all())->toBe(['channel_type' => 'whatsapp', 'version' => PhoneConsent::VERSION])
        ->and($log)->not->toContain('5512345678');
});

test('completing the wizard as Plus sends the code as the template parameter on one line', function () {
    chanEnableWhatsApp();
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    chanChatFormSubmit('whatsapp', chanWhatsAppFormData())
        ->assertHasNoFormErrors()
        ->assertWizardCurrentStep(3)
        ->assertSee('We just sent a 6-digit code to +52 •••• 5678 on WhatsApp.');

    $channel = NotificationChannel::where('user_id', $user->id)->sole();

    expect($channel->channel_type)->toBe('whatsapp')
        ->and($channel->apprise_url)->toBeNull()
        ->and($channel->label)->toBe('WhatsApp •••• 5678')
        ->and($channel->isVerified())->toBeFalse()
        ->and(collect($channel->config)->except('consented_at')->all())->toBe([
            'access_token' => CHAN_WA_TOKEN,
            'phone_number_id' => CHAN_WA_PHONE_ID,
            'template' => 'wx_alert',
            'template_language' => 'es_MX',
            'to' => '+525512345678',
            'consent_version' => PhoneConsent::VERSION,
        ]);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['urls'] === CHAN_WA_URL
        && ! array_key_exists('title', $request->data())
        && $request['body'] === "Your WxNotify code is {$channel->confirmation_code}. Enter it in your dashboard to confirm this number for weather alerts.");
});

test('a bare ten-digit number is taken as US or Canadian, and the hint shows +1', function () {
    chanEnableWhatsApp();
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    chanChatFormSubmit('whatsapp', chanWhatsAppFormData(['to' => '55 1234 5678']))
        ->assertWizardCurrentStep(3)
        ->assertSee('We just sent a 6-digit code to +1 •••• 5678 on WhatsApp.')
        ->assertDontSee('+52 •••• 5678');

    $channel = NotificationChannel::sole();

    expect($channel->config['to'])->toBe('+15512345678')
        ->and($this->type->displaySummary($channel))->toBe('+1 •••• 5678');
    Http::assertSent(fn (Request $request): bool => str_contains($request['urls'], '@'.CHAN_WA_PHONE_ID.'/15512345678?'));
});

test('the setup step says how numbers are read and what the template must be', function () {
    chanEnableWhatsApp();
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'whatsapp']);

    goToConnectChannelStep($component, 0)
        ->assertSee('A number without + or 00 in front is treated as a US or Canadian number')
        ->assertSee('include the country code')
        ->assertDontSee('The US or Canadian number to send alerts to.')
        ->assertSee('WxNotify: {{1}} (from your WxNotify account)')
        ->assertSee('Utility')
        ->assertSee('exactly one variable, not at the very start or end')
        ->assertSee('Meta must approve the template before anything can be sent')
        ->assertSee('Meta bills your account for each template message')
        ->assertSee('A test number can only message the recipients registered on it')
        ->assertSee('outside a conversation the person started, WhatsApp delivers only an approved template')
        ->assertSee('one line of up to 900 characters');
});

test('a disabled whatsapp row is not offered and a forced nextStep is refused', function () {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));
    Http::fake();

    $page = livewire(ConnectChannel::class)->instance();
    $tile = $page->{$page->getDefaultTestingSchemaName()}->getComponent(fn ($component) => $component instanceof RadioCards);

    expect($tile->getOptions())->not->toHaveKey('whatsapp');

    $component = livewire(ConnectChannel::class)
        ->fillForm(['channel_type' => 'whatsapp'])
        ->fillForm(chanWhatsAppFormData());
    goToConnectChannelStep($component, 1);

    $component->assertNotified('That channel type isn\'t available.');
    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
});

test('a Free subscriber is refused even when whatsapp is enabled', function () {
    chanEnableWhatsApp();
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Free]));
    Http::fake();

    $component = livewire(ConnectChannel::class)
        ->fillForm(['channel_type' => 'whatsapp'])
        ->fillForm(chanWhatsAppFormData());
    goToConnectChannelStep($component, 1);

    $component->assertNotified('Your plan can\'t add that channel');
    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
});

test('an unconfirmed whatsapp channel gets no alert and no test', function () {
    Http::fake();
    $channel = NotificationChannel::factory()->whatsApp()->unverified()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();

    $alert = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert', ['url' => 'https://wx.example.com/view-product/kbou-1']);
    $test = app(ChannelDispatcher::class)->send($channel, 'This is a test from WxNotify for "WhatsApp".', 'Test', ['type' => 'test']);

    expect($alert->success)->toBeFalse()
        ->and($alert->error)->toBe('This number has not been confirmed yet.')
        ->and($test->success)->toBeFalse();
    Http::assertNothingSent();
});

test('an alert arrives as one line within 900 characters with the link', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->for($user)->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $channel = NotificationChannel::factory()->whatsApp()->for($user)->create();
    $product = IngestedProduct::factory()->create([
        'text' => "Tornado Warning for\nBoulder County until 5 PM.\n\n\tTake shelter now     — it’s urgent.\n".str_repeat('* WHAT...Damaging winds. ', 80),
        'view_slug' => 'kbou-torbou-271622',
    ]);

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(ChannelDispatcher::class));

    $log = DeliveryLog::sole();

    expect($log->status)->toBe('success');
    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($log, $product): bool {
        $body = $request['body'];

        return $request['urls'] === CHAN_WA_URL
            && ! array_key_exists('title', $request->data())
            && preg_match('/[\n\r\t]| {5}/', $body) === 0
            && mb_strlen($body) <= MessagingMessage::WHATSAPP_MAX_LENGTH
            && str_starts_with($body, $log->subject.': Tornado Warning for Boulder County until 5 PM. Take shelter now — it’s urgent.')
            && str_ends_with($body, '... '.$product->viewUrl());
    });
});

test('a 424 records the fixed WhatsApp text, and the wizard says the code was not sent', function () {
    Http::fake(['*/notify/*' => Http::response(['error' => 'One or more notifications could not be sent'], 424)]);
    $channel = NotificationChannel::factory()->whatsApp()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe(CHAN_WA_FAILURE)
        ->and($this->type->failureMessage())->toBe(CHAN_WA_FAILURE);

    chanEnableWhatsApp();
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));

    chanChatFormSubmit('whatsapp', chanWhatsAppFormData())
        ->assertNotified(Notification::make()
            ->title('Couldn\'t send your confirmation code')
            ->body(CHAN_WA_NOT_SENT)
            ->danger());
    expect(NotificationChannel::count())->toBe(1);
});

test('an Apprise connection error propagates from the transport', function () {
    Http::fake(['*/notify/*' => Http::failedConnection()]);
    $channel = NotificationChannel::factory()->whatsApp()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();

    expect(fn () => app(ChannelDispatcher::class)->send($channel, 'Tornado Warning'))
        ->toThrow(ConnectionException::class);
});

test('the same number twice is refused; Back and Next with it refreshes', function () {
    chanEnableWhatsApp();
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = chanChatFormSubmit('whatsapp', chanWhatsAppFormData());
    $channelId = $component->get('data.channel_id');

    $component->fillForm(chanWhatsAppFormData(['to' => '0052 (55) 1234-5678', 'template' => 'wx_alert_v2']));
    goToConnectChannelStep($component, 1)
        ->assertNotNotified('You already have a WhatsApp channel for that number.');

    $channel = NotificationChannel::sole();

    expect($channel->id)->toBe($channelId)
        ->and($channel->config['template'])->toBe('wx_alert_v2')
        ->and($channel->isVerified())->toBeFalse();

    $channel->forceFill(['verified_at' => now()])->save();

    expect(fn () => app(BeginChannelConnection::class)($user, $this->type, chanWhatsAppFormData(['to' => '+52-55-1234-5678'])))
        ->toThrow(InvalidArgumentException::class, 'You already have a WhatsApp channel for that number.');

    chanChatFormSubmit('whatsapp', chanWhatsAppFormData(['to' => '0052 55 1234 5678']))
        ->assertNotified('You already have a WhatsApp channel for that number.');

    expect(NotificationChannel::count())->toBe(1);

    app(BeginChannelConnection::class)($user, $this->type, chanWhatsAppFormData(['to' => '+44 7700 900123']));
    expect(NotificationChannel::count())->toBe(2);
});

test('codes are limited by the phone buckets', function () {
    chanFakePauser();
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    $channel = NotificationChannel::factory()->whatsApp()->for($user)->unverified()->create();
    $numberKey = DispatchChannelConfirmationCode::numberLimitKey($user->id, '+525512345678');

    foreach (range(1, DispatchChannelConfirmationCode::CODES_PER_NUMBER_PER_HOUR) as $ignored) {
        livewire(ListNotificationChannels::class)->callAction(TestAction::make('resend')->table($channel))
            ->assertNotified('Confirmation code resent');
    }

    livewire(ListNotificationChannels::class)->callAction(TestAction::make('resend')->table($channel))
        ->assertNotified('Too many confirmation codes');

    expect(RateLimiter::attempts($numberKey))->toBe(DispatchChannelConfirmationCode::CODES_PER_NUMBER_PER_HOUR)
        ->and(RateLimiter::attempts(DispatchChannelConfirmationCode::userLimitKey($user->id)))->toBe(DispatchChannelConfirmationCode::CODES_PER_NUMBER_PER_HOUR)
        ->and(RateLimiter::attempts(DispatchChannelConfirmationCode::chatUserLimitKey($user->id)))->toBe(0)
        ->and(RateLimiter::attempts(DispatchChannelConfirmationCode::chatDestinationLimitKey($user->id, '+525512345678')))->toBe(0);
    Http::assertSentCount(DispatchChannelConfirmationCode::CODES_PER_NUMBER_PER_HOUR);
});

test('the token and the number never appear in config, logs, the failure detail, the edit modal or the API', function () {
    Log::spy();
    chanEnableWhatsApp();
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    $sends = 0;
    Http::fake(['*/notify/*' => function (Request $request) use (&$sends) {
        $sends++;

        return match ($sends) {
            1 => Http::response(status: 200),
            2 => Http::response(['error' => 'One or more notifications could not be sent'], 424),
            default => Http::failedConnection('cURL error 7: Failed to connect to apprise-api for '.CHAN_WA_URL)($request),
        };
    }]);

    $channel = app(BeginChannelConnection::class)($user, $this->type, chanWhatsAppFormData());
    $channel->forceFill(['verified_at' => now()])->save();
    $channel->update(['label' => 'My phone']);

    $raw = (string) DB::table('notification_channels')->where('id', $channel->id)->value('config');

    $rule = AlertRule::factory()->for($user)->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $product = IngestedProduct::factory()->create(['text' => 'Tornado Warning for Boulder County.']);
    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(ChannelDispatcher::class));
    $failure = DeliveryLog::sole();

    $pending = NotificationChannel::factory()->whatsApp('+447700900123')->for($user)->unverified()->create();
    expect(app(DispatchChannelConfirmationCode::class)($pending))->toBeFalse();

    $duplicate = null;

    try {
        app(BeginChannelConnection::class)($user, $this->type, chanWhatsAppFormData());
    } catch (InvalidArgumentException $e) {
        $duplicate = $e->getMessage();
    }

    $component = livewire(ListNotificationChannels::class)
        ->mountAction(TestAction::make('edit')->table($channel))
        ->assertSchemaComponentHidden('apprise_url_preview');
    $snapshot = json_encode($component->snapshot);

    $api = $this->withToken($user->createToken('Pixel 9', ['app'])->plainTextToken)
        ->getJson('/api/v1/channels')
        ->assertOk();

    $log = json_encode(Activity::all()->toArray());

    foreach ([CHAN_WA_TOKEN, '5512345678', '7700900123', 'whatsapp://'] as $secret) {
        expect($raw)->not->toContain($secret)
            ->and($log)->not->toContain($secret)
            ->and(json_encode($failure->toArray()))->not->toContain($secret)
            ->and($snapshot)->not->toContain($secret)
            ->and((string) $duplicate)->not->toContain($secret)
            ->and($api->getContent())->not->toContain($secret);
    }

    expect($failure->status)->toBe('failure')
        ->and($failure->error_detail)->toBe(CHAN_WA_FAILURE)
        ->and($duplicate)->toBe('You already have a WhatsApp channel for that number.')
        ->and($component->instance()->mountedActions[0]['data'])->not->toHaveKeys(['config', 'apprise_url', 'confirmation_code'])
        ->and(collect($api->json('data'))->firstWhere('id', $channel->id))->toMatchArray([
            'destination' => '+52 •••• 5678',
            'destination_label' => 'WhatsApp number',
        ]);

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context === [
        'notification_channel_id' => $pending->id,
        'exception' => ConnectionException::class,
    ]);
});

test('the type is a dashboard-only phone and messaging type with fixed texts', function () {
    $channel = NotificationChannel::factory()->whatsApp()->create();

    expect($this->type->slug())->toBe('whatsapp')
        ->and($this->type->label())->toBe('WhatsApp')
        ->and($this->type->icon())->toBe('fab-whatsapp')
        ->and($this->type->providerLabel())->toBe('Meta WhatsApp')
        ->and($this->type->optOutHint())->toBeNull()
        ->and($this->type->transport())->toBe(ChannelTransport::Messaging)
        ->and($this->type->maxLength())->toBe(MessagingMessage::WHATSAPP_MAX_LENGTH)
        ->and($this->type->requiredConfigKeys())->toBe(['access_token', 'phone_number_id', 'template', 'template_language', 'to'])
        ->and($this->type->serverUrl(chanWhatsAppConfig()))->toBeNull()
        ->and($this->type->destinationKey(chanWhatsAppConfig(['to' => '+525512345678'])))->toBe('+525512345678')
        ->and($this->type->destinationNoun())->toBe('number')
        ->and($this->type->supportsInlineCreate())->toBeFalse()
        ->and($this->type->appSetupFields(User::factory()->make()))->toBeNull()
        ->and($this->type->supportsRichContent())->toBeFalse()
        ->and($this->type->countsTowardFreeSlot())->toBeFalse()
        ->and($this->type->displaySummary($channel))->toBe('+52 •••• 5678')
        ->and($this->type->displaySummary(NotificationChannel::factory()->make(['channel_type' => 'whatsapp', 'apprise_url' => null, 'config' => []])))->toBe('—')
        ->and($this->type->destinationLabel())->toBe('WhatsApp number')
        ->and($this->type->confirmationHint($channel))->toBe('We just sent a 6-digit code to +52 •••• 5678 on WhatsApp.')
        ->and($this->type->codeNotSentHint())->toBe(CHAN_WA_NOT_SENT)
        ->and($this->type->compose("Line one\nline two", 'Title', ['type' => 'confirmation', 'code' => '123456']))
        ->toBe('Your WxNotify code is 123456. Enter it in your dashboard to confirm this number for weather alerts.');
});

test('the API refuses to create a whatsapp channel', function () {
    chanEnableWhatsApp();
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    Http::fake();

    $this->withToken($user->createToken('Pixel 9', ['app'])->plainTextToken)
        ->postJson('/api/v1/channels', ['channel_type' => 'whatsapp', ...chanWhatsAppFormData()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['channel_type']);

    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
});
