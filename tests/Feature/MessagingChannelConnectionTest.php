<?php

use App\Actions\Channels\DispatchChannelConfirmationCode;
use App\Actions\Channels\VerifyChannelConfirmationCode;
use App\Enums\Tier;
use App\Exceptions\ChannelCodeThrottled;
use App\Filament\Dashboard\Pages\ConnectChannel;
use App\Filament\Dashboard\Resources\NotificationChannels\Pages\ListNotificationChannels;
use App\Models\NotificationChannel;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;

use function Pest\Livewire\livewire;

// goToConnectChannelStep() and chanEnablePhoneTypes() live in tests/Pest.php.

const CHAN_MSGCONN_SPACE = 'AAAAmSgConn1';

const CHAN_MSGCONN_OTHER_SPACE = 'AAAAmSgConn2';

beforeEach(function () {
    $this->user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($this->user);
});

/**
 * A pasted Google Chat webhook URL for the space.
 */
function chanMsgConnWebhookUrl(string $space = CHAN_MSGCONN_SPACE, string $key = 'AIzaSyMsgConnKey-1'): string
{
    return "https://chat.googleapis.com/v1/spaces/{$space}/messages?key={$key}&token=MsgConnToken%3D";
}

/**
 * Pick Google Chat, paste the URL and submit step 2.
 */
function chanMsgConnSubmit(string $url): Testable
{
    $component = livewire(ConnectChannel::class)->fillForm(['channel_type' => 'google_chat']);
    goToConnectChannelStep($component, 0);
    $component->fillForm(['webhook_url' => $url]);

    return goToConnectChannelStep($component, 1);
}

/**
 * Press the table's "Resend code" on the channel.
 */
function chanMsgConnResend(NotificationChannel $channel): Testable
{
    return livewire(ListNotificationChannels::class)->callAction(TestAction::make('resend')->table($channel));
}

test('the same google chat space twice is refused and the first channel is untouched', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $first = NotificationChannel::factory()->for($this->user)->googleChat(CHAN_MSGCONN_SPACE)->create();
    $before = $first->fresh()->only(['config', 'label', 'confirmation_code', 'verified_at']);

    chanMsgConnSubmit(chanMsgConnWebhookUrl(key: 'AIzaSyAnotherKey-2'))
        ->assertNotified('You already have a Google Chat channel for that space.');

    expect(NotificationChannel::sole()->id)->toBe($first->id)
        ->and($first->fresh()->only(['config', 'label', 'confirmation_code', 'verified_at']))->toEqual($before);
    Http::assertNothingSent();

    chanMsgConnSubmit(chanMsgConnWebhookUrl(CHAN_MSGCONN_OTHER_SPACE))
        ->assertNotNotified('You already have a Google Chat channel for that space.');
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));
    chanMsgConnSubmit(chanMsgConnWebhookUrl())
        ->assertNotNotified('You already have a Google Chat channel for that space.');

    expect(NotificationChannel::count())->toBe(3);
});

test('back then next with the same space refreshes the pending channel', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = chanMsgConnSubmit(chanMsgConnWebhookUrl());
    $channelId = $component->get('data.channel_id');

    $component->fillForm(['webhook_url' => chanMsgConnWebhookUrl(key: 'AIzaSyFixedKey-3')]);
    goToConnectChannelStep($component, 1)
        ->assertNotNotified('You already have a Google Chat channel for that space.');

    $channel = NotificationChannel::sole();

    expect($channel->id)->toBe($channelId)
        ->and($channel->config['key'])->toBe('AIzaSyFixedKey-3')
        ->and($channel->isVerified())->toBeFalse();
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains($request['urls'], '/AIzaSyFixedKey-3/')
        && str_contains($request['body'], $channel->confirmation_code));
});

test('back then next to a space another channel already has is refused', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $existing = NotificationChannel::factory()->for($this->user)->googleChat(CHAN_MSGCONN_OTHER_SPACE)->create();

    $component = chanMsgConnSubmit(chanMsgConnWebhookUrl());
    $pending = NotificationChannel::findOrFail($component->get('data.channel_id'));
    $before = $pending->only(['config', 'confirmation_code']);

    $component->fillForm(['webhook_url' => chanMsgConnWebhookUrl(CHAN_MSGCONN_OTHER_SPACE)]);
    goToConnectChannelStep($component, 1)
        ->assertNotified('You already have a Google Chat channel for that space.');

    expect($pending->fresh()->only(['config', 'confirmation_code']))->toEqual($before)
        ->and($existing->fresh()->config['space'])->toBe(CHAN_MSGCONN_OTHER_SPACE)
        ->and(NotificationChannel::count())->toBe(2);
    Http::assertSentCount(1);
});

test('a sixth code to one space in an hour is refused without writing or sending', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $component = chanMsgConnSubmit(chanMsgConnWebhookUrl());
    foreach (range(2, DispatchChannelConfirmationCode::CHAT_CODES_PER_DESTINATION_PER_HOUR) as $attempt) {
        $component->fillForm(['webhook_url' => chanMsgConnWebhookUrl(key: "AIzaSyRetryKey-{$attempt}")]);
        goToConnectChannelStep($component, 1)->assertNotNotified('Too many confirmation codes');
    }
    $pending = NotificationChannel::sole();
    $before = $pending->only(['config', 'confirmation_code', 'confirmation_code_expires_at']);

    $component->fillForm(['webhook_url' => chanMsgConnWebhookUrl(key: 'AIzaSyRetryKey-6')]);
    goToConnectChannelStep($component, 1)->assertNotified('Too many confirmation codes');

    expect(NotificationChannel::sole()->only(['config', 'confirmation_code', 'confirmation_code_expires_at']))->toEqual($before);
    Http::assertSentCount(DispatchChannelConfirmationCode::CHAT_CODES_PER_DESTINATION_PER_HOUR);

    // A brand-new wizard run for the same space, after the pending channel is gone, is refused before anything is stored.
    $pending->delete();
    chanMsgConnSubmit(chanMsgConnWebhookUrl())->assertNotified('Too many confirmation codes');

    expect(NotificationChannel::count())->toBe(0);
    Http::assertSentCount(DispatchChannelConfirmationCode::CHAT_CODES_PER_DESTINATION_PER_HOUR);

    chanMsgConnSubmit(chanMsgConnWebhookUrl(CHAN_MSGCONN_OTHER_SPACE))->assertNotNotified('Too many confirmation codes');
    Http::assertSentCount(DispatchChannelConfirmationCode::CHAT_CODES_PER_DESTINATION_PER_HOUR + 1);
});

