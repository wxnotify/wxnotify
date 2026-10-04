<?php

use App\NativeComponents\AlertsScreen;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

function apiEntry(int $id, array $overrides = []): array
{
    return [
        'id' => $id,
        'subject' => "Tornado Warning {$id}",
        'category' => 'weather_alerts',
        'category_label' => 'Weather Alerts',
        'dispatched_at' => now()->subMinutes(5)->toIso8601String(),
        'channels' => [['type' => 'discord', 'type_label' => 'Discord', 'status' => 'success']],
        'has_failure' => false,
        ...$overrides,
    ];
}

function feedResponse(array $entries, ?string $next = null): array
{
    return ['data' => $entries, 'meta' => ['next_cursor' => $next]];
}

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('a signed-out visitor is sent to sign in', function () {
    (new TokenStore)->forget();

    Native::test(AlertsScreen::class)->assertReplacedWith('/sign-in');
});

test('the feed loads from the server, shows each alert, and refreshes the cache', function () {
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(1), apiEntry(2, ['has_failure' => true])]))]);

    Native::test(AlertsScreen::class)
        ->assertSee('Tornado Warning 1')
        ->assertSee('Weather Alerts · 5 minutes ago')
        ->assertSee('Sent via Discord')
        ->assertSee('Tornado Warning 2')
        ->assertSee('Delivery problem')
        ->assertElement('refreshable')
        ->assertSet('offline', false);

    expect(array_column((new LocalCache)->feed(), 'id'))->toBe([2, 1]);
});

test('the cached feed paints first, then the refresh replaces it', function () {
    (new LocalCache)->replaceFeed([apiEntry(7, ['subject' => 'Cached alert'])]);
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(8, ['subject' => 'Fresh alert'])]))]);

    Native::test(AlertsScreen::class)->assertSee('Fresh alert')->assertDontSee('Cached alert');
});

test('offline, the cached feed stays with a banner and nothing breaks', function () {
    (new LocalCache)->replaceFeed([apiEntry(7, ['subject' => 'Cached alert'])]);
    $this->network->connected = false;
    Http::fake();

    Native::test(AlertsScreen::class)
        ->assertSee('Cached alert')
        ->assertSee("You're offline. Showing alerts from")
        ->assertSet('offline', true);

    Http::assertNothingSent();
});

test('a dropped connection shows the offline banner too', function () {
    (new LocalCache)->replaceFeed([apiEntry(7, ['subject' => 'Cached alert'])]);
    Http::fake(fn () => throw new ConnectionException('no route'));

    Native::test(AlertsScreen::class)->assertSee('Cached alert')->assertSet('offline', true);
});

test('the banner shows the time in the subscriber\'s timezone when it is known', function () {
    (new LocalCache)->replaceFeed([apiEntry(7)]);
    (new LocalCache)->rememberProfile(['timezone' => 'America/Chicago']);
    $this->network->connected = false;

    Native::test(AlertsScreen::class)->assertSee('CDT')->assertDontSee('minutes ago.');
});

test('a refused token wipes the phone and returns to sign in', function () {
    (new LocalCache)->replaceFeed([apiEntry(7)]);
    Http::fake(['*' => Http::response([], 401)]);

    Native::test(AlertsScreen::class)->assertReplacedWith('/sign-in');

    expect((new TokenStore)->token())->toBeNull()->and((new LocalCache)->feed())->toBe([]);
});

test('a server error shows a retry message and keeps the cached feed', function () {
    (new LocalCache)->replaceFeed([apiEntry(7, ['subject' => 'Cached alert'])]);
    Http::fake(['*' => Http::response([], 500)]);

    Native::test(AlertsScreen::class)
        ->assertSee('Cached alert')
        ->assertSee('could not load your alerts');
});

test('an empty feed says there are no alerts yet', function () {
    Http::fake(['*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class)->assertSee('No alerts yet');
});

test('load more appends the next page with the cursor and stops when there is none', function () {
    Http::fake([
        'wx.test/api/v1/alerts?limit=50' => Http::response(feedResponse([apiEntry(2)], 'CURSOR1')),
        'wx.test/api/v1/alerts?limit=25&before=CURSOR1' => Http::response(feedResponse([apiEntry(1)])),
    ]);

    Native::test(AlertsScreen::class)
        ->assertSee('Load more')
        ->tap('Load more')
        ->assertSee('Tornado Warning 1')
        ->assertDontSee('Load more')
        ->assertSet('nextCursor', null);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'before=CURSOR1'));
});

test('load more is unavailable offline', function () {
    Http::fake(['*' => Http::response(feedResponse([apiEntry(2)], 'CURSOR1'))]);

    $screen = Native::test(AlertsScreen::class);
    $this->network->connected = false;
    $screen->tap('Load more')->assertSet('offline', true);

    Http::assertSentCount(1);
});

test('tapping an alert opens its detail', function () {
    Http::fake(['*' => Http::response(feedResponse([apiEntry(4)]))]);

    Native::test(AlertsScreen::class)->tap('Tornado Warning 4')->assertNavigatedTo('/alerts/4');
});

test('the bottom bar has Alerts and Account', function () {
    Http::fake(['*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class)->assertHasTab('Alerts')->assertHasTab('Account');
});
