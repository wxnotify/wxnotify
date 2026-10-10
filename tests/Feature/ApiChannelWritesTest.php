<?php

use App\Actions\Channels\VerifyChannelConfirmationCode;
use App\Enums\Tier;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $this->user = User::factory()->create(['tier_override' => Tier::Free]);
    $this->phone = $this->user->createToken('Pixel 9', ['app']);
});

function apiChannelWriteCall($test, string $method, string $uri, array $body = [])
{
    app('auth')->forgetGuards();

    return $test->withToken($test->phone->plainTextToken)->json($method, $uri, $body);
}

const DISCORD_URL = 'https://discord.com/api/webhooks/123456789/abcDEF-token_123';

test('creating a channel stores it unverified, sends the code, and never echoes the secret', function () {
    $response = apiChannelWriteCall($this, 'POST', '/api/v1/channels', ['channel_type' => 'discord', 'webhook_url' => DISCORD_URL])->assertCreated();

    $channel = NotificationChannel::where('user_id', $this->user->id)->sole();

    expect($channel->apprise_url)->toBe('discord://123456789/abcDEF-token_123/')
        ->and($channel->isVerified())->toBeFalse()
        ->and($channel->confirmation_code)->not->toBeNull()
        ->and($response->json('data.id'))->toBe($channel->id)
        ->and($response->json('data.verified'))->toBeFalse()
        ->and($response->json('data.label'))->toBe('Discord')
        ->and($response->getContent())->not->toContain('abcDEF')
        ->not->toContain($channel->confirmation_code);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/notify/'));
    expect(Activity::query()->where('subject_type', NotificationChannel::class)->where('subject_id', $channel->id)->first()->causer_id)->toBe($this->user->id);
});

test('an email channel and a label given by the subscriber', function () {
    Mail::fake();

    $response = apiChannelWriteCall($this, 'POST', '/api/v1/channels', ['channel_type' => 'email', 'to' => 'pat@example.com', 'label' => 'Work email'])->assertCreated();

    expect($response->json('data.label'))->toBe('Work email')
        ->and(NotificationChannel::where('user_id', $this->user->id)->sole()->config)->toBe(['to' => 'pat@example.com']);
});

test('the fields are validated per type and a type that is unknown or not creatable is refused', function (array $body, string $field) {
    apiChannelWriteCall($this, 'POST', '/api/v1/channels', $body)->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(NotificationChannel::where('user_id', $this->user->id)->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'bad discord url' => [['channel_type' => 'discord', 'webhook_url' => 'https://example.com/hook'], 'webhook_url'],
    'missing discord url' => [['channel_type' => 'discord'], 'webhook_url'],
    'ntfy topic with a space' => [['channel_type' => 'ntfy', 'topic' => 'has space'], 'topic'],
    'telegram without a chat' => [['channel_type' => 'telegram', 'bot_token' => '1:abc'], 'chat_id'],
    'bad email' => [['channel_type' => 'email', 'to' => 'nope'], 'to'],
    'overlong label' => [['channel_type' => 'ntfy', 'topic' => 'wx', 'label' => str_repeat('a', 256)], 'label'],
    'pushover is not creatable' => [['channel_type' => 'pushover', 'user_key' => 'x'], 'channel_type'],
    'webhook is not creatable' => [['channel_type' => 'webhook', 'url' => 'https://example.com'], 'channel_type'],
    'unknown type' => [['channel_type' => 'carrier-pigeon'], 'channel_type'],
]);

test('a Free subscriber at the no-cost limit is refused before anything is sent, but may still add email', function () {
    NotificationChannel::factory()->for($this->user)->create(['channel_type' => 'discord']);

    apiChannelWriteCall($this, 'POST', '/api/v1/channels', ['channel_type' => 'ntfy', 'topic' => 'wx'])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'channel_limit');

    Http::assertNothingSent();
    expect(NotificationChannel::where('user_id', $this->user->id)->count())->toBe(1);

    Mail::fake();
    apiChannelWriteCall($this, 'POST', '/api/v1/channels', ['channel_type' => 'email', 'to' => 'pat@example.com'])->assertCreated();
});