test('an eleventh code across spaces is refused', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channels = collect(range(0, DispatchChannelConfirmationCode::CHAT_CODES_PER_USER_PER_HOUR))
        ->map(fn (int $i): NotificationChannel => NotificationChannel::factory()->for($this->user)
            ->googleChat("AAAAmSgUser{$i}")->unverified()->create());

    $channels->take(DispatchChannelConfirmationCode::CHAT_CODES_PER_USER_PER_HOUR)
        ->each(fn (NotificationChannel $channel) => chanMsgConnResend($channel)->assertNotified('Confirmation code resent'));

    chanMsgConnResend($channels->last())->assertNotified('Too many confirmation codes');
    Http::assertSentCount(DispatchChannelConfirmationCode::CHAT_CODES_PER_USER_PER_HOUR);

    $this->actingAs(User::factory()->create(['tier_override' => Tier::Plus]));
    chanMsgConnSubmit(chanMsgConnWebhookUrl())->assertNotNotified('Too many confirmation codes');
    Http::assertSentCount(DispatchChannelConfirmationCode::CHAT_CODES_PER_USER_PER_HOUR + 1);
});

test('the chat limits do not touch the phone buckets', function () {
    chanEnablePhoneTypes();
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $chats = collect(range(1, DispatchChannelConfirmationCode::CHAT_CODES_PER_USER_PER_HOUR))
        ->map(fn (int $i): NotificationChannel => NotificationChannel::factory()->for($this->user)
            ->googleChat("AAAAmSgPhone{$i}")->unverified()->create());
    $chats->each(fn (NotificationChannel $channel) => chanMsgConnResend($channel)->assertNotified('Confirmation code resent'));

    expect(RateLimiter::attempts(DispatchChannelConfirmationCode::userLimitKey($this->user->id)))->toBe(0);

    $sms = NotificationChannel::factory()->for($this->user)->twilioSms()->unverified()->create();
    foreach (range(1, DispatchChannelConfirmationCode::CODES_PER_NUMBER_PER_HOUR) as $ignored) {
        chanMsgConnResend($sms)->assertNotified('Confirmation code resent');
    }
    chanMsgConnResend($sms)->assertNotified('Too many confirmation codes');

    Http::assertSentCount(DispatchChannelConfirmationCode::CHAT_CODES_PER_USER_PER_HOUR + DispatchChannelConfirmationCode::CODES_PER_NUMBER_PER_HOUR);
    expect(RateLimiter::attempts(DispatchChannelConfirmationCode::chatUserLimitKey($this->user->id)))->toBe(DispatchChannelConfirmationCode::CHAT_CODES_PER_USER_PER_HOUR);
});

test('resend in the table is throttled with the same notice', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->for($this->user)->googleChat(CHAN_MSGCONN_SPACE)->unverified()->create();

    foreach (range(1, DispatchChannelConfirmationCode::CHAT_CODES_PER_DESTINATION_PER_HOUR) as $ignored) {
        chanMsgConnResend($channel)->assertNotified('Confirmation code resent');
    }

    chanMsgConnResend($channel);
    $sent = collect(sentNotifications())->last();

    expect($sent['title'])->toBe('Too many confirmation codes')
        ->and($sent['body'])->toMatch('/^We\'ve sent several codes recently\. Try again in \d+ minutes?\.$/')
        ->and($sent['status'])->toBe('danger');
    Http::assertSentCount(DispatchChannelConfirmationCode::CHAT_CODES_PER_DESTINATION_PER_HOUR);
});

test('a throttled send leaves the stored code and the wrong-code counter alone', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->for($this->user)->googleChat(CHAN_MSGCONN_SPACE)->unverified()->create();

    foreach (range(1, DispatchChannelConfirmationCode::CHAT_CODES_PER_DESTINATION_PER_HOUR) as $ignored) {
        chanMsgConnResend($channel);
    }

    $code = $channel->fresh()->confirmation_code;
    app(VerifyChannelConfirmationCode::class)($channel->fresh(), '000000');

    expect(fn () => app(DispatchChannelConfirmationCode::class)($channel->fresh()))
        ->toThrow(ChannelCodeThrottled::class);

    expect($channel->fresh()->confirmation_code)->toBe($code)
        ->and(Cache::get(VerifyChannelConfirmationCode::attemptsKey($channel)))->toBe(1);
    Http::assertSentCount(DispatchChannelConfirmationCode::CHAT_CODES_PER_DESTINATION_PER_HOUR);
});

test('the chat limiter keys hash the destination', function () {
    expect(DispatchChannelConfirmationCode::chatDestinationLimitKey(7, CHAN_MSGCONN_SPACE))
        ->toBe('chat-code-destination:7:'.hash('sha256', CHAN_MSGCONN_SPACE))
        ->not->toContain(CHAN_MSGCONN_SPACE)
        ->and(DispatchChannelConfirmationCode::chatUserLimitKey(7))->toBe('chat-code-user:7');
});
