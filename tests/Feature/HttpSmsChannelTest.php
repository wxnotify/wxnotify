<?php

use App\Actions\Channels\BeginChannelConnection;
use App\Enums\ChannelTransport;
use App\Enums\Tier;
use App\Filament\Dashboard\Pages\ConnectChannel;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelTypes\HttpSmsChannelType;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\Transports\SmsTransport;
use App\Support\PhoneConsent;
use App\Support\PhoneNumber;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

const CHAN_HTTPSMS_TEST_KEY = 'uk-Test_key9';

beforeEach(function () {
    chanEnablePhoneTypes();
    $this->type = new HttpSmsChannelType;
});

/**
 * The guided form's valid data for an httpSMS channel.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function chanHttpSmsFormData(array $overrides = []): array
{
    return [
        'api_key' => CHAN_HTTPSMS_TEST_KEY,
        'from' => '(555) 234-0001',
        'to' => '555-555-0123',
        'consent' => true,
        ...$overrides,
    ];
}

test('it builds the httpsms url with an encoded key', function (string $key, string $expectedUserInfo) {
    $url = $this->type->appriseUrl([
        'api_key' => $key,
        'from' => '+15552340001',
        'to' => '+15555550123',
    ]);

    expect($url)->toBe("httpsms://{$expectedUserInfo}@15552340001/15555550123");
})->with([
    'a plain key' => ['abc123', 'abc123'],
    'underscore and dash' => ['ab_c-d', 'ab_c-d'],
    'every URL-special character' => ['a_b-c%d@e:f/g?h#i+j k', 'a_b-c%25d%40e%3Af%2Fg%3Fh%23i%2Bj%20k'],
]);

test('from and to may be the same phone', function () {
    $url = $this->type->appriseUrl(['api_key' => 'k', 'from' => '+15552340001', 'to' => '+15552340001']);
    $destination = $this->type->buildDestination(chanHttpSmsFormData(['to' => '(555) 234-0001']));

    expect($url)->toBe('httpsms://k@15552340001/15552340001')
        ->and($destination['config'])->toMatchArray(['from' => '+15552340001', 'to' => '+15552340001']);
});

test('it identifies itself and builds the destination from the form', function () {
    $destination = $this->type->buildDestination(chanHttpSmsFormData());

    expect($this->type->slug())->toBe('httpsms')
        ->and($this->type->label())->toBe('httpSMS (your Android phone)')
        ->and($this->type->icon())->toBe('heroicon-o-device-phone-mobile')
        ->and($this->type->transport())->toBe(ChannelTransport::Sms)
        ->and($this->type->requiredConfigKeys())->toBe(['api_key', 'from', 'to'])
        ->and($this->type->optOutHint())->toBeNull()
        ->and($destination['label'])->toBe('httpSMS (your Android phone) •••• 0123')
        ->and($destination['config'])->toMatchArray([
            'api_key' => CHAN_HTTPSMS_TEST_KEY,
            'from' => '+15552340001',
            'to' => '+15555550123',
            'consent_version' => PhoneConsent::VERSION,
        ]);
});

test('it validates the fields', function (string $field, mixed $value, bool $passes, ?string $message = null) {
    $rules = collect($this->type->formSchema())->keyBy(fn ($component) => $component->getName())[$field]->getValidationRules();
    $validator = Validator::make([$field => $value], [$field => $rules]);

    expect($validator->passes())->toBe($passes);

    if ($message !== null) {
        expect($validator->errors()->get($field))->toBe([$message]);
    }
})->with([
    'a key' => ['api_key', CHAN_HTTPSMS_TEST_KEY, true],
    'a blank key' => ['api_key', '', false],
    'a 256 character key' => ['api_key', str_repeat('a', 256), false],
    'a 255 character key' => ['api_key', str_repeat('a', 255), true],
    'a formatted from number' => ['from', '+1 (555) 234-0001', true],
    'a from number with a 1 exchange' => ['from', '5551230001', false, PhoneNumber::INVALID_MESSAGE],
    'a UK to number' => ['to', '+44 20 7946 0958', false, PhoneNumber::INVALID_MESSAGE],
    'a blank to number' => ['to', '', false],
]);

test('the key field is masked and required', function () {
    $fields = collect($this->type->formSchema())->keyBy(fn ($component) => $component->getName());

    expect($fields['api_key']->isPassword())->toBeTrue()
        ->and($fields['api_key']->isPasswordRevealable())->toBeTrue()
        ->and($fields['api_key']->isRequired())->toBeTrue();
});

test('completing the wizard as Plus texts the code through httpsms', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'httpsms']);
    goToConnectChannelStep($component, 0);
    $component->assertWizardCurrentStep(2)
        ->assertSee('httpSMS app')
        ->assertSee('Android phone')
        ->fillForm(chanHttpSmsFormData());
    goToConnectChannelStep($component, 1);
    $component->assertWizardCurrentStep(3);

    $channel = NotificationChannel::where('user_id', $user->id)->sole();

    expect($channel->channel_type)->toBe('httpsms')
        ->and($channel->apprise_url)->toBeNull()
        ->and($channel->isVerified())->toBeFalse();

    Http::assertSent(fn (Request $request): bool => str_starts_with($request['urls'], 'httpsms://'.CHAN_HTTPSMS_TEST_KEY.'@15552340001/15555550123')
        && str_contains($request['body'], $channel->confirmation_code));
});

test('a rejected send records the fixed httpSMS failure text', function () {
    Http::fake(['*/notify/*' => Http::response(['error' => 'One or more notifications could not be sent'], 424)]);
    $channel = NotificationChannel::factory()->httpSms()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('httpSMS did not accept the text message. Check the API key, that the httpSMS app is running on your phone, and the From number.')
        ->and($result->error)->toBe($this->type->failureMessage())
        ->and($result->error)->not->toContain(CHAN_HTTPSMS_TEST_KEY)->not->toContain('5555550123');
});

test('the transport refuses an httpsms channel missing its key', function () {
    Http::fake();
    $channel = NotificationChannel::factory()->httpSms()->create();
    $channel->config = collect($channel->config)->except('api_key')->all();

    expect(app(SmsTransport::class)->send($channel, 'body', null)->error)->toBe('This SMS channel is missing its settings.');
    Http::assertNothingSent();
});

test('the destination summary masks the number', function () {
    $channel = NotificationChannel::factory()->httpSms('+15552340001')->create();

    expect($this->type->displaySummary($channel))->toBe('•••• 0001')
        ->and($this->type->destinationLabel())->toBe('Text number')
        ->and($this->type->confirmationHint($channel))->toBe('We just texted a 6-digit code to •••• 0001.')
        ->and($this->type->codeNotSentHint())->toContain('httpSMS app');
});

test('the key and numbers stay out of the raw config, the activity log and the failure detail', function () {
    Http::fake(['*/notify/*' => Http::sequence()->push(status: 200)->push(['error' => 'x'], 424)]);
    $user = User::factory()->create(['tier_override' => Tier::Plus]);

    $channel = app(BeginChannelConnection::class)($user, $this->type, chanHttpSmsFormData());
    $channel->update(['label' => 'Phone']);
    $result = app(ChannelDispatcher::class)->send($channel->fresh(), 'Tornado Warning', 'Alert');

    $raw = (string) DB::table('notification_channels')->where('id', $channel->id)->value('config');
    $log = json_encode(Activity::all()->toArray());

    expect($raw)->not->toContain(CHAN_HTTPSMS_TEST_KEY)->not->toContain('5555550123')
        ->and($log)->not->toContain(CHAN_HTTPSMS_TEST_KEY)->not->toContain('5555550123')->not->toContain('2340001')
        ->and($result->error)->not->toContain(CHAN_HTTPSMS_TEST_KEY)->not->toContain('5555550123')->not->toContain('2340001');
});

test('the API refuses to create an httpsms channel', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    Http::fake();

    $this->withToken($user->createToken('Pixel 9', ['app'])->plainTextToken)
        ->postJson('/api/v1/channels', ['channel_type' => 'httpsms', ...chanHttpSmsFormData()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['channel_type']);

    expect(NotificationChannel::count())->toBe(0);
});