test('when the code cannot be sent the answer is 502 and no channel remains', function (callable $fake) {
    $fake();

    apiChannelWriteCall($this, 'POST', '/api/v1/channels', ['channel_type' => 'ntfy', 'topic' => 'wx'])
        ->assertStatus(502)
        ->assertJsonPath('error', 'code_not_sent');

    expect(NotificationChannel::where('user_id', $this->user->id)->count())->toBe(0);
})->with([
    'unreachable' => [fn () => Http::fake(['*/notify/*' => Http::failedConnection()])],
    'refused' => [function () {
        nwsFreshHttp();
        Http::fake(['*/notify/*' => Http::response(status: 500)]);
    }],
]);

test('the right code verifies, a wrong one is refused with a message, and verifying twice is fine', function () {
    $channel = NotificationChannel::factory()->for($this->user)->unverified()->create();

    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$channel->id}/verify", ['code' => '000000'])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'invalid_code')
        ->assertJsonPath('message', 'That code didn\'t match, or it expired. Request a new code if you need one.');

    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$channel->id}/verify", ['code' => '123456'])
        ->assertOk()
        ->assertJsonPath('data.verified', true);

    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$channel->id}/verify", ['code' => '999999'])->assertOk();
    expect($channel->fresh()->isVerified())->toBeTrue();
});

test('five wrong codes lock the code until a resend', function () {
    $channel = NotificationChannel::factory()->for($this->user)->unverified()->create();

    foreach (range(1, VerifyChannelConfirmationCode::MAX_WRONG_CODES) as $_) {
        apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$channel->id}/verify", ['code' => '000000'])->assertUnprocessable();
    }

    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$channel->id}/verify", ['code' => '123456'])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'invalid_code');

    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$channel->id}/resend")->assertNoContent();
    $code = $channel->fresh()->confirmation_code;

    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$channel->id}/verify", ['code' => $code])->assertOk();
});

test('resend sends a fresh code, refuses a verified channel, and says when it could not send', function () {
    $channel = NotificationChannel::factory()->for($this->user)->unverified()->create();

    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$channel->id}/resend")->assertNoContent();
    Http::assertSentCount(1);
    expect($channel->fresh()->confirmation_code_expires_at->isFuture())->toBeTrue();

    Http::fake(['*/notify/*' => Http::failedConnection()]);
    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$channel->id}/resend")->assertStatus(502)->assertJsonPath('error', 'code_not_sent');

    $verified = NotificationChannel::factory()->for($this->user)->create();
    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$verified->id}/resend")->assertConflict()->assertJsonPath('error', 'already_verified');
});

test('a test goes to a verified channel only, and says when it failed', function () {
    $verified = NotificationChannel::factory()->for($this->user)->create();
    $unverified = NotificationChannel::factory()->for($this->user)->unverified()->create();

    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$verified->id}/test")->assertNoContent();
    Http::assertSentCount(1);

    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$unverified->id}/test")->assertConflict()->assertJsonPath('error', 'not_verified');
    Http::assertSentCount(1);

    nwsFreshHttp();
    Http::fake(['*/notify/*' => Http::response(status: 500)]);
    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$verified->id}/test")->assertStatus(502)->assertJsonPath('error', 'test_failed');
});

