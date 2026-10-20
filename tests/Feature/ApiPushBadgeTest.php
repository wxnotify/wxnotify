<?php

use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->accessToken = $this->user->createToken('iPhone', ['app']);
    $this->bearer = $this->accessToken->plainTextToken;
});

function badgeClearRequest($test, ?string $bearer = null)
{
    // The guard caches the first request's user for the rest of the test.
    app('auth')->forgetGuards();

    $request = $bearer === null ? $test : $test->withToken($bearer);

    return $request->postJson('/api/v1/push-token/badge/clear');
}

test('it needs an app token', function () {
    badgeClearRequest($this)->assertUnauthorized();

    $beacon = $this->user->createToken('beacon', ['location'])->plainTextToken;
    badgeClearRequest($this, $beacon)->assertForbidden();
});

test('the old builds\' badge clear answers 204 with no body and neither reads nor writes a channel', function (string $platform) {
    NotificationChannel::factory()->for($this->user)->mobilePush(null, $platform)
        ->create(['personal_access_token_id' => $this->accessToken->accessToken->id]);
    $activities = Activity::query()->count();
    app('auth')->forgetGuards();
    DB::enableQueryLog();

    $response = $this->withToken($this->bearer)->postJson('/api/v1/push-token/badge/clear')->assertNoContent();

    $channelQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'notification_channels'));

    expect($response->getContent())->toBe('')
        ->and($channelQueries)->toBeEmpty()
        ->and(Activity::query()->count())->toBe($activities);
})->with(['ios', 'android']);

test('a phone with no channel answers 204', function () {
    badgeClearRequest($this, $this->bearer)->assertNoContent();
});
