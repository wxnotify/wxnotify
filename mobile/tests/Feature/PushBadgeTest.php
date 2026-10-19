<?php

use App\Services\PushBadge;
use App\Services\SignOut;
use App\Support\PushState;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\PushNotifications;

beforeEach(function () {
    app()->forgetInstance(PushBadge::class);
    (new TokenStore)->put('9|secret');
});

function badgeResetSent(): Closure
{
    return fn (Request $r) => $r->method() === 'POST'
        && $r->url() === 'https://wx.test/api/v1/push-token/badge/clear';
}

test('clear calls the plugin and the server', function () {
    PushNotifications::shouldReceive('clearBadge')->once();
    Http::fake(['wx.test/api/v1/*' => Http::response(null, 204)]);

    app(PushBadge::class)->clear();

    Http::assertSent(badgeResetSent());
    expect((new PushState)->resetPending())->toBeFalse();
});

test('a failed server call is swallowed and marks a reset pending', function () {
    PushNotifications::shouldReceive('clearBadge')->once();
    Http::fake(['wx.test/api/v1/*' => Http::response(null, 500)]);

    app(PushBadge::class)->clear();

    expect((new PushState)->resetPending())->toBeTrue();
});

test('an expired sign-in is swallowed too', function () {
    PushNotifications::shouldReceive('clearBadge')->once();
    Http::fake(['wx.test/api/v1/*' => Http::response(null, 401)]);

    app(PushBadge::class)->clear();

    expect((new PushState)->resetPending())->toBeTrue();
});

test('the next clear retries and clears the pending flag', function () {
    PushNotifications::shouldReceive('clearBadge')->twice();
    Http::fake(['wx.test/api/v1/*' => Http::sequence()->push(null, 500)->push(null, 204)]);

    app(PushBadge::class)->clear();
    app(PushBadge::class)->clear();

    Http::assertSentCount(2);
    expect((new PushState)->resetPending())->toBeFalse();
});

test('with server false and nothing pending only the local clear runs', function () {
    PushNotifications::shouldReceive('clearBadge')->once();
    Http::fake();

    app(PushBadge::class)->clear(server: false);

    Http::assertNothingSent();
});

test('with server false and a reset pending, the pending reset is retried', function () {
    PushNotifications::shouldReceive('clearBadge')->once();
    Http::fake(['wx.test/api/v1/*' => Http::response(null, 204)]);
    (new PushState)->markResetPending();

    app(PushBadge::class)->clear(server: false);

    Http::assertSent(badgeResetSent());
    expect((new PushState)->resetPending())->toBeFalse();
});

test('signed out: the local clear only, no request', function () {
    (new TokenStore)->forget();
    PushNotifications::shouldReceive('clearBadge')->once();
    Http::fake();

    app(PushBadge::class)->clear();

    Http::assertNothingSent();
});

test('signing out forgets the pending flag', function () {
    (new PushState)->markResetPending();
    Http::fake(['*' => Http::response(null, 204)]);

    app(SignOut::class)();

    expect((new PushState)->resetPending())->toBeFalse();
});

test('a failing local clear does not throw or skip the server reset', function () {
    PushNotifications::shouldReceive('clearBadge')->once()->andThrow(new RuntimeException('plugin down'));
    Http::fake(['wx.test/api/v1/*' => Http::response(null, 204)]);

    app(PushBadge::class)->clear();

    Http::assertSent(badgeResetSent());
});

test('retrying with no reset pending sends nothing and leaves the badge alone', function () {
    PushNotifications::shouldReceive('clearBadge')->never();
    Http::fake();

    app(PushBadge::class)->retryPendingReset();

    Http::assertNothingSent();
});

test('retrying a pending reset calls only the server and clears the flag', function () {
    PushNotifications::shouldReceive('clearBadge')->never();
    Http::fake(['wx.test/api/v1/*' => Http::response(null, 204)]);
    (new PushState)->markResetPending();

    app(PushBadge::class)->retryPendingReset();

    Http::assertSent(badgeResetSent());
    expect((new PushState)->resetPending())->toBeFalse();
});

test('a failed retry is swallowed and stays pending', function () {
    PushNotifications::shouldReceive('clearBadge')->never();
    Http::fake(['wx.test/api/v1/*' => Http::response(null, 500)]);
    (new PushState)->markResetPending();

    app(PushBadge::class)->retryPendingReset();

    expect((new PushState)->resetPending())->toBeTrue();
});

test('retrying while signed out sends nothing', function () {
    (new TokenStore)->forget();
    (new PushState)->markResetPending();
    PushNotifications::shouldReceive('clearBadge')->never();
    Http::fake();

    app(PushBadge::class)->retryPendingReset();

    Http::assertNothingSent();
});
