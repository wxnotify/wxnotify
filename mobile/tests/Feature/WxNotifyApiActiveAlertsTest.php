<?php

use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Unauthenticated;
use App\Services\WxNotifyApi;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => (new TokenStore)->put('9|secret'));

test('the active alerts are fetched from /api/v1/active-alerts and returned with their meta', function () {
    $body = ['data' => [['id' => 1, 'event' => 'Tornado Warning']], 'meta' => ['has_places' => true, 'generated_at' => '2026-10-12T14:14:00+00:00']];
    Http::fake(['wx.test/api/v1/active-alerts' => Http::response($body)]);

    expect(app(WxNotifyApi::class)->activeAlerts())->toBe($body);

    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://wx.test/api/v1/active-alerts' && $r->hasHeader('Authorization', 'Bearer 9|secret'));
});

test('a rejected token and a dead connection read as unauthenticated and offline', function () {
    Http::fake(['wx.test/api/v1/active-alerts' => Http::response([], 401)]);

    expect(fn () => app(WxNotifyApi::class)->activeAlerts())->toThrow(Unauthenticated::class);

    $this->network->connected = false;

    expect(fn () => app(WxNotifyApi::class)->activeAlerts())->toThrow(Offline::class);
});