test('renaming changes the label only, validates it, and logs the subscriber', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create(['label' => 'Old', 'apprise_url' => 'discord://1/tok/']);

    apiChannelWriteCall($this, 'PUT', "/api/v1/channels/{$channel->id}", ['label' => 'New'])->assertOk()->assertJsonPath('data.label', 'New');
    expect($channel->fresh()->label)->toBe('New')->and($channel->fresh()->apprise_url)->toBe('discord://1/tok/');

    apiChannelWriteCall($this, 'PUT', "/api/v1/channels/{$channel->id}", ['label' => ''])->assertUnprocessable()->assertJsonValidationErrors('label');
    apiChannelWriteCall($this, 'PUT', "/api/v1/channels/{$channel->id}", ['label' => str_repeat('a', 256)])->assertUnprocessable();

    expect(Activity::query()->where('subject_type', NotificationChannel::class)->where('subject_id', $channel->id)->latest('id')->first()->causer_id)->toBe($this->user->id);
});

test('a channel that is an alert\'s only one is refused with the alert names, a shared one is deleted', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create();
    $rule = AlertRule::factory()->for($this->user)->create(['name' => 'Storm warnings']);
    $rule->notificationChannels()->attach($channel);

    apiChannelWriteCall($this, 'DELETE', "/api/v1/channels/{$channel->id}")
        ->assertConflict()
        ->assertJsonPath('error', 'channel_in_use')
        ->assertJsonPath('alerts', ['Storm warnings']);
    expect(NotificationChannel::find($channel->id))->not->toBeNull();

    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($this->user)->create());

    apiChannelWriteCall($this, 'DELETE', "/api/v1/channels/{$channel->id}")->assertNoContent();
    expect(NotificationChannel::find($channel->id))->toBeNull();
});

test('the phone\'s own push channel is read-only on every write', function (string $method, string $suffix, array $body) {
    $phone = NotificationChannel::factory()->for($this->user)->mobilePush()->create();

    apiChannelWriteCall($this, $method, "/api/v1/channels/{$phone->id}{$suffix}", $body)
        ->assertConflict()
        ->assertJsonPath('error', 'channel_managed_by_app');

    expect(NotificationChannel::find($phone->id))->not->toBeNull();
})->with([
    'rename' => ['PUT', '', ['label' => 'x']],
    'delete' => ['DELETE', '', []],
    'verify' => ['POST', '/verify', ['code' => '123456']],
    'resend' => ['POST', '/resend', []],
    'test' => ['POST', '/test', []],
]);

test('another subscriber\'s channel is a 404 on every write', function (string $method, string $suffix, array $body) {
    $theirs = NotificationChannel::factory()->create(['label' => 'Theirs']);

    apiChannelWriteCall($this, $method, "/api/v1/channels/{$theirs->id}{$suffix}", $body)->assertNotFound();

    expect($theirs->fresh()->label)->toBe('Theirs');
    Http::assertNothingSent();
})->with([
    'rename' => ['PUT', '', ['label' => 'x']],
    'delete' => ['DELETE', '', []],
    'verify' => ['POST', '/verify', ['code' => '123456']],
    'resend' => ['POST', '/resend', []],
    'test' => ['POST', '/test', []],
]);

test('sending is limited to ten an hour per subscriber, shared by create, resend and test', function () {
    $channel = NotificationChannel::factory()->for($this->user)->unverified()->create();
    RateLimiter::clear('app-channel-send:'.$this->user->id);

    foreach (range(1, 10) as $_) {
        apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$channel->id}/resend")->assertNoContent();
    }

    apiChannelWriteCall($this, 'POST', "/api/v1/channels/{$channel->id}/resend")->assertStatus(429);
    apiChannelWriteCall($this, 'POST', '/api/v1/channels', ['channel_type' => 'ntfy', 'topic' => 'wx'])->assertStatus(429);
});

test('during maintenance a write answers 503 with the maintenance message as plain text', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create();
    $settings = app(GeneralSettings::class);
    $settings->maintenance_mode = true;
    $settings->maintenance_message = 'Back soon.';
    $settings->save();

    $response = apiChannelWriteCall($this, 'PUT', "/api/v1/channels/{$channel->id}", ['label' => 'New']);

    $response->assertStatus(503);
    expect($response->getContent())->toBe('Back soon.')->and($channel->fresh()->label)->not->toBe('New');
});
