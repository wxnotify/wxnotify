<?php

use App\Services\WxNotifyApi;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('the map snapshot is fetched from /api/v1/map and unwrapped from its data key', function () {
    (new TokenStore)->put('9|secret');
    Http::fake(['wx.test/api/v1/map' => Http::response(['data' => ['places' => [], 'warnings' => []]])]);

    expect(app(WxNotifyApi::class)->map())->toBe(['places' => [], 'warnings' => []]);

    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://wx.test/api/v1/map' && $r->hasHeader('Authorization', 'Bearer 9|secret'));
});
