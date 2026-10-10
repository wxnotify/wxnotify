<?php

use App\NativeComponents\AlertsScreen;
use App\Support\LocalCache;
use App\Support\LocationSharingState;
use App\Support\PushState;
use App\Support\TokenStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\PushNotifications;
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
        'product_url' => null,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $node
 * @return array<int, array<string, mixed>>
 */
function findRows(array $node): array
{
    $rows = ($node['type'] ?? null) === 'list_item' ? [$node] : [];

    foreach ($node['children'] ?? [] as $child) {
        $rows = [...$rows, ...findRows($child)];
    }

    return $rows;
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

test('opening the feed while push is on asks the OS to enroll and sends no token itself', function () {
    (new PushState)->markEnabled();
    PushNotifications::shouldReceive('checkPermission')->andReturn('granted');
    PushNotifications::shouldReceive('getToken')->never();
    PushNotifications::shouldReceive('enroll')->once();
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class);

    Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
});

test('opening the feed while push is off enrolls nothing', function () {
    PushNotifications::shouldReceive('checkPermission')->andReturn('granted');
    PushNotifications::shouldReceive('enroll')->never();
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class);

    Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
});

test('the feed loads from the server, shows each alert, and refreshes the cache', function () {
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(1), apiEntry(2, ['has_failure' => true])]))]);

    Native::test(AlertsScreen::class)
        ->assertSee('Tornado Warning 1')
        ->assertSee('Weather Alerts · 5 minutes ago')
        ->assertSee('Sent via Discord')
        ->assertSee('Tornado Warning 2')
        ->assertSee('Delivery problem')
        ->assertElement('list', fn (array $list) => isset($list['props']['on_refresh'], $list['props']['on_end_reached'])
            && $list['props']['end_reached_buffer'] === 5
            && $list['props']['separator'] === true
            && $list['style']['bg_color'] === '#F8FAFC'
            && $list['props']['dark_bg_color'] === '#0F172A'
            && count($list['children']) === 2
            && count(array_filter($list['children'], fn (array $row) => array_key_exists('trailing_badges_json', $row['props']))) === 1)
        ->assertElement('list_item', fn (array $item) => $item['props']['headline'] === 'Tornado Warning 1'
            && $item['props']['overline'] === 'Weather Alerts · 5 minutes ago'
            && $item['props']['supporting'] === 'Sent via Discord'
            && ! array_key_exists('supporting_color', $item['props'])
            && ! array_key_exists('trailing_badges_json', $item['props']))
        ->assertElement('list_item', fn (array $item) => $item['props']['headline'] === 'Tornado Warning 2'
            && str_contains($item['props']['supporting'], 'Delivery problem')
            && ! array_key_exists('supporting_color', $item['props'])
            && json_decode($item['props']['trailing_badges_json'], true)[0]['color'] === '#EF4444'
            && count(json_decode($item['props']['trailing_badges_json'], true)) === 1)
        ->assertSet('offline', false);

    expect(array_column((new LocalCache)->feed(), 'id'))->toBe([2, 1]);
});

test('a delivery with no category shows only the time in the overline', function () {
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([
        apiEntry(1, ['category' => null, 'category_label' => null, 'subject' => 'Alert']),
    ]))]);

    Native::test(AlertsScreen::class)
        ->assertElement('list_item', fn (array $item) => $item['props']['overline'] === '5 minutes ago')
        ->assertDontSee('· ');
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

    Native::test(AlertsScreen::class)
        ->assertSee('No alerts yet')
        ->assertSee('Alerts you receive show up here')
        ->assertMissingElement('list_item')
        ->assertElement('list', fn (array $list) => isset($list['props']['on_refresh']));
});

