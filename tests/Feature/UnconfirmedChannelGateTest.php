<?php

use App\Actions\Channels\DispatchChannelConfirmationCode;
use App\Actions\Channels\RegisterMobilePushDevice;
use App\Actions\Channels\SendTestNotification;
use App\Enums\Tier;
use App\Filament\Dashboard\Resources\NotificationChannels\Pages\ListNotificationChannels;
use App\Jobs\DeliverScheduledAlert;
use App\Jobs\DeliverWeatherAlert;
use App\Mail\ChannelMessageMail;
use App\Models\AlertRule;
use App\Models\ChannelType;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\WebhookUrlGuard;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

use function Pest\Livewire\livewire;

/**
 * Every registered channel type. A test below fails when a type is added to the registry and not here.
 */
const CHAN_GATE_SLUGS = [
    'email', 'ntfy', 'discord', 'slack', 'telegram', 'pushover', 'msteams', 'webhook', 'webpush', 'mobile_push',
    'twilio_sms', 'bulkvs_sms', 'httpsms', 'twilio_voice', 'google_chat', 'mattermost', 'rocketchat', 'matrix', 'whatsapp',
];

const CHAN_GATE_PHONE_SLUGS = ['twilio_sms', 'bulkvs_sms', 'httpsms', 'twilio_voice', 'whatsapp'];

const CHAN_GATE_PUBLIC_IP = '93.184.216.34';

/**
 * Fakes every wire a transport can use and returns a counter of the messages that actually went
 * out on any of them: HTTP (Apprise, Twilio, FCM), mail, the webhook Guzzle client and the Web Push service.
 *
 * @return Closure(): int
 */
function chanGateFakeWires(): Closure
{
    Http::fake([
        '*/notify/*' => Http::response(status: 200),
        'api.twilio.com/*' => Http::response(['sid' => 'CA'.str_repeat('0', 32), 'status' => 'queued'], 201),
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test-access-token', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response(['name' => 'projects/wx-test/messages/1']),
    ]);
    Mail::fake();
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn (): array => [CHAN_GATE_PUBLIC_IP]));
    chanFakeChatDns(['chat.example.com' => [CHAN_GATE_PUBLIC_IP], 'matrix.example.org' => [CHAN_GATE_PUBLIC_IP]]);
    chanFakePauser();
    chanConfigureVapid();
    $webhookCalls = chanFakeWebhookEndpoint(array_fill(0, 10, new Response(200)));
    $pushes = chanFakePushService();

    return fn (): int => count(Http::recorded())
        + Mail::sent(ChannelMessageMail::class)->count()
        + count($webhookCalls)
        + count($pushes);
}

/**
 * A channel of the given type for the user, built from the factory state sibling tests use
 * (the Apprise-only types take a plain Apprise URL).
 */
function chanGateChannel(string $slug, User $user, bool $verified): NotificationChannel
{
    $factory = NotificationChannel::factory()->for($user);

    $factory = match ($slug) {
        'email' => $factory->email(),
        'webhook' => $factory->webhook(),
        'webpush' => $factory->webPush(),
        'mobile_push' => $factory->mobilePush(),
        'twilio_sms' => $factory->twilioSms(),
        'bulkvs_sms' => $factory->bulkVsSms(),
        'httpsms' => $factory->httpSms(),
        'twilio_voice' => $factory->twilioVoice(),
        'google_chat' => $factory->googleChat(),
        'mattermost' => $factory->mattermost(),
        'rocketchat' => $factory->rocketChat(),
        'matrix' => $factory->matrix(),
        'whatsapp' => $factory->whatsApp(),
        default => $factory->state(['channel_type' => $slug, 'apprise_url' => match ($slug) {
            'ntfy' => 'ntfy://wx-alerts-topic',
            'discord' => 'discord://123456789/token/',
            'slack' => 'slack://T1JJ3T3L2/A1BRTD4JD/TIiajkdnlazkcOXrIdevi7FQ',
            'telegram' => 'tgram://123%3AABC-token/-100123456',
            'pushover' => 'pover://uKey1234567890@aTok0987654321',
            'msteams' => 'msteams://team/tokenA/tokenB/tokenC',
        }]),
    };

    return $verified ? $factory->create() : $factory->unverified()->create();
}

