<?php

use App\Actions\Channels\DispatchChannelConfirmationCode;
use App\Actions\Channels\VerifyChannelConfirmationCode;
use App\Enums\Tier;
use App\Filament\Dashboard\Pages\ConnectChannel;
use App\Filament\Dashboard\Resources\NotificationChannels\Pages\ListNotificationChannels;
use App\Models\NotificationChannel;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

// goToConnectChannelStep(), chanEnablePhoneTypes() and chanFakePauser() live in tests/Pest.php.

const CHAN_THROTTLE_NUMBER = '+15552340123';

beforeEach(function () {
    chanEnablePhoneTypes();
    chanFakePauser();
    $this->user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($this->user);
    RateLimiter::clear(DispatchChannelConfirmationCode::userLimitKey($this->user->id));
    RateLimiter::clear(DispatchChannelConfirmationCode::numberLimitKey($this->user->id, CHAN_THROTTLE_NUMBER));
});

/**
 * Press the table's "Resend code" on the channel.
 */
function chanThrottleResend(NotificationChannel $channel): mixed
{
    return livewire(ListNotificationChannels::class)->callAction(TestAction::make('resend')->table($channel));
}

test('a phone number gets five codes an hour, then resend refuses without touching the code or the guess counter', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->for($this->user)->httpSms(CHAN_THROTTLE_NUMBER)->unverified()->create();

    foreach (range(1, DispatchChannelConfirmationCode::CODES_PER_NUMBER_PER_HOUR) as $ignored) {
        chanThrottleResend($channel)->assertNotified('Confirmation code resent');
    }

    $code = $channel->fresh()->confirmation_code;
    app(VerifyChannelConfirmationCode::class)($channel->fresh(), '000000');

    chanThrottleResend($channel)
        ->assertNotified('Too many confirmation codes');

    Http::assertSentCount(DispatchChannelConfirmationCode::CODES_PER_NUMBER_PER_HOUR);
    expect($channel->fresh()->confirmation_code)->toBe($code)
        ->and(Cache::get(VerifyChannelConfirmationCode::attemptsKey($channel)))->toBe(1);
});

test('the limit is per subscriber and number: another number still gets its code', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $first = NotificationChannel::factory()->for($this->user)->httpSms(CHAN_THROTTLE_NUMBER)->unverified()->create();
    $other = NotificationChannel::factory()->for($this->user)->httpSms('+15552340124')->unverified()->create();

    foreach (range(1, DispatchChannelConfirmationCode::CODES_PER_NUMBER_PER_HOUR) as $ignored) {
        chanThrottleResend($first);
    }

    chanThrottleResend($other)->assertNotified('Confirmation code resent');
});

test('a subscriber gets ten codes an hour across all their numbers', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channels = collect(range(0, DispatchChannelConfirmationCode::CODES_PER_USER_PER_HOUR))
        ->map(fn (int $i): NotificationChannel => NotificationChannel::factory()->for($this->user)
            ->httpSms('+155523402'.str_pad((string) $i, 2, '0', STR_PAD_LEFT))->unverified()->create());

    $channels->take(DispatchChannelConfirmationCode::CODES_PER_USER_PER_HOUR)
        ->each(fn (NotificationChannel $channel) => chanThrottleResend($channel)->assertNotified('Confirmation code resent'));

    chanThrottleResend($channels->last())->assertNotified('Too many confirmation codes');
    Http::assertSentCount(DispatchChannelConfirmationCode::CODES_PER_USER_PER_HOUR);
});