test('load more appends the next page with the cursor and stops when there is none', function () {
    Http::fake([
        'wx.test/api/v1/alerts?limit=50' => Http::response(feedResponse([apiEntry(2)], 'CURSOR1')),
        'wx.test/api/v1/alerts?limit=25&before=CURSOR1' => Http::response(feedResponse([apiEntry(1)])),
    ]);

    Native::test(AlertsScreen::class)
        ->assertDontSee('Tornado Warning 1')
        ->assertSet('nextCursor', 'CURSOR1')
        ->call('loadMore')
        ->assertSee('Tornado Warning 1')
        ->assertSet('nextCursor', null)
        ->assertSet('pagedBeyondFirst', true)
        ->assertMissingElement('button')
        ->assertElement('list', fn (array $list) => count($list['children']) === 2);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'before=CURSOR1'));
});

test('load more sends nothing without a next cursor', function () {
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(1)]))]);

    Native::test(AlertsScreen::class)
        ->call('loadMore')
        ->call('loadMore')
        ->assertSet('pagedBeyondFirst', false);

    Http::assertSentCount(1);
});

test('a load more while one is already running sends no extra request', function () {
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(2)], 'CURSOR1'))]);

    Native::test(AlertsScreen::class)
        ->set('loadingMore', true)
        ->call('loadMore')
        ->assertSet('pagedBeyondFirst', false)
        ->assertSet('loadingMore', true);

    Http::assertSentCount(1);
});

test('a load more within a second of a page loading is ignored, and works again after it', function () {
    Http::fake([
        'wx.test/api/v1/alerts?limit=50' => Http::response(feedResponse([apiEntry(3)], 'CURSOR2')),
        'wx.test/api/v1/alerts?limit=25&before=CURSOR2' => Http::response(feedResponse([apiEntry(2)], 'CURSOR1')),
        'wx.test/api/v1/alerts?limit=25&before=CURSOR1' => Http::response(feedResponse([apiEntry(1)])),
    ]);

    $this->freezeTime();
    $loadedAt = now();

    $screen = Native::test(AlertsScreen::class)
        ->call('loadMore')
        ->assertSet('loadingMore', false)
        ->assertSet('nextCursor', 'CURSOR1')
        ->call('loadMore')
        ->assertSet('nextCursor', 'CURSOR1');

    Http::assertSentCount(2);

    $this->travelTo($loadedAt->copy()->addMilliseconds(999));
    $screen->call('loadMore')->assertDontSee('Tornado Warning 1')->assertSet('nextCursor', 'CURSOR1');

    Http::assertSentCount(2);

    $this->travelTo($loadedAt->copy()->addMilliseconds(1001));
    $screen->call('loadMore')->assertSee('Tornado Warning 1')->assertSet('nextCursor', null);

    Http::assertSentCount(3);
});

test('a failed load more releases the guard and leaves the cursor so a later one can retry', function () {
    Http::fake([
        'wx.test/api/v1/alerts?limit=50' => Http::response(feedResponse([apiEntry(2)], 'CURSOR1')),
        'wx.test/api/v1/alerts?limit=25&before=CURSOR1' => Http::response([], 500),
    ]);

    Native::test(AlertsScreen::class)
        ->call('loadMore')
        ->assertSet('loadingMore', false)
        ->assertSet('lastPageLoadedAt', null)
        ->assertSet('nextCursor', 'CURSOR1');
});

test('a refresh that prepends an alert puts it first and the rows are not keyed', function () {
    Http::fake(['wx.test/api/v1/alerts*' => Http::sequence()
        ->push(feedResponse([apiEntry(2), apiEntry(1)]))
        ->push(feedResponse([apiEntry(3), apiEntry(2), apiEntry(1)])),
    ]);

    $rows = fn (array $tree): array => array_values(findRows($tree));

    $screen = Native::test(AlertsScreen::class);
    $before = $rows($screen->tree());
    $after = $rows($screen->call('refresh')->tree());

    $ids = array_column($after, 'id');

    expect($after[0]['props']['headline'])->toBe('Tornado Warning 3')
        ->and(array_column(array_column($after, 'props'), 'headline'))->toBe(['Tornado Warning 3', 'Tornado Warning 2', 'Tornado Warning 1'])
        // Un-keyed rows get positional ids (consecutive, and the top row keeps its id whichever alert it shows).
        // A native:key would give each row a hashed id that follows its alert instead, and Android's
        // LazyColumn would then keep the old first row anchored and hide the new alert above the viewport.
        ->and($ids)->toBe([$ids[0], $ids[0] + 1, $ids[0] + 2])
        ->and($ids[0])->toBe($before[0]['id']);
});

