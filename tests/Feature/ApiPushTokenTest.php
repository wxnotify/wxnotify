<?php

use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->accessToken = $this->user->createToken('Pixel 9', ['app']);
    $this->bearer = $this->accessToken->plainTextToken;
});

function pushRegister($test, array $body = [], ?string $bearer = null)
{
    // The guard caches the first request's user for the rest of the test.
    app('auth')->forgetGuards();

    return $test->withToken($bearer ?? $test->bearer)->putJson('/api/v1/push-token', $body + ['token' => 'fcm-token-1', 'platform' => 'android']);
}

test('registering creates a verified device channel tied to the calling token', function () {
    pushRegister($this)->assertNoContent();

    $channel = NotificationChannel::query()->where('channel_type', 'mobile_push')->sole();

    expect($channel->user_id)->toBe($this->user->id)
        ->and($channel->label)->toBe('Pixel 9')
        ->and($channel->personal_access_token_id)->toBe($this->accessToken->accessToken->id)
        ->and($channel->isVerified())->toBeTrue()
        ->and($channel->apprise_url)->toBeNull()
        ->and($channel->config)->toBe(['fcm_token' => 'fcm-token-1', 'platform' => 'android'])
        ->and($channel->device_token_hash)->toBe(hash('sha256', 'fcm-token-1'));
});

test('registering again from the same sign-in updates the one channel and keeps a renamed label', function () {
    pushRegister($this)->assertNoContent();
    NotificationChannel::query()->where('channel_type', 'mobile_push')->update(['label' => 'My phone']);

    pushRegister($this, ['token' => 'fcm-token-2', 'platform' => 'ios'])->assertNoContent();

    $channel = NotificationChannel::query()->where('channel_type', 'mobile_push')->sole();
    expect($channel->label)->toBe('My phone')
        ->and($channel->config)->toBe(['fcm_token' => 'fcm-token-2', 'platform' => 'ios'])
        ->and($channel->device_token_hash)->toBe(hash('sha256', 'fcm-token-2'));
});

test('a rule that already uses the channel keeps using it after a token refresh', function () {
    pushRegister($this)->assertNoContent();
    $channel = NotificationChannel::query()->where('channel_type', 'mobile_push')->sole();
    AlertRule::factory()->for($this->user)->create()->notificationChannels()->attach($channel->id);

    pushRegister($this, ['token' => 'fcm-token-2'])->assertNoContent();

    expect(DB::table('alert_rule_notification_channel')->where('notification_channel_id', $channel->id)->count())->toBe(1);
});

test('a phone handed to another account stops receiving the old account\'s alerts', function () {
    $other = User::factory()->create();
    $stale = NotificationChannel::factory()->for($other)->mobilePush('fcm-token-1')->create();

    pushRegister($this)->assertNoContent();

    expect(NotificationChannel::find($stale->id))->toBeNull()
        ->and(NotificationChannel::query()->where('channel_type', 'mobile_push')->sole()->user_id)->toBe($this->user->id);
});

test('an older sign-in of the same account on the same phone gives up its channel', function () {
    $older = $this->user->createToken('Pixel 9 (old install)', ['app']);
    $stale = NotificationChannel::factory()->for($this->user)->mobilePush('fcm-token-1')
        ->create(['personal_access_token_id' => $older->accessToken->id]);

    pushRegister($this)->assertNoContent();

    expect(NotificationChannel::find($stale->id))->toBeNull()
        ->and(NotificationChannel::query()->where('channel_type', 'mobile_push')->count())->toBe(1);
});

test('the request is validated', function (array $body) {
    $this->withToken($this->bearer)->putJson('/api/v1/push-token', $body)->assertUnprocessable();

    expect(NotificationChannel::query()->where('channel_type', 'mobile_push')->count())->toBe(0);
})->with([
    'no token' => [['platform' => 'ios']],
    'empty token' => [['token' => '', 'platform' => 'ios']],
    'token too long' => [['token' => str_repeat('a', 4097), 'platform' => 'ios']],
    'no platform' => [['token' => 'x']],
    'unknown platform' => [['token' => 'x', 'platform' => 'windows']],
]);

test('unregistering removes the calling token\'s channel and is repeatable', function () {
    pushRegister($this)->assertNoContent();

    $this->withToken($this->bearer)->deleteJson('/api/v1/push-token')->assertNoContent();
    $this->withToken($this->bearer)->deleteJson('/api/v1/push-token')->assertNoContent();

    expect(NotificationChannel::query()->where('channel_type', 'mobile_push')->count())->toBe(0);
});

test('unregistering leaves other devices alone', function () {
    pushRegister($this)->assertNoContent();
    $otherPhone = $this->user->createToken('iPad', ['app']);
    pushRegister($this, ['token' => 'fcm-token-ipad', 'platform' => 'ios'], $otherPhone->plainTextToken)->assertNoContent();

    $this->app['auth']->forgetGuards();
    $this->withToken($this->bearer)->deleteJson('/api/v1/push-token')->assertNoContent();

    expect(NotificationChannel::query()->where('channel_type', 'mobile_push')->sole()->label)->toBe('iPad');
});

test('signing out or revoking a device removes its channel', function () {
    pushRegister($this)->assertNoContent();

    $this->withToken($this->bearer)->deleteJson('/api/v1/auth/token')->assertNoContent();

    expect(NotificationChannel::query()->where('channel_type', 'mobile_push')->count())->toBe(0);
});

test('requests without the app ability are refused', function () {
    $script = $this->user->createToken('My script', ['api'])->plainTextToken;

    pushRegister($this, bearer: $script)->assertForbidden();
    $this->withToken($script)->deleteJson('/api/v1/push-token')->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->withoutToken()->putJson('/api/v1/push-token', ['token' => 'x', 'platform' => 'ios'])->assertUnauthorized();
});

test('the push token is never echoed, logged or written to the activity log', function () {
    pushRegister($this, ['token' => 'super-secret-fcm-token'])->assertNoContent()->assertContent('');

    expect(Activity::query()->get()->toJson())->not->toContain('super-secret-fcm-token');
    // The registration logs the channel as created, attributed to the subscriber.
    $entry = Activity::query()->where('subject_type', NotificationChannel::class)->where('event', 'created')->first();
    expect($entry)->not->toBeNull()->and($entry->causer_id)->toBe($this->user->id);
});
