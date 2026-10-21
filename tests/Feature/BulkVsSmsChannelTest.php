<?php

use App\Enums\ChannelTransport;
use App\Enums\Tier;
use App\Filament\Dashboard\Pages\ConnectChannel;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelTypes\BulkVsSmsChannelType;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\Transports\SmsTransport;
use App\Support\PhoneConsent;
use App\Support\PhoneNumber;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

use function Pest\Livewire\livewire;

const CHAN_BULKVS_TEST_USER = 'ops@example.com';
const CHAN_BULKVS_TEST_PASSWORD = 'Secr3t-pass';

beforeEach(function () {
    chanEnablePhoneTypes();
    $this->type = new BulkVsSmsChannelType;
});

/**
 * The guided form's valid data for a BulkVS SMS channel.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function chanBulkVsSmsFormData(array $overrides = []): array
{
    return [
        'api_username' => CHAN_BULKVS_TEST_USER,
        'api_password' => CHAN_BULKVS_TEST_PASSWORD,
        'from' => '(555) 234-0001',
        'to' => '555-555-0123',
        'consent' => true,
        ...$overrides,
    ];
}

test('it builds the bulkvs url with encoded credentials', function (string $username, string $password, string $expectedUserInfo) {
    $url = $this->type->appriseUrl([
        'api_username' => $username,
        'api_password' => $password,
        'from' => '+15552340001',
        'to' => '+15555550123',
    ]);

    expect($url)->toBe("bulkvs://{$expectedUserInfo}@15552340001/15555550123");
})->with([
    'plain credentials' => ['ops', 'Secr3t', 'ops:Secr3t'],
    'an email username' => ['ops@example.com', 'Secr3t', 'ops%40example.com:Secr3t'],
    'every URL-special character' => ['u@s:e/r', 'p:/?#%+w d', 'u%40s%3Ae%2Fr:p%3A%2F%3F%23%25%2Bw%20d'],
]);

test('it identifies itself and builds the destination from the form', function () {
    $destination = $this->type->buildDestination(chanBulkVsSmsFormData());

    expect($this->type->slug())->toBe('bulkvs_sms')
        ->and($this->type->label())->toBe('BulkVS SMS')
        ->and($this->type->providerLabel())->toBe('BulkVS')
        ->and($this->type->transport())->toBe(ChannelTransport::Sms)
        ->and($this->type->requiredConfigKeys())->toBe(['api_username', 'api_password', 'from', 'to'])
        ->and($destination['label'])->toBe('BulkVS SMS •••• 0123')
        ->and($destination['config'])->toMatchArray([
            'api_username' => CHAN_BULKVS_TEST_USER,
            'api_password' => CHAN_BULKVS_TEST_PASSWORD,
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
    'a username' => ['api_username', CHAN_BULKVS_TEST_USER, true],
    'a blank username' => ['api_username', '', false],
    'a 256 character username' => ['api_username', str_repeat('a', 256), false],
    'a 255 character username' => ['api_username', str_repeat('a', 255), true],
    'a password' => ['api_password', CHAN_BULKVS_TEST_PASSWORD, true],
    'a blank password' => ['api_password', '', false],
    'a 256 character password' => ['api_password', str_repeat('a', 256), false],
    'a formatted from number' => ['from', '+1 (555) 234-0001', true],
    'a from number with a 1 exchange' => ['from', '5551230001', false, PhoneNumber::INVALID_MESSAGE],
    'a UK to number' => ['to', '+44 20 7946 0958', false, PhoneNumber::INVALID_MESSAGE],
    'a blank to number' => ['to', '', false],
]);

test('the password field is masked and required', function () {
    $fields = collect($this->type->formSchema())->keyBy(fn ($component) => $component->getName());

    expect($fields['api_password']->isPassword())->toBeTrue()
        ->and($fields['api_password']->isPasswordRevealable())->toBeTrue()
        ->and($fields['api_password']->isRequired())->toBeTrue();
});

test('completing the wizard as Plus texts the code through bulkvs', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'bulkvs_sms']);
    goToConnectChannelStep($component, 0);
    $component->assertWizardCurrentStep(2)
        ->assertSee('BulkVS portal')
        ->assertSee('SMS-enabled BulkVS number')
        ->fillForm(chanBulkVsSmsFormData());
    goToConnectChannelStep($component, 1);
    $component->assertWizardCurrentStep(3);

    $channel = NotificationChannel::where('user_id', $user->id)->sole();

    expect($channel->channel_type)->toBe('bulkvs_sms')
        ->and($channel->apprise_url)->toBeNull()
        ->and($channel->isVerified())->toBeFalse();

    Http::assertSent(fn (Request $request): bool => str_starts_with($request['urls'], 'bulkvs://ops%40example.com:Secr3t-pass@')
        && str_ends_with($request['urls'], '@15552340001/15555550123')
        && str_contains($request['body'], $channel->confirmation_code));
});

test('a rejected send records the fixed BulkVS failure text', function () {
    Http::fake(['*/notify/*' => Http::response(['error' => 'One or more notifications could not be sent'], 424)]);
    $channel = NotificationChannel::factory()->bulkVsSms()->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('BulkVS did not accept the text message. Check the API username and password and the From number.')
        ->and($result->error)->toBe($this->type->failureMessage());
});

test('the transport refuses a bulkvs channel missing a credential', function () {
    Http::fake();
    $channel = NotificationChannel::factory()->bulkVsSms()->create();
    $channel->config = collect($channel->config)->except('api_username')->all();

    expect(app(SmsTransport::class)->send($channel, 'body', null)->error)->toBe('This SMS channel is missing its settings.');
    Http::assertNothingSent();
});

test('the destination summary masks the number', function () {
    $channel = NotificationChannel::factory()->bulkVsSms('+15552340001')->create();

    expect($this->type->displaySummary($channel))->toBe('•••• 0001')
        ->and($this->type->destinationLabel())->toBe('Text number')
        ->and($this->type->confirmationHint($channel))->toBe('We just texted a 6-digit code to •••• 0001.')
        ->and($this->type->codeNotSentHint())->toContain('BulkVS');
});

test('the API refuses to create a bulkvs_sms channel', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    Http::fake();

    $this->withToken($user->createToken('Pixel 9', ['app'])->plainTextToken)
        ->postJson('/api/v1/channels', ['channel_type' => 'bulkvs_sms', ...chanBulkVsSmsFormData()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['channel_type']);

    expect(NotificationChannel::count())->toBe(0);
});