test('load more is unavailable offline', function () {
    Http::fake(['*' => Http::response(feedResponse([apiEntry(2)], 'CURSOR1'))]);

    $screen = Native::test(AlertsScreen::class);
    $this->network->connected = false;
    $screen->call('loadMore')->assertSet('offline', true)->assertSet('loadingMore', false);

    Http::assertSentCount(1);
});

test('tapping an alert with a product page opens it in the in-app browser', function () {
    Http::fake(['*' => Http::response(feedResponse([apiEntry(4, ['product_url' => 'https://wx.test/view-product/abc'])]))]);

    Native::test(AlertsScreen::class)->tap('Tornado Warning 4')->assertNoNavigation();

    expect($this->browser->inAppUrls)->toBe(['https://wx.test/view-product/abc']);
});

test('long-pressing an alert with a product page opens its detail instead', function () {
    Http::fake(['*' => Http::response(feedResponse([apiEntry(4, ['product_url' => 'https://wx.test/view-product/abc'])]))]);

    Native::test(AlertsScreen::class)->longPress('openDetail(4)')->assertNavigatedTo('/alerts/4');

    expect($this->browser->inAppUrls)->toBe([]);
});

test('tapping an alert with no product page opens its detail', function () {
    Http::fake(['*' => Http::response(feedResponse([apiEntry(4)]))]);

    Native::test(AlertsScreen::class)->tap('Tornado Warning 4')->assertNavigatedTo('/alerts/4');

    expect($this->browser->inAppUrls)->toBe([]);
});

test('the bottom bar has Alerts and Settings', function () {
    Http::fake(['*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class)->assertTabActive('Alerts')->assertHasTab('Settings');
});

test('coming back from a detail keeps the pages already loaded and sends no request', function () {
    Http::fake([
        'wx.test/api/v1/alerts?limit=50' => Http::response(feedResponse([apiEntry(2)], 'CURSOR1')),
        'wx.test/api/v1/alerts?limit=25&before=CURSOR1' => Http::response(feedResponse([apiEntry(1)])),
    ]);

    $screen = Native::test(AlertsScreen::class)->call('loadMore');

    $this->travel(2)->minutes();

    $screen->call('onResume')
        ->assertSee('Tornado Warning 2')
        ->assertSee('Tornado Warning 1')
        ->assertSet('pagedBeyondFirst', true);

    Http::assertSentCount(2);
});

test('coming back refreshes only when the feed is more than a minute old', function () {
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(2)]))]);

    $screen = Native::test(AlertsScreen::class);

    $this->travel(30)->seconds();
    $screen->call('onResume');
    Http::assertSentCount(1);

    $this->travel(2)->minutes();
    $screen->call('onResume');
    Http::assertSentCount(2);
});

test('a successful refresh after paging starts the list over from the first page', function () {
    Http::fake([
        'wx.test/api/v1/alerts?limit=50' => Http::response(feedResponse([apiEntry(2)], 'CURSOR1')),
        'wx.test/api/v1/alerts?limit=25&before=CURSOR1' => Http::response(feedResponse([apiEntry(1)])),
    ]);

    Native::test(AlertsScreen::class)
        ->call('loadMore')
        ->assertSet('pagedBeyondFirst', true)
        ->call('refresh')
        ->assertSet('pagedBeyondFirst', false)
        ->assertDontSee('Tornado Warning 1');
});

