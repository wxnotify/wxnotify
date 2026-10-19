<?php

use App\Models\NotificationChannel;
use App\Models\User;
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

function badgePhoneFor(User $user, int $tokenId, int $count, string $platform = 'ios'): NotificationChannel
{
    return NotificationChannel::factory()->for($user)->mobilePush(null, $platform)
        ->create(['personal_access_token_id' => $tokenId, 'unread_push_count' => $count]);
}

test('it needs an app token', function () {
    badgeClearRequest($this)->assertUnauthorized();

    $beacon = $this->user->createToken('beacon', ['location'])->plainTextToken;
    badgeClearRequest($this, $beacon)->assertForbidden();
});

test('clearing resets the callers phone only', function () {
    $mine = badgePhoneFor($this->user, $this->accessToken->accessToken->id, 4);
    $second = $this->user->createToken('iPad', ['app']);
    $sibling = badgePhoneFor($this->user, $second->accessToken->id, 5);
    $other = User::factory()->create();
    $strangers = badgePhoneFor($other, $other->createToken('iPhone', ['app'])->accessToken->id, 6);

    badgeClearRequest($this, $this->bearer)->assertNoContent();

    expect($mine->fresh()->unread_push_count)->toBe(0)
        ->and($sibling->fresh()->unread_push_count)->toBe(5)
        ->and($strangers->fresh()->unread_push_count)->toBe(6);
});

test('a phone with no channel answers 204', function () {
    badgeClearRequest($this, $this->bearer)->assertNoContent();
});

test('an android channel answers 204 and is untouched', function () {
    $android = badgePhoneFor($this->user, $this->accessToken->accessToken->id, 3, 'android');

    badgeClearRequest($this, $this->bearer)->assertNoContent();

    expect($android->fresh()->unread_push_count)->toBe(3);
});

test('the response has no body and writes no activity row', function () {
    badgePhoneFor($this->user, $this->accessToken->accessToken->id, 2);
    $before = Activity::query()->count();

    $response = badgeClearRequest($this, $this->bearer)->assertNoContent();

    expect($response->getContent())->toBe('')
        ->and(Activity::query()->count())->toBe($before);
});