test('the wizard refuses a throttled number and keeps no channel and no consent record', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    foreach (range(1, DispatchChannelConfirmationCode::CODES_PER_NUMBER_PER_HOUR) as $ignored) {
        RateLimiter::hit(DispatchChannelConfirmationCode::numberLimitKey($this->user->id, CHAN_THROTTLE_NUMBER), 3600);
    }

    $component = livewire(ConnectChannel::class)
        ->fillForm(['channel_type' => 'httpsms'])
        ->fillForm(['api_key' => 'uk-Throttle_key3', 'from' => '(555) 234-0001', 'to' => '555-234-0123', 'consent' => true]);
    goToConnectChannelStep($component, 1)
        ->assertNotified('Too many confirmation codes');

    expect(NotificationChannel::count())->toBe(0)
        ->and(Activity::where('event', 'phone_consent')->count())->toBe(0);
    Http::assertNothingSent();
});

test('channels other than phones are not limited by the phone throttle', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->for($this->user)->unverified()->create(['channel_type' => 'ntfy', 'apprise_url' => 'ntfy://wx-topic']);

    foreach (range(1, DispatchChannelConfirmationCode::CODES_PER_USER_PER_HOUR + 1) as $ignored) {
        chanThrottleResend($channel)->assertNotified('Confirmation code resent');
    }

    Http::assertSentCount(DispatchChannelConfirmationCode::CODES_PER_USER_PER_HOUR + 1);
});

test('the API answers a throttled phone resend with 429', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->for($this->user)->httpSms(CHAN_THROTTLE_NUMBER)->unverified()->create();
    foreach (range(1, DispatchChannelConfirmationCode::CODES_PER_NUMBER_PER_HOUR) as $ignored) {
        RateLimiter::hit(DispatchChannelConfirmationCode::numberLimitKey($this->user->id, CHAN_THROTTLE_NUMBER), 3600);
    }

    $this->withToken($this->user->createToken('Pixel 9', ['app'])->plainTextToken)
        ->postJson("/api/v1/channels/{$channel->id}/resend")
        ->assertStatus(429)
        ->assertJsonPath('error', 'too_many_codes');

    Http::assertNothingSent();
});

test('resend says why it could not send', function (Closure $setUp, string $body) {
    $channel = $setUp($this->user);

    chanThrottleResend($channel);
    $sent = collect(sentNotifications())->last();

    expect($sent['title'])->toBe('Couldn\'t resend the confirmation code')
        ->and($sent['body'])->toContain($body)
        ->and($sent['status'])->toBe('danger');
})->with([
    'a downgraded phone channel' => [
        function (User $user): NotificationChannel {
            Http::fake();
            $user->forceFill(['tier_override' => Tier::Free])->save();

            return NotificationChannel::factory()->for($user)->httpSms(CHAN_THROTTLE_NUMBER)->unverified()->create();
        },
        'This channel needs the Plus plan or higher.',
    ],
    'a phone provider that refuses the text' => [
        function (User $user): NotificationChannel {
            Http::fake(['*/notify/*' => Http::response(['error' => 'x'], 424)]);

            return NotificationChannel::factory()->for($user)->httpSms(CHAN_THROTTLE_NUMBER)->unverified()->create();
        },
        'httpSMS app',
    ],
    'an unreachable notification service' => [
        function (User $user): NotificationChannel {
            Http::fake(['*/notify/*' => Http::response(status: 500)]);

            return NotificationChannel::factory()->for($user)->unverified()->create(['channel_type' => 'ntfy', 'apprise_url' => 'ntfy://wx-topic']);
        },
        'temporarily unavailable',
    ],
]);

test('send test on a downgraded phone channel names the plan, not reachability', function () {
    Http::fake();
    $this->user->forceFill(['tier_override' => Tier::Free])->save();
    $channel = NotificationChannel::factory()->for($this->user)->httpSms(CHAN_THROTTLE_NUMBER)->create();

    livewire(ListNotificationChannels::class)->callAction(TestAction::make('sendTest')->table($channel));
    $sent = collect(sentNotifications())->last();

    expect($sent['title'])->toBe('Couldn\'t send the test notification')
        ->and($sent['body'])->toBe('This channel needs the Plus plan or higher.');
    Http::assertNothingSent();
});