test('going offline after a failed load clears the stale error message', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $screen = Native::test(AlertsScreen::class)->assertSee('could not load your alerts');

    $this->network->connected = false;

    $screen->call('refresh')->assertSet('offline', true)->assertDontSee('could not load');
});

test('a server error after being offline clears the offline banner', function () {
    $this->network->connected = false;
    Http::fake(['*' => Http::response([], 500)]);

    $screen = Native::test(AlertsScreen::class)->assertSet('offline', true);

    $this->network->connected = true;

    $screen->call('refresh')->assertSet('offline', false)->assertSee('could not load your alerts');
});

test('offline with an empty cache the banner does not mention a sync time', function () {
    $this->network->connected = false;
    Http::fake();

    Native::test(AlertsScreen::class)
        ->assertSee("You're offline.")
        ->assertDontSee('Showing alerts from');
});

test('a successful load more after a failed one clears the error', function () {
    Http::fake([
        'wx.test/api/v1/alerts?limit=50' => Http::response(feedResponse([apiEntry(2)], 'CURSOR1')),
        'wx.test/api/v1/alerts?limit=25&before=CURSOR1' => Http::sequence()
            ->push([], 500)
            ->push(feedResponse([apiEntry(1)])),
    ]);

    Native::test(AlertsScreen::class)
        ->call('loadMore')
        ->assertSee('could not load more alerts')
        ->call('loadMore')
        ->assertDontSee('could not load more alerts')
        ->assertSee('Tornado Warning 1');
});

test('an HTML page where JSON was expected keeps the cached feed and shows the retry message', function () {
    (new LocalCache)->replaceFeed([apiEntry(7, ['subject' => 'Cached alert'])]);
    Http::fake(['*' => Http::response('<html>portal</html>', 200, ['Content-Type' => 'text/html'])]);

    Native::test(AlertsScreen::class)
        ->assertSee('Cached alert')
        ->assertSee('could not load your alerts');
});

test('opening the feed while location sharing is wanted checks it with the server once', function () {
    (new LocationSharingState)->markWanted();
    Http::fake([
        'wx.test/api/v1/alerts*' => Http::response(feedResponse([])),
        'wx.test/api/v1/location-sharing' => Http::response(['data' => ['linked' => true, 'location_id' => 7, 'interval_seconds' => 300]]),
    ]);
    $this->geolocation->status = ['id' => 'wxnotify-location', 'shareActive' => true, 'shareIntervalMs' => 300000, 'lastShareStatus' => 204, 'authorization' => 'always'];
    (new TokenStore)->putBeaconToken('12|b', 'https://wx.test/api/v1/location');

    Native::test(AlertsScreen::class);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://wx.test/api/v1/location-sharing');
});

test('opening the feed while location sharing is off sends nothing about it', function () {
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class);

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'location-sharing'));
});

test('tapping Settings opens a menu of Rules, Locations and Account', function () {
    Http::fake(['*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class)
        ->assertSet('settingsMenuOpen', false)
        ->press('openSettingsMenu')
        ->assertSet('settingsMenuOpen', true)
        ->assertElement('bottom_sheet', fn (array $sheet) => $sheet['props']['visible'] === true)
        ->assertSee('Rules')
        ->assertSee('Locations')
        ->assertSee('Account');
});

test('the Settings menu goes to the page chosen and closes', function (string $method, string $uri) {
    Http::fake(['*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class)
        ->press('openSettingsMenu')
        ->press($method)
        ->assertSet('settingsMenuOpen', false)
        ->assertReplacedWith($uri);
})->with([
    ['openRules', '/rules'],
    ['openLocations', '/locations'],
    ['openAccount', '/account'],
]);

test('dismissing the Settings menu closes it without navigating', function () {
    Http::fake(['*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class)
        ->press('openSettingsMenu')
        ->call('closeSettingsMenu')
        ->assertSet('settingsMenuOpen', false)
        ->assertNoNavigation();
});