/**
 * Sends one weather alert and one scheduled summary through the queued jobs, and one test
 * notification, to the channel. Returns whether the test notification went out.
 */
function chanGateSendAll(NotificationChannel $channel, AlertRule $rule, AlertRule $summaryRule, IngestedProduct $product): bool
{
    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(ChannelDispatcher::class));
    (new DeliverScheduledAlert($summaryRule->id, $channel->id, 'Daily summary', 'Today: Sunny, high 88.'))->handle(app(ChannelDispatcher::class));

    return app(SendTestNotification::class)($channel->fresh());
}

beforeEach(function () {
    chanEnablePhoneTypes();
    chanEnableWhatsApp();
    $this->credentials = fcmUseCredentials();
});

afterEach(function () {
    @unlink($this->credentials['path']);
});

test('the gate dataset covers every registered channel type', function () {
    expect(app(ChannelTypeRegistry::class)->all()->map->slug()->sort()->values()->all())
        ->toBe(collect(CHAN_GATE_SLUGS)->sort()->values()->all());
});

test('an unconfirmed channel gets no alert, no summary and no test, and the jobs log the fixed refusal', function (string $slug) {
    $sent = chanGateFakeWires();
    $user = User::factory()->create(['tier_override' => Tier::Pro]);
    $channel = chanGateChannel($slug, $user, verified: false);
    $rule = AlertRule::factory()->for($user)->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $summaryRule = AlertRule::factory()->for($user)->dailySummary()->create();
    $product = IngestedProduct::factory()->create(['text' => 'Tornado Warning issued for...']);
    $refusal = in_array($slug, CHAN_GATE_PHONE_SLUGS, true) ? 'This number has not been confirmed yet.' : 'This channel has not been confirmed yet.';

    $testSent = chanGateSendAll($channel, $rule, $summaryRule, $product);

    expect($testSent)->toBeFalse()
        ->and($sent())->toBe(0);
    Http::assertNothingSent();
    Mail::assertNothingSent();

    $logs = DeliveryLog::query()->orderBy('id')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs->pluck('status')->all())->toBe(['failure', 'failure'])
        ->and($logs->pluck('error_detail')->all())->toBe([$refusal, $refusal])
        ->and($logs->pluck('alert_rule_id')->all())->toBe([$rule->id, $summaryRule->id])
        ->and($logs->pluck('notification_channel_id')->unique()->all())->toBe([$channel->id]);

    $secrets = array_filter([$channel->apprise_url, ...array_values(array_filter($channel->config ?? [], 'is_string')), $channel->confirmation_code]);
    foreach ($secrets as $secret) {
        expect($refusal)->not->toContain($secret);
    }
})->with(CHAN_GATE_SLUGS);

test('confirming the channel lets the same alert, summary and test go out', function (string $slug) {
    $sent = chanGateFakeWires();
    $user = User::factory()->create(['tier_override' => Tier::Pro]);
    $channel = chanGateChannel($slug, $user, verified: false);
    $rule = AlertRule::factory()->for($user)->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $summaryRule = AlertRule::factory()->for($user)->dailySummary()->create();
    $product = IngestedProduct::factory()->create(['text' => 'Tornado Warning issued for...']);

    chanGateSendAll($channel, $rule, $summaryRule, $product);
    expect($sent())->toBe(0);

    $channel->forceFill(['verified_at' => now()])->save();

    expect(chanGateSendAll($channel, $rule, $summaryRule, $product))->toBeTrue()
        ->and($sent())->toBeGreaterThanOrEqual(3)
        ->and(DeliveryLog::query()->where('status', 'success')->count())->toBe(2);
})->with(CHAN_GATE_SLUGS);

