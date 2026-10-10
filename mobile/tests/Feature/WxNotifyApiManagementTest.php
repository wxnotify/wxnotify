<?php

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Conflict;
use App\Exceptions\Api\LocationLimitReached;
use App\Exceptions\Api\Maintenance;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Retryable;
use App\Exceptions\Api\Unauthenticated;
use App\Exceptions\Api\ValidationFailed;
use App\Services\WxNotifyApi;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

function wxApi(): WxNotifyApi
{
    return app(WxNotifyApi::class);
}

test('the rule calls hit the right routes with the bearer token', function () {
    Http::fake([
        'wx.test/api/v1/alert-rules' => Http::response(['data' => [['id' => 1]]]),
        'wx.test/api/v1/alert-rules/1' => Http::response(['data' => ['id' => 1, 'is_active' => false]]),
        'wx.test/api/v1/alert-rule-options' => Http::response(['data' => ['categories' => []]]),
    ]);

    expect(wxApi()->alertRules())->toBe([['id' => 1]])
        ->and(wxApi()->alertRule(1))->toBe(['id' => 1, 'is_active' => false])
        ->and(wxApi()->setAlertRuleActive(1, false))->toBe(['id' => 1, 'is_active' => false])
        ->and(wxApi()->alertRuleOptions())->toBe(['categories' => []]);

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->url() === 'https://wx.test/api/v1/alert-rules/1' && $r['is_active'] === false && $r->hasHeader('Authorization', 'Bearer 9|secret'));
});

test('saving a rule creates with POST and replaces with PUT', function () {
    Http::fake(['wx.test/api/v1/alert-rules*' => Http::response(['data' => ['id' => 4]])]);

    wxApi()->saveAlertRule(null, ['category' => 'threshold']);
    wxApi()->saveAlertRule(4, ['category' => 'lightning']);
    wxApi()->deleteAlertRule(4);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://wx.test/api/v1/alert-rules' && $r['category'] === 'threshold');
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://wx.test/api/v1/alert-rules/4' && $r['category'] === 'lightning');
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://wx.test/api/v1/alert-rules/4');
});

test('the location and lookup calls hit the right routes', function () {
    Http::fake([
        'wx.test/api/v1/locations' => Http::response(['data' => [['id' => 2]], 'meta' => ['can_create' => true, 'limit' => 5]]),
        'wx.test/api/v1/locations/2' => Http::response(['data' => ['id' => 2, 'label' => 'Cabin']]),
        'wx.test/api/v1/nws-offices' => Http::response(['data' => [['code' => 'OKX', 'label' => 'OKX — New York, NY']]]),
        'wx.test/api/v1/zones*' => Http::response(['data' => [['code' => 'NYC103', 'label' => 'x']], 'meta' => ['next_after' => 'NYC103']]),
        'wx.test/api/v1/places/search*' => Http::response(['data' => [['label' => 'A', 'lat' => 35.2, 'lng' => -97.4]]]),
        'wx.test/api/v1/places/reverse*' => Http::response(['data' => ['address' => '1 Main Street']]),
    ]);

    expect(wxApi()->locations())->toBe(['data' => [['id' => 2]], 'meta' => ['can_create' => true, 'limit' => 5]])
        ->and(wxApi()->saveLocation(2, ['label' => 'Cabin']))->toBe(['id' => 2, 'label' => 'Cabin'])
        ->and(wxApi()->nwsOffices())->toBe([['code' => 'OKX', 'label' => 'OKX — New York, NY']])
        ->and(wxApi()->zones('NY', 'suffolk', 'NYC059'))->toBe(['data' => [['code' => 'NYC103', 'label' => 'x']], 'next_after' => 'NYC103'])
        ->and(wxApi()->searchPlaces('main street'))->toBe([['label' => 'A', 'lat' => 35.2, 'lng' => -97.4]])
        ->and(wxApi()->reversePlace(35.2, -97.4))->toBe('1 Main Street');

    wxApi()->saveLocation(null, ['label' => 'New']);
    wxApi()->deleteLocation(2);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://wx.test/api/v1/locations');
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://wx.test/api/v1/locations/2');
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://wx.test/api/v1/zones?') && str_contains($r->url(), 'state=NY') && str_contains($r->url(), 'q=suffolk') && str_contains($r->url(), 'after=NYC059'));
});

test('a validation answer becomes the first message per field, with nested keys reduced to their root', function () {
    Http::fake(['*' => Http::response([
        'message' => 'The given data was invalid.',
        'errors' => ['channel_ids.0' => ['The selected channel ids.0 is invalid.', 'second'], 'channel_ids' => ['Pick one.'], 'threshold_value' => ['Required.']],
    ], 422)]);

    try {
        wxApi()->saveAlertRule(null, []);
        $this->fail('Expected ValidationFailed');
    } catch (ValidationFailed $e) {
        expect($e->errors)->toBe(['channel_ids' => 'The selected channel ids.0 is invalid.', 'threshold_value' => 'Required.'])
            ->and($e->getMessage())->toBe('The given data was invalid.');
    }
});

test('a conflict carries its reason, message and the alert names', function () {
    Http::fake(['*' => Http::response(['error' => 'location_in_use', 'message' => "Can't delete Home.", 'alerts' => ['Freeze', 'Storm']], 409)]);

    try {
        wxApi()->deleteLocation(1);
        $this->fail('Expected Conflict');
    } catch (Conflict $e) {
        expect($e->reason)->toBe('location_in_use')->and($e->getMessage())->toBe("Can't delete Home.")->and($e->alerts)->toBe(['Freeze', 'Storm']);
    }
});

test('a plain-text 503 is maintenance with the server\'s words, not a retryable error', function () {
    Http::fake(['*' => Http::sequence()
        ->push('Back soon.', 503, ['Content-Type' => 'text/plain; charset=UTF-8'])
        ->push('', 503, ['Content-Type' => 'text/plain'])
        ->push(['message' => 'Server Error'], 503)]);

    expect(fn () => wxApi()->saveAlertRule(null, []))->toThrow(Maintenance::class, 'Back soon.')
        ->and(fn () => wxApi()->saveAlertRule(null, []))->toThrow(Maintenance::class, 'WxNotify is down for maintenance.')
        ->and(fn () => wxApi()->saveAlertRule(null, []))->toThrow(Retryable::class);
});

test('the existing failure mapping still holds', function () {
    Http::fake(['wx.test/api/v1/alert-rules' => Http::sequence()
        ->push([], 401)
        ->push([], 502)
        ->push(['error' => 'location_limit'], 422)]);

    expect(fn () => wxApi()->alertRules())->toThrow(Unauthenticated::class)
        ->and(fn () => wxApi()->alertRules())->toThrow(Retryable::class)
        ->and(fn () => wxApi()->alertRules())->toThrow(LocationLimitReached::class);

    $this->network->connected = false;

    expect(fn () => wxApi()->alertRules())->toThrow(Offline::class);
});

test('a response without the data wrapper is a transient failure', function () {
    Http::fake(['*' => Http::response(['unexpected' => true])]);

    expect(fn () => wxApi()->alertRules())->toThrow(Retryable::class)
        ->and(fn () => wxApi()->alertRules())->toThrow(ApiException::class);
});
