<?php

use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Rejected;
use App\Exceptions\Api\Retryable;
use App\Exceptions\Api\Unauthenticated;
use App\Services\WxNotifyApi;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    (new TokenStore)->put('9|secret');
    $this->api = app(WxNotifyApi::class);
});

test('the feed keeps each entry\'s read flag and surfaces the unread count from meta', function () {
    Http::fake(['wx.test/api/v1/alerts*' => Http::response([
        'data' => [['id' => 3, 'read' => false], ['id' => 2, 'read' => true]],
        'meta' => ['next_cursor' => 'abc', 'unread_count' => 4],
    ])]);

    $page = $this->api->alerts();

    expect(array_column($page['data'], 'read', 'id'))->toBe([3 => false, 2 => true])
        ->and($page['meta']['next_cursor'])->toBe('abc')
        ->and($page['meta']['unread_count'])->toBe(4);
});

test('a feed without a usable unread count reports none rather than a guess', function (mixed $meta) {
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(['data' => [], 'meta' => $meta])]);

    expect($this->api->alerts()['meta']['unread_count'])->toBeNull();
})->with([
    'missing' => [['next_cursor' => null]],
    'not a number' => [['next_cursor' => null, 'unread_count' => 'many']],
]);

test('marking one alert read posts to it and returns the new unread count', function () {
    Http::fake(['wx.test/api/v1/alerts/7/read' => Http::response(['data' => ['unread_count' => 2]])]);

    expect($this->api->markAlertRead(7))->toBe(2);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://wx.test/api/v1/alerts/7/read'
        && $r->hasHeader('Authorization', 'Bearer 9|secret'));
});

test('marking all alerts read posts once and returns the new unread count', function () {
    Http::fake(['wx.test/api/v1/alerts/read-all' => Http::response(['data' => ['unread_count' => 0]])]);

    expect($this->api->markAllAlertsRead())->toBe(0);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://wx.test/api/v1/alerts/read-all');
});

test('the read calls map failures like every other call', function (int $status, string $exception) {
    Http::fake(['wx.test/*' => Http::response([], $status)]);

    expect(fn () => $this->api->markAlertRead(7))->toThrow($exception)
        ->and(fn () => $this->api->markAllAlertsRead())->toThrow($exception);
})->with([
    'not in the feed' => [404, Rejected::class],
    'signed out' => [401, Unauthenticated::class],
    'server error' => [500, Retryable::class],
]);

test('a read call answered without a count is retryable', function () {
    Http::fake(['wx.test/*' => Http::response(['data' => ['unread_count' => null]])]);

    expect(fn () => $this->api->markAlertRead(7))->toThrow(Retryable::class);
});

test('a read call offline sends nothing', function () {
    $this->network->connected = false;
    Http::fake();

    expect(fn () => $this->api->markAlertRead(7))->toThrow(Offline::class);

    Http::assertNothingSent();
});