test('the confirmation code still reaches every type that has a code flow', function (string $slug) {
    $sent = chanGateFakeWires();
    $channel = chanGateChannel($slug, User::factory()->create(['tier_override' => Tier::Pro]), verified: false);

    expect(app(DispatchChannelConfirmationCode::class)($channel))->toBeTrue()
        ->and($sent())->toBeGreaterThanOrEqual(1)
        ->and($channel->fresh()->isVerified())->toBeFalse();
})->with(array_values(array_diff(CHAN_GATE_SLUGS, ['mobile_push'])));

test('the plan still wins over the confirmation code\'s exemption for every type', function (string $slug) {
    $sent = chanGateFakeWires();
    ChannelType::query()->where('slug', $slug)->update(['min_tier' => Tier::Plus->value]);
    $channel = chanGateChannel($slug, User::factory()->create(['tier_override' => Tier::Free]), verified: false);

    $result = app(ChannelDispatcher::class)->send($channel, 'Your WxNotify confirmation code is: 654321', 'Confirm', ['type' => 'confirmation', 'code' => '654321']);

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe(ChannelDispatcher::TIER_REFUSAL)
        ->and($sent())->toBe(0);
})->with(CHAN_GATE_SLUGS);

test('a phone registered through the app is confirmed and gets alerts', function () {
    $sent = chanGateFakeWires();
    $user = User::factory()->create();
    $token = $user->createToken('Pixel 9', ['app'])->accessToken;
    $channel = app(RegisterMobilePushDevice::class)($user, $token, 'fcm-token-registered', 'android');
    $rule = AlertRule::factory()->for($user)->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $product = IngestedProduct::factory()->create();

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(ChannelDispatcher::class));

    expect($channel->isVerified())->toBeTrue()
        ->and(DeliveryLog::sole()->status)->toBe('success')
        ->and($sent())->toBeGreaterThanOrEqual(1);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'messages:send'));
});

test('a web push channel whose subscription expired gets no further alerts', function () {
    chanGateFakeWires();
    $pushes = chanFakePushService(expired: true);
    $user = User::factory()->create();
    $channel = chanGateChannel('webpush', $user, verified: true);
    $rule = AlertRule::factory()->for($user)->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $product = IngestedProduct::factory()->create();

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(ChannelDispatcher::class));
    expect($pushes)->toHaveCount(1)
        ->and($channel->fresh()->isVerified())->toBeFalse();

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(ChannelDispatcher::class));

    expect($pushes)->toHaveCount(1)
        ->and(DeliveryLog::query()->orderByDesc('id')->first()->error_detail)->toBe('This channel has not been confirmed yet.');
});

test('a dashboard test that finds the channel unconfirmed says to confirm it, not to check it is reachable', function () {
    chanGateFakeWires();
    chanFakePushService(expired: true);
    $user = User::factory()->create();
    $this->actingAs($user);
    $channel = chanGateChannel('webpush', $user, verified: true);

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('sendTest')->table($channel))
        ->assertNotified(Notification::make()
            ->title('Couldn\'t send the test notification')
            ->body('This channel needs to be confirmed first. Resend the code and enter it, or connect the channel again.')
            ->danger());
});

test('an API test that finds the channel unconfirmed answers not_verified, not test_failed', function () {
    chanGateFakeWires();
    chanFakePushService(expired: true);
    $user = User::factory()->create();
    $channel = chanGateChannel('webpush', $user, verified: true);

    $this->withToken($user->createToken('Pixel 9', ['app'])->plainTextToken)
        ->postJson("/api/v1/channels/{$channel->id}/test")
        ->assertConflict()
        ->assertJsonPath('error', 'not_verified')
        ->assertJsonPath('message', 'Verify this channel before sending a test.');
});
