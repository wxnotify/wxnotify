<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Pages\ConnectChannel;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

// goToConnectChannelStep() and chanEnablePhoneTypes() live in tests/Pest.php.

const CHAN_AVAILABILITY_KEY = 'uk-Avail_key1';

beforeEach(function () {
    chanFakePauser();
});

/**
 * The httpSMS setup step's valid data.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function chanAvailabilitySmsData(array $overrides = []): array
{
    return [
        'api_key' => CHAN_AVAILABILITY_KEY,
        'from' => '(555) 234-0001',
        'to' => '(555) 234-0123',
        'consent' => true,
        ...$overrides,
    ];
}

/**
 * Submit the setup step straight from the client (nextStep(1)), never validating step 1.
 *
 * @param  array<string, mixed>  $data
 */
function chanSubmitSetupStep(string $slug, array $data): mixed
{
    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => $slug])->fillForm($data);

    return goToConnectChannelStep($component, 1);
}

test('a disabled phone type submitted straight to the setup step creates nothing and sends nothing', function () {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));
    Http::fake();

    $component = chanSubmitSetupStep('httpsms', chanAvailabilitySmsData());

    expect(NotificationChannel::count())->toBe(0);
    Http::assertNothingSent();
    $component->assertNotified('That channel type isn\'t available.');
});

test('a free subscriber whose free channel is in use cannot add another by skipping step 1', function () {
    $user = User::factory()->create();
    NotificationChannel::factory()->for($user)->create(['channel_type' => 'ntfy', 'apprise_url' => 'ntfy://first-topic']);
    $this->actingAs($user);
    Http::fake();

    $component = chanSubmitSetupStep('ntfy', ['topic' => 'second-topic']);

    expect(NotificationChannel::count())->toBe(1);
    Http::assertNothingSent();
    $component->assertNotified('Your plan can\'t add that channel');
});

test('a free subscriber who goes back to fix a typo refreshes their own pending channel', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = chanSubmitSetupStep('ntfy', ['topic' => 'wx-tpoic']);
    $channelId = $component->get('data.channel_id');

    // A new request loads the subscriber afresh; the test's cached user would still hold no channels.
    $this->actingAs($user->fresh());
    $component->fillForm(['topic' => 'wx-topic']);
    goToConnectChannelStep($component, 1)
        ->assertNotNotified('Your plan can\'t add that channel');

    $channel = NotificationChannel::sole();

    expect($channel->id)->toBe($channelId)
        ->and($channel->apprise_url)->toBe('ntfy://wx-topic')
        ->and($channel->isVerified())->toBeFalse();
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request['urls'] === 'ntfy://wx-topic'
        && str_contains($request['body'], $channel->confirmation_code));
});

test('a channel id the wizard did not create cannot free up the slot', function (Closure $makeForged) {
    $user = User::factory()->create();
    $this->actingAs($user);
    Http::fake();
    $forged = $makeForged($user);
    $before = $forged->fresh()->only(['apprise_url', 'confirmation_code', 'verified_at']);
    $count = NotificationChannel::count();

    $component = livewire(ConnectChannel::class)
        ->fillForm(['channel_type' => 'ntfy'])
        ->fillForm(['topic' => 'wx-topic'])
        ->set('data.channel_id', $forged->id);
    goToConnectChannelStep($component, 1)
        ->assertNotified('Your plan can\'t add that channel');

    expect(NotificationChannel::count())->toBe($count)
        ->and($forged->fresh()->only(['apprise_url', 'confirmation_code', 'verified_at']))->toEqual($before);
    Http::assertNothingSent();
})->with([
    'another subscriber\'s pending channel' => [function (User $user): NotificationChannel {
        NotificationChannel::factory()->for($user)->create(['channel_type' => 'ntfy', 'apprise_url' => 'ntfy://mine']);

        return NotificationChannel::factory()->unverified()->create(['channel_type' => 'ntfy', 'apprise_url' => 'ntfy://theirs']);
    }],
    'the subscriber\'s own pending channel of another type' => [
        fn (User $user): NotificationChannel => NotificationChannel::factory()->for($user)->unverified()->create(),
    ],
    'the subscriber\'s own verified channel' => [
        fn (User $user): NotificationChannel => NotificationChannel::factory()->for($user)->create(['channel_type' => 'ntfy', 'apprise_url' => 'ntfy://mine']),
    ],
]);

test('going back and submitting the same number again resends to the wizard\'s own pending channel', function () {
    chanEnablePhoneTypes();
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = chanSubmitSetupStep('httpsms', chanAvailabilitySmsData());
    $channelId = $component->get('data.channel_id');

    $component->fillForm(chanAvailabilitySmsData(['api_key' => 'uk-Fixed_key2', 'to' => '555-234-0123']));
    goToConnectChannelStep($component, 1)
        ->assertNotNotified('You already have a httpSMS (your Android phone) channel for that number.');

    $channel = NotificationChannel::sole();

    expect($channel->id)->toBe($channelId)
        ->and($component->get('data.channel_id'))->toBe($channelId)
        ->and($channel->config['api_key'])->toBe('uk-Fixed_key2')
        ->and($channel->isVerified())->toBeFalse();
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request['urls'], 'httpsms://uk-Fixed_key2@')
        && str_contains($request['body'], $channel->confirmation_code));
});

test('a channel id the wizard did not create is never refreshed', function (Closure $makeChannel, ?string $notification) {
    chanEnablePhoneTypes();
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $foreign = $makeChannel($user);
    $before = $foreign->fresh()->only(['config', 'confirmation_code', 'verified_at']);

    $component = livewire(ConnectChannel::class)
        ->fillForm(['channel_type' => 'httpsms'])
        ->fillForm(chanAvailabilitySmsData())
        ->set('data.channel_id', $foreign->id);
    goToConnectChannelStep($component, 1);

    if ($notification !== null) {
        $component->assertNotified($notification);
    }

    expect($foreign->fresh()->only(['config', 'confirmation_code', 'verified_at']))->toEqual($before);
})->with([
    'another subscriber\'s pending channel' => [
        fn (User $user): NotificationChannel => NotificationChannel::factory()->httpSms('+15552340123')->unverified()
            ->for(User::factory()->create(['tier_override' => Tier::Plus]))->create(),
        null,
    ],
    'the subscriber\'s own verified channel' => [
        fn (User $user): NotificationChannel => NotificationChannel::factory()->httpSms('+15552340123')->for($user)->create(),
        'You already have a httpSMS (your Android phone) channel for that number.',
    ],
]);
