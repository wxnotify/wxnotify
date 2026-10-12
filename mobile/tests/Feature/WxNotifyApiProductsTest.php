<?php

use App\Exceptions\Api\Offline;
use App\Exceptions\Api\ValidationFailed;
use App\Services\WxNotifyApi;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => (new TokenStore)->put('9|secret'));

test('a product search sends only the filters that are set, with the limit and cursor, and returns data and meta', function () {
    Http::fake(['wx.test/api/v1/products*' => Http::response(['data' => [['id' => 1]], 'meta' => ['next_cursor' => 'abc']])]);

    $page = app(WxNotifyApi::class)->products(['q' => 'afd', 'type' => null, 'wfo' => 'MOB', 'period' => ''], 'cur', 10);

    expect($page)->toBe(['data' => [['id' => 1]], 'meta' => ['next_cursor' => 'abc']]);

    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://wx.test/api/v1/products?')
            && $query === ['q' => 'afd', 'wfo' => 'MOB', 'limit' => '10', 'before' => 'cur']
            && $request->hasHeader('Authorization', 'Bearer 9|secret');
    });
});

test('a search the API rejects throws a validation failure, and a dead connection reads as offline', function () {
    Http::fake(['wx.test/api/v1/products*' => Http::response(['message' => 'bad', 'errors' => ['q' => ['The q field must be at least 2 characters.']]], 422)]);

    expect(fn () => app(WxNotifyApi::class)->products(['q' => 'a']))->toThrow(ValidationFailed::class);

    $this->network->connected = false;

    expect(fn () => app(WxNotifyApi::class)->products([]))->toThrow(Offline::class);
});

test('the product types are fetched and unwrapped from data', function () {
    Http::fake(['wx.test/api/v1/product-types' => Http::response(['data' => [['category' => 'winter_weather', 'label' => 'Winter Weather', 'products' => [['id' => 3, 'name' => 'Winter Storm Warning']]]]])]);

    expect(app(WxNotifyApi::class)->productTypes()[0]['products'][0])->toBe(['id' => 3, 'name' => 'Winter Storm Warning']);
});
