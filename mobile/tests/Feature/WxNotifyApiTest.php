<?php

use App\Exceptions\Api\LocationLimitReached;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Rejected;
use App\Exceptions\Api\Retryable;
use App\Exceptions\Api\Unauthenticated;
use App\Services\WxNotifyApi;
use App\Support\TokenStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    (new TokenStore)->put('9|secret');
    $this->api = app(WxNotifyApi::class);
});

test('requests carry the bearer token and ask for JSON', function () {
    Http::fake(['wx.test/api/v1/me' => Http::response(['data' => ['id' => 1, 'name' => 'Pat']])]);

    expect($this->api->me())->toBe(['id' => 1, 'name' => 'Pat']);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://wx.test/api/v1/me'
        && $r->hasHeader('Authorization', 'Bearer 9|secret')
        && $r->hasHeader('Accept', 'application/json'));
});

test('the feed passes the cursor and limit', function () {
    Http::fake(['wx.test/*' => Http::response(['data' => [], 'meta' => ['next_cursor' => null]])]);

    $this->api->alerts('abc', 50);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'before=abc') && str_contains($r->url(), 'limit=50'));
});

test('one alert is unwrapped from its data key', function () {
    Http::fake(['wx.test/api/v1/alerts/7' => Http::response(['data' => ['id' => 7]])]);

    expect($this->api->alert(7))->toBe(['id' => 7]);
});

test('the code exchange sends no bearer token', function () {
    Http::fake(['wx.test/*' => Http::response(['token' => '1|x', 'expires_at' => '2027-01-01T00:00:00+00:00', 'user' => ['id' => 1]])]);
    (new TokenStore)->forget();

    expect($this->api->exchangeCode('code', 'verifier')['token'])->toBe('1|x');

    Http::assertSent(fn (Request $r) => ! $r->hasHeader('Authorization')
        && $r['code'] === 'code' && $r['code_verifier'] === 'verifier');
});

test('a 401 is an unauthenticated error', function () {
    Http::fake(['*' => Http::response([], 401)]);

    expect(fn () => $this->api->me())->toThrow(Unauthenticated::class);
});

test('no stored token is an unauthenticated error without a request', function () {
    (new TokenStore)->forget();
    Http::fake();

    expect(fn () => $this->api->me())->toThrow(Unauthenticated::class);
    Http::assertNothingSent();
});

test('429 and 5xx are retryable', function (int $status) {
    Http::fake(['*' => Http::response([], $status)]);

    expect(fn () => $this->api->me())->toThrow(Retryable::class);
})->with([429, 500, 503]);

test('a rejected exchange is a rejection', function () {
    Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 422)]);

    expect(fn () => $this->api->exchangeCode('code', 'verifier'))->toThrow(Rejected::class);
});

test('a dropped connection is offline', function () {
    Http::fake(fn () => throw new ConnectionException('no route'));

    expect(fn () => $this->api->me())->toThrow(Offline::class);
});

test('a device that reports no network is offline without a request', function () {
    $this->network->connected = false;
    Http::fake();

    expect(fn () => $this->api->me())->toThrow(Offline::class);
    Http::assertNothingSent();
});

test('signing out sends a DELETE and tolerates the empty 204 body', function () {
    Http::fake(['wx.test/api/v1/auth/token' => Http::response(null, 204)]);

    $this->api->signOut();

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE');
});

test('a successful response that is not JSON is a retryable error, not a crash', function () {
    Http::fake(['*' => Http::response('<html>portal</html>', 200, ['Content-Type' => 'text/html'])]);

    expect(fn () => $this->api->me())->toThrow(Retryable::class)
        ->and(fn () => $this->api->alerts())->toThrow(Retryable::class)
        ->and(fn () => $this->api->alert(1))->toThrow(Retryable::class)
        ->and(fn () => $this->api->exchangeCode('code', 'verifier'))->toThrow(Retryable::class);
});

test('JSON missing the expected shape is a retryable error', function () {
    Http::fake(['*' => Http::response(['unexpected' => true])]);

    expect(fn () => $this->api->me())->toThrow(Retryable::class)
        ->and(fn () => $this->api->alerts())->toThrow(Retryable::class)
        ->and(fn () => $this->api->exchangeCode('code', 'verifier'))->toThrow(Retryable::class);
});

test('an empty success response, such as the sign-out 204, is fine', function () {
    Http::fake(['*' => Http::response(null, 204)]);

    $this->api->signOut();

    Http::assertSentCount(1);
});

test('turning location sharing on returns the beacon details', function () {
    $data = ['location_id' => 7, 'beacon_token' => '12|beacon', 'url' => 'https://wx.test/api/v1/location', 'interval_seconds' => 300];
    Http::fake(['wx.test/api/v1/location-sharing' => Http::response(['data' => $data])]);

    expect($this->api->enableLocationSharing())->toBe($data);

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->hasHeader('Authorization', 'Bearer 9|secret'));
});

test('a plan at its location limit is a LocationLimitReached, any other 422 is Rejected', function () {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::sequence()
        ->push(['error' => 'location_limit'], 422)
        ->push(['error' => 'something_else'], 422)]);

    expect(fn () => $this->api->enableLocationSharing())->toThrow(LocationLimitReached::class)
        ->and(fn () => $this->api->enableLocationSharing())->toThrow(Rejected::class);
});

test('turning location sharing off accepts the empty 204', function () {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::response(null, 204)]);

    $this->api->disableLocationSharing();

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE');
});
