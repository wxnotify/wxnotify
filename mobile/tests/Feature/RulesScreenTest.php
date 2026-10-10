<?php

use App\NativeComponents\RulesScreen;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

function listedRule(array $overrides = []): array
{
    return [
        'id' => 1, 'name' => 'Freeze', 'display_name' => 'Freeze', 'category' => 'threshold', 'category_label' => 'Threshold Alerts',
        'is_active' => true, ...$overrides,
    ];
}

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('the rules list shows each rule\'s name, its category and an on/off switch', function () {
    Http::fake(['wx.test/api/v1/alert-rules' => Http::response(['data' => [
        listedRule(),
        listedRule(['id' => 2, 'name' => null, 'display_name' => 'Tornado Warning', 'category' => 'weather_alerts', 'category_label' => 'Weather Alerts', 'is_active' => false]),
    ]])]);

    Native::test(RulesScreen::class)
        ->assertSee('Freeze')
        ->assertSee('Threshold Alerts')
        ->assertSee('Tornado Warning')
        ->assertSee('Weather Alerts')
        ->assertSee('Off')
        ->assertElement('toggle', fn (array $node) => ($node['props']['value'] ?? null) === false)
        ->assertHasTab('Rules')
        ->assertTabActive('Rules');
});

test('the list is remembered for offline use', function () {
    Http::fake(['wx.test/api/v1/alert-rules' => Http::response(['data' => [listedRule()]])]);

    Native::test(RulesScreen::class);

    expect((new LocalCache)->rules())->toBe([listedRule()]);
});

test('offline, the remembered list is shown with a note', function () {
    (new LocalCache)->rememberRules([listedRule()]);
    $this->network->connected = false;
    Http::fake();

    Native::test(RulesScreen::class)->assertSee('Freeze')->assertSee('offline');
});

test('offline with nothing remembered says so', function () {
    $this->network->connected = false;
    Http::fake();

    Native::test(RulesScreen::class)->assertSee('offline')->assertSee('No alert rules yet');
});

test('with no rules the screen explains how to add one', function () {
    Http::fake(['wx.test/api/v1/alert-rules' => Http::response(['data' => []])]);

    Native::test(RulesScreen::class)->assertSee('No alert rules yet')->assertSee('Add rule');
});

test('a load that fails shows the reason and keeps the remembered list, whether maintenance or not', function (int $status, string $text) {
    (new LocalCache)->rememberRules([listedRule()]);
    Http::fake(['wx.test/api/v1/alert-rules' => Http::response('Back soon.', $status, ['Content-Type' => 'text/plain'])]);

    Native::test(RulesScreen::class)->assertSee('Freeze')->assertSee($text);
})->with([
    'maintenance' => [503, 'Back soon.'],
    'a server error' => [500, 'could not load your alert rules'],
]);

test('switching a rule off updates the server and keeps the new state', function () {
    Http::fake([
        'wx.test/api/v1/alert-rules' => Http::response(['data' => [listedRule()]]),
        'wx.test/api/v1/alert-rules/1' => Http::response(['data' => listedRule(['is_active' => false])]),
    ]);

    Native::test(RulesScreen::class)->toggle('active-1', false)->assertSet('rules.0.is_active', false);

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['is_active'] === false);
    expect((new LocalCache)->rules()[0]['is_active'])->toBeFalse();
});

test('a refused switch reverts and says why', function (int $status, string $text) {
    Http::fake([
        'wx.test/api/v1/alert-rules' => Http::response(['data' => [listedRule()]]),
        'wx.test/api/v1/alert-rules/1' => Http::response('Back soon.', $status, ['Content-Type' => 'text/plain']),
    ]);

    Native::test(RulesScreen::class)->toggle('active-1', false)->assertSet('rules.0.is_active', true)->assertSee($text);
})->with([
    'maintenance' => [503, 'Back soon.'],
    'an unexpected refusal' => [404, 'Couldn\'t update that alert'],
]);

test('switching a rule while offline reverts it and says so', function () {
    Http::fake(['wx.test/api/v1/alert-rules' => Http::response(['data' => [listedRule()]])]);

    $screen = Native::test(RulesScreen::class);
    $this->network->connected = false;

    $screen->toggle('active-1', false)->assertSet('rules.0.is_active', true)->assertSee('offline');
});

test('tapping a rule opens its editor and the add button opens a new one', function () {
    Http::fake(['wx.test/api/v1/alert-rules' => Http::response(['data' => [listedRule()]])]);

    Native::test(RulesScreen::class)->tap('rule-1')->assertNavigatedTo('/rules/1');
    Native::test(RulesScreen::class)->tap('Add rule')->assertNavigatedTo('/rules/new');
});

test('a refused token returns to sign in and a signed-out visitor is sent there', function () {
    Http::fake(['*' => Http::response([], 401)]);
    Native::test(RulesScreen::class)->assertReplacedWith('/sign-in');

    (new TokenStore)->forget();
    Native::test(RulesScreen::class)->assertReplacedWith('/sign-in');
});
