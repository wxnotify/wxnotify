<?php

use App\NativeComponents\AlertsScreen;
use App\Support\LocalCache;
use App\Support\LocationSharingState;
use App\Support\PushState;
use App\Support\TokenStore;
use App\Support\WelcomeState;
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
        'read' => true,
        'channels' => [['type' => 'mobile_push', 'type_label' => 'Mobile app', 'status' => 'success']],
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

function feedResponse(array $entries, ?string $next = null, ?int $unread = null): array
{
    $unread ??= count(array_filter($entries, fn (array $entry): bool => ($entry['read'] ?? true) === false));

    return ['data' => $entries, 'meta' => ['next_cursor' => $next, 'unread_count' => $unread]];
}

/**
 * True when the row carries the unread dot (a trailing badge in the theme's primary colour).
 *
 * @param  array<string, mixed>  $row
 */
function hasUnreadDot(array $row): bool
{
    $badges = json_decode($row['props']['trailing_badges_json'] ?? '[]', true);

    return in_array(config('native-ui.theme.light.primary'), array_column($badges, 'color'), true);
}

/**
 * @param  array<string, mixed>  $tree
 * @return array<string, bool> whether each row (by headline) shows the unread dot
 */
function unreadDots(array $tree): array
{
    return collect(sentAlertRows($tree))->mapWithKeys(fn (array $row): array => [$row['props']['headline'] => hasUnreadDot($row)])->sortKeys()->all();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function activeEvent(int $id, array $overrides = []): array
{
    return [
        'id' => $id,
        'event' => "Tornado Warning {$id}",
        'family' => 'warning',
        'places' => ['Home'],
        'expires' => now()->addHours(3)->toIso8601String(),
        'view_url' => "https://wx.test/view-product/e{$id}",
        ...$overrides,
    ];
}

/**
 * The rows of the alerts list (a flat list: the "Alerts sent to you" heading and the current-watches panel sit above it).
 *
 * @param  array<string, mixed>  $tree
 * @return array<int, array<string, mixed>>
 */
function sentAlertRows(array $tree): array
{
    $rows = [];
    $collect = function (array $node) use (&$collect, &$rows): void {
        if (($node['type'] ?? null) === 'list') {
            $rows = findRows($node);

            return;
        }

        foreach ($node['children'] ?? [] as $child) {
            $collect($child);
        }
    };
    $collect($tree);

    return array_values($rows);
}

beforeEach(function () {
    (new TokenStore)->put('9|secret');

    // Every mount also fetches the current-alerts section. A test changes its response by assigning these
    // before mounting (the first matching Http::fake stub wins, so the URL is never faked a second time).
    $this->activeAlerts = ['data' => [], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]];
    $this->activeAlertsStatus = 200;
    Http::fake(['wx.test/api/v1/active-alerts' => fn () => Http::response($this->activeAlerts, $this->activeAlertsStatus)]);
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
        ->assertDontSee('Sent via')
        ->assertSee('Tornado Warning 2')
        ->assertSee('Delivery problem')
        ->assertElement('list', fn (array $list) => isset($list['props']['on_refresh'], $list['props']['on_end_reached'])
            && $list['props']['end_reached_buffer'] === 5
            && $list['props']['separator'] === true
            && $list['style']['bg_color'] === '#F8FAFC'
            && $list['props']['dark_bg_color'] === '#0F172A'
            && $list['props']['plain'] === true
            && array_column($list['children'], 'type') === ['list_item', 'list_item']
            && count(findRows($list)) === 2
            && count(array_filter(findRows($list), fn (array $row) => array_key_exists('trailing_badges_json', $row['props']))) === 1)
        ->assertElement('list_item', fn (array $item) => $item['props']['headline'] === 'Tornado Warning 1'
            && $item['props']['overline'] === 'Weather Alerts · 5 minutes ago'
            && ($item['props']['supporting'] ?? '') === ''
            && ! array_key_exists('supporting_color', $item['props'])
            && ! array_key_exists('trailing_badges_json', $item['props']))
        ->assertElement('list_item', fn (array $item) => $item['props']['headline'] === 'Tornado Warning 2'
            && $item['props']['supporting'] === 'Delivery problem'
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
        ->assertElement('list_item', fn (array $item) => ($item['props']['overline'] ?? null) === '5 minutes ago')
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
        ->assertElement('list', fn (array $list) => isset($list['props']['on_refresh'])
            && array_column(array_column(findRows($list), 'props'), 'headline') === ['No alerts yet']);
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
        ->assertElement('list', fn (array $list) => count(findRows($list)) === 2);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'before=CURSOR1'));
});

test('load more sends nothing without a next cursor', function () {
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(1)]))]);

    Native::test(AlertsScreen::class)
        ->call('loadMore')
        ->call('loadMore')
        ->assertSet('pagedBeyondFirst', false);

    Http::assertSentCount(2);
});

test('a load more while one is already running sends no extra request', function () {
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(2)], 'CURSOR1'))]);

    Native::test(AlertsScreen::class)
        ->set('loadingMore', true)
        ->call('loadMore')
        ->assertSet('pagedBeyondFirst', false)
        ->assertSet('loadingMore', true);

    Http::assertSentCount(2);
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

    Http::assertSentCount(3);

    $this->travelTo($loadedAt->copy()->addMilliseconds(999));
    $screen->call('loadMore')->assertDontSee('Tornado Warning 1')->assertSet('nextCursor', 'CURSOR1');

    Http::assertSentCount(3);

    $this->travelTo($loadedAt->copy()->addMilliseconds(1001));
    $screen->call('loadMore')->assertSee('Tornado Warning 1')->assertSet('nextCursor', null);

    Http::assertSentCount(4);
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

    $rows = fn (array $tree): array => sentAlertRows($tree);

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

    Http::assertSentCount(2);
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

test('the bottom bar has Alerts, Map and Settings', function () {
    Http::fake(['*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class)->assertTabActive('Alerts')->assertHasTab('Map')->assertHasTab('Settings');
});

test('coming back from a detail keeps the pages already loaded and sends no feed request', function () {
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

    Http::assertSentCount(4);
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'before=CURSOR1'))->count())->toBe(1);
});

test('coming back refreshes only when the feed is more than a minute old', function () {
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(2)]))]);

    $screen = Native::test(AlertsScreen::class);

    $this->travel(30)->seconds();
    $screen->call('onResume');
    Http::assertSentCount(2);

    $this->travel(2)->minutes();
    $screen->call('onResume');
    Http::assertSentCount(4);
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

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://wx.test/api/v1/location-sharing');
});

test('opening the feed while location sharing is off sends nothing about it', function () {
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class);

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'location-sharing'));
});

test('the panel lists the events in effect behind their family emoji with their end and places, above the sent alerts', function () {
    $this->freezeSecond();
    $this->activeAlerts = ['data' => [activeEvent(1), activeEvent(2, ['family' => 'watch', 'places' => ['Cabin', 'Home'], 'expires' => null]), activeEvent(3, ['family' => 'advisory'])], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]];
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(7)]))]);

    Native::test(AlertsScreen::class)
        ->assertSee('Current watches, warnings and advisories')
        ->assertSee('Alerts sent to you')
        ->assertMissingElement('list_section')
        ->assertSee('🚨 Tornado Warning 1')
        ->assertSee('Warning · Ends 3 hours from now')
        ->assertSee('👀 Tornado Warning 2')
        ->assertSee('Watch · No end time given')
        ->assertSee('⚠️ Tornado Warning 3')
        ->assertSee('Cabin, Home')
        ->assertSee('Tornado Warning 7');
});

test('an event row opens its product page, and a row without a page has no tap', function () {
    $this->activeAlerts = ['data' => [activeEvent(1), activeEvent(2, ['view_url' => null])], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]];
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    $screen = Native::test(AlertsScreen::class);
    $screen->assertElement('pressable', fn (array $row) => str_contains(json_encode($row), 'Tornado Warning 1') && isset($row['on_press']))
        ->assertMissingElement('pressable', fn (array $row) => str_contains(json_encode($row), 'Tornado Warning 2'))
        ->assertSee('Tornado Warning 2');

    $screen->call('openActive', 1);
    $screen->call('openActive', 2);
    $screen->call('openActive', 99);

    expect($this->browser->inAppUrls)->toBe(['https://wx.test/view-product/e1']);
});

test('only the first five show with Show all (N), and Show all reveals the rest and becomes Show fewer', function () {
    $this->activeAlerts = ['data' => array_map(fn (int $id) => activeEvent($id), range(1, 8)), 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]];
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class)
        ->assertSee('Tornado Warning 5')
        ->assertDontSee('Tornado Warning 6')
        ->assertSee('Show all (3)')
        ->tap('Show all (3)')
        ->assertSee('Tornado Warning 8')
        ->assertSee('Show fewer')
        ->tap('Show fewer')
        ->assertDontSee('Tornado Warning 6');
});

test('nothing in effect says so, and no places asks for a place or location sharing instead', function () {
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class)->assertSee('Nothing in effect at your places.');

    $this->activeAlerts = ['data' => [], 'meta' => ['has_places' => false, 'generated_at' => now()->toIso8601String()]];

    Native::test(AlertsScreen::class)
        ->assertSee("Add a place or turn on location sharing to see what's in effect near you.")
        ->assertDontSee('Nothing in effect at your places.');
});

test('a successful load saves the response, and a later offline open paints the saved copy marked with when it was fetched', function () {
    $this->freezeTime();
    $this->activeAlerts = ['data' => [activeEvent(1)], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]];
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class);

    expect((new LocalCache)->activeAlerts()['data'][0]['event'])->toBe('Tornado Warning 1');

    $this->network->connected = false;

    Native::test(AlertsScreen::class)
        ->assertSee('Tornado Warning 1')
        ->assertSee("You're offline.")
        ->assertSee('As of');
});

test('offline, an event whose end has passed since the copy was saved is not shown', function () {
    $this->freezeTime();
    (new LocalCache)->rememberActiveAlerts(['data' => [activeEvent(1, ['expires' => now()->addMinutes(10)->toIso8601String()]), activeEvent(2)], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]]);
    $this->network->connected = false;
    $this->travel(30)->minutes();

    Native::test(AlertsScreen::class)
        ->assertDontSee('Tornado Warning 1')
        ->assertSee('Tornado Warning 2');
});

test('offline with no saved copy the section says current alerts need a connection', function () {
    $this->network->connected = false;

    Native::test(AlertsScreen::class)->assertSee('Current alerts need a connection.');
});

test('a failing section load keeps the feed working and says it could not load', function () {
    $this->activeAlerts = ['message' => 'boom'];
    $this->activeAlertsStatus = 500;
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(7)]))]);

    Native::test(AlertsScreen::class)
        ->assertSee("Couldn't load current alerts.")
        ->assertSee('Tornado Warning 7')
        ->assertDontSee('could not load your alerts');
});

test('a failing feed keeps the section and its own message', function () {
    $this->activeAlerts = ['data' => [activeEvent(1)], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]];
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(['message' => 'boom'], 500)]);

    Native::test(AlertsScreen::class)
        ->assertSee('Tornado Warning 1')
        ->assertSee('WxNotify could not load your alerts. Pull down to try again.');
});

test('a rejected token on the section signs out', function () {
    $this->activeAlerts = [];
    $this->activeAlertsStatus = 401;
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class)->assertReplacedWith('/sign-in');
});

test('a failed refresh with events on screen keeps them and says they could not be refreshed, with their age', function () {
    $this->freezeSecond();
    (new LocalCache)->rememberActiveAlerts(['data' => [activeEvent(1)], 'meta' => ['has_places' => true, 'generated_at' => now()->subMinutes(5)->toIso8601String()]]);
    $this->activeAlerts = ['message' => 'boom'];
    $this->activeAlertsStatus = 500;
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class)
        ->assertSee('Tornado Warning 1')
        ->assertSee("Couldn't refresh current alerts. As of 5 minutes ago")
        ->assertDontSee("Couldn't load current alerts.")
        ->assertDontSee("You're offline.");
});

test('an event whose end has passed is never shown, and the count and the empty line ignore it', function () {
    $this->freezeSecond();
    $ended = fn (int $id) => activeEvent($id, ['expires' => now()->subMinutes(5)->toIso8601String()]);
    $this->activeAlerts = ['data' => [$ended(1), activeEvent(2)], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]];
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    Native::test(AlertsScreen::class)
        ->assertDontSee('Tornado Warning 1')
        ->assertSee('Tornado Warning 2');

    $this->activeAlerts = ['data' => [$ended(1), ...array_map(fn (int $id) => activeEvent($id), range(2, 6))], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]];

    Native::test(AlertsScreen::class)
        ->assertSee('Tornado Warning 6')
        ->assertDontSee('Show all');

    $this->activeAlerts = ['data' => [$ended(1)], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]];

    Native::test(AlertsScreen::class)
        ->assertDontSee('Tornado Warning 1')
        ->assertSee('Nothing in effect at your places.');
});

test('coming back refreshes the section on its own even when the feed is paged, and sends no feed request', function () {
    Http::fake([
        'wx.test/api/v1/alerts?limit=50' => Http::response(feedResponse([apiEntry(2)], 'CURSOR1')),
        'wx.test/api/v1/alerts?limit=25&before=CURSOR1' => Http::response(feedResponse([apiEntry(1)])),
    ]);

    $screen = Native::test(AlertsScreen::class)->call('loadMore');
    $this->activeAlerts = ['data' => [activeEvent(9)], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]];

    $this->travel(30)->seconds();
    $screen->call('onResume')->assertDontSee('Tornado Warning 9');

    $this->travel(2)->minutes();
    $screen->call('onResume')->assertSee('Tornado Warning 9')->assertSet('pagedBeyondFirst', true);

    Http::assertSentCount(4);
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'active-alerts'))->count())->toBe(2);
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'before=CURSOR1'))->count())->toBe(1);
});

test('offline, a saved copy with no fetch time says only that you are offline', function () {
    (new LocalCache)->rememberActiveAlerts(['data' => [activeEvent(1)], 'meta' => ['has_places' => true]]);
    $this->network->connected = false;

    Native::test(AlertsScreen::class)
        ->assertSee('Tornado Warning 1')
        ->assertSee("You're offline.")
        ->assertDontSee('Current alerts need a connection.')
        ->assertDontSee('As of');
});

test('a section error clears after a later successful refresh', function () {
    $this->activeAlertsStatus = 500;
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    $screen = Native::test(AlertsScreen::class)->assertSee("Couldn't load current alerts.");

    $this->activeAlertsStatus = 200;
    $screen->call('refresh')
        ->assertSet('activeError', false)
        ->assertDontSee("Couldn't load current alerts.")
        ->assertSee('Nothing in effect at your places.');
});

test('pull to refresh reloads the section as well as the feed', function () {
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    $screen = Native::test(AlertsScreen::class)->assertDontSee('Tornado Warning 1');

    $this->activeAlerts = ['data' => [activeEvent(1)], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]];
    $screen->press('refresh')->assertSee('Tornado Warning 1');
});

test('a rejected token on both the feed and the section signs out once', function () {
    $this->activeAlertsStatus = 401;
    Http::fake(['wx.test/api/v1/alerts*' => Http::response([], 401)]);

    Native::test(AlertsScreen::class)->assertReplacedWith('/sign-in');

    expect((new TokenStore)->token())->toBeNull();
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'active-alerts'));
});

test('the list does not come back expanded after a refresh leaves five or fewer events', function () {
    $many = fn (int $count) => ['data' => array_map(fn (int $id) => activeEvent($id), range(1, $count)), 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]];
    $this->activeAlerts = $many(8);
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    $screen = Native::test(AlertsScreen::class)->tap('Show all (3)')->assertSet('showAllActive', true);

    $this->activeAlerts = $many(3);
    $screen->call('refresh')->assertSet('showAllActive', false);

    $this->activeAlerts = $many(8);
    $screen->call('refresh')->assertSee('Show all (3)')->assertDontSee('Tornado Warning 8');
});

function activeAlertRequestCount(): int
{
    return Http::recorded(fn (Request $r) => str_contains($r->url(), 'active-alerts'))->count();
}

test('a screen left open drops an event as it ends and refetches the section once the copy is a minute old, on a poll', function () {
    $this->freezeSecond();
    $this->activeAlerts = ['data' => [activeEvent(1, ['expires' => now()->addMinutes(10)->toIso8601String()]), activeEvent(2)], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]];
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    $screen = Native::test(AlertsScreen::class)->assertSee('Tornado Warning 1')->assertSee('Tornado Warning 2');
    expect(activeAlertRequestCount())->toBe(1);

    $this->travel(11)->minutes();
    $screen->firePoll('pollActive')
        ->assertDontSee('Tornado Warning 1')
        ->assertSee('Tornado Warning 2')
        ->assertSee('Warning · Ends 2 hours from now');

    expect(activeAlertRequestCount())->toBe(2);
});

test('a poll within a minute of the last fetch sends no request but still drops an event that has ended', function () {
    $this->freezeSecond();
    $this->activeAlerts = ['data' => [activeEvent(1, ['expires' => now()->addSeconds(20)->toIso8601String()]), activeEvent(2)], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]];
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    $screen = Native::test(AlertsScreen::class)->assertSee('Tornado Warning 1');

    $this->travel(30)->seconds();
    $screen->firePoll('pollActive')
        ->assertDontSee('Tornado Warning 1')
        ->assertSee('Tornado Warning 2');

    expect(activeAlertRequestCount())->toBe(1);
});

test('the poll shows a warning that came into effect since the last fetch', function () {
    $this->freezeSecond();
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    $screen = Native::test(AlertsScreen::class)->assertSee('Nothing in effect at your places.');

    $this->activeAlerts = ['data' => [activeEvent(9)], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]];
    $this->travel(90)->seconds();
    $screen->firePoll('pollActive')->assertSee('Tornado Warning 9')->assertDontSee('Nothing in effect at your places.');
});

test('the poll never requests the sent-alerts feed, and does nothing once signed out', function () {
    $this->freezeSecond();
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(7)]))]);

    $screen = Native::test(AlertsScreen::class);
    $feedRequests = fn () => Http::recorded(fn (Request $r) => str_contains($r->url(), '/api/v1/alerts'))->count();
    expect($feedRequests())->toBe(1);

    $this->travel(5)->minutes();
    $screen->firePoll('pollActive');

    expect($feedRequests())->toBe(1)->and(activeAlertRequestCount())->toBe(2);

    (new TokenStore)->forget();
    $this->travel(5)->minutes();
    $screen->firePoll('pollActive');

    Http::assertSentCount(3);
});

test('offline with a saved copy whose events have all ended, the section says the saved alerts have ended instead of that nothing is in effect', function () {
    $this->freezeTime();
    (new LocalCache)->rememberActiveAlerts(['data' => [activeEvent(1, ['expires' => now()->addMinutes(10)->toIso8601String()])], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]]);
    $this->network->connected = false;
    $this->travel(30)->minutes();

    Native::test(AlertsScreen::class)
        ->assertDontSee('Tornado Warning 1')
        ->assertSee("You're offline.")
        ->assertSee('The alerts saved 30 minutes ago have ended. Connect to check for new ones.')
        ->assertDontSee('Nothing in effect at your places.');
});

test('the ended-alerts line gives the saved time in the subscriber\'s timezone when it is known', function () {
    $this->freezeTime();
    (new LocalCache)->rememberProfile(['timezone' => 'America/Chicago']);
    (new LocalCache)->rememberActiveAlerts(['data' => [activeEvent(1, ['expires' => now()->addMinutes(10)->toIso8601String()])], 'meta' => ['has_places' => true, 'generated_at' => '2026-10-12T19:14:00+00:00']]);
    $this->network->connected = false;
    $this->travel(30)->minutes();

    Native::test(AlertsScreen::class)->assertSee('The alerts saved at Oct 12, 2:14 PM CDT have ended. Connect to check for new ones.');
});

test('offline with a saved copy that had no events, the section still says nothing is in effect', function () {
    (new LocalCache)->rememberActiveAlerts(['data' => [], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]]);
    $this->network->connected = false;

    Native::test(AlertsScreen::class)
        ->assertSee("You're offline.")
        ->assertSee('Nothing in effect at your places.')
        ->assertDontSee('have ended');
});

test('an existing signed-in install is marked as having seen the welcome flow when Alerts opens', function () {
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([]))]);

    expect((new WelcomeState)->seen())->toBeFalse();

    Native::test(AlertsScreen::class)->assertNoNavigation();

    expect((new WelcomeState)->seen())->toBeTrue();
});

test('Alerts does not mark a signed-out visitor as having seen the welcome flow', function () {
    (new TokenStore)->forget();

    Native::test(AlertsScreen::class)->assertReplacedWith('/sign-in');

    expect((new WelcomeState)->seen())->toBeFalse();
});

test('an unread alert shows the dot and a read one does not', function () {
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(1, ['read' => false]), apiEntry(2)]))]);

    $screen = Native::test(AlertsScreen::class);

    expect(unreadDots($screen->tree()))->toBe(['Tornado Warning 1' => true, 'Tornado Warning 2' => false]);
});

test('an unread alert with a failed send shows both the dot and the warning', function () {
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(1, ['read' => false, 'has_failure' => true])]))]);

    Native::test(AlertsScreen::class)->assertElement('list_item', fn (array $item) => hasUnreadDot($item)
        && in_array('#EF4444', array_column(json_decode($item['props']['trailing_badges_json'], true), 'color'), true));
});

test('the badge is set from the server\'s unread_count on every feed load', function () {
    Http::fakeSequence('wx.test/api/v1/alerts*')
        ->push(feedResponse([apiEntry(1, ['read' => false])], unread: 3))
        ->push(feedResponse([apiEntry(1, ['read' => false])], unread: 5));

    $screen = Native::test(AlertsScreen::class)->assertSee('3 unread');

    expect($this->appBadge->counts)->toBe([3]);

    $screen->call('refresh')->assertSee('5 unread');

    expect($this->appBadge->counts)->toBe([3, 5])
        ->and((new LocalCache)->unreadCount())->toBe(5);
});

test('tapping an unread alert marks it read, lowers the count and sets the badge, then opens it as before', function () {
    Http::fake([
        'wx.test/api/v1/alerts/4/read' => Http::response(['data' => ['unread_count' => 1]]),
        'wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(4, ['read' => false]), apiEntry(5, ['read' => false])])),
    ]);

    $screen = Native::test(AlertsScreen::class)->tap('Tornado Warning 4')->assertNavigatedTo('/alerts/4');

    expect(unreadDots($screen->tree()))->toBe(['Tornado Warning 4' => false, 'Tornado Warning 5' => true])
        ->and($this->appBadge->last())->toBe(1)
        ->and(collect((new LocalCache)->feed())->pluck('read', 'id')->sortKeys()->all())->toBe([4 => true, 5 => false]);
    $screen->assertSee('1 unread');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://wx.test/api/v1/alerts/4/read');
});

test('tapping an unread alert with a product page reads it and opens the page', function () {
    Http::fake([
        'wx.test/api/v1/alerts/4/read' => Http::response(['data' => ['unread_count' => 0]]),
        'wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(4, ['read' => false, 'product_url' => 'https://wx.test/view-product/abc'])])),
    ]);

    Native::test(AlertsScreen::class)->tap('Tornado Warning 4')->assertNoNavigation();

    expect($this->browser->inAppUrls)->toBe(['https://wx.test/view-product/abc'])
        ->and($this->appBadge->last())->toBe(0);
});

test('a failed read call is queued and retried on the next feed load', function () {
    Http::fake([
        'wx.test/api/v1/alerts/4/read' => Http::sequence()->push([], 500)->push(['data' => ['unread_count' => 0]]),
        'wx.test/api/v1/alerts?*' => Http::sequence()
            ->push(feedResponse([apiEntry(4, ['read' => false, 'product_url' => 'https://wx.test/view-product/abc'])]))
            ->push(feedResponse([apiEntry(4)], unread: 0)),
    ]);

    $screen = Native::test(AlertsScreen::class)->tap('Tornado Warning 4');

    expect((new LocalCache)->pendingReads())->toBe([4])
        ->and(unreadDots($screen->tree()))->toBe(['Tornado Warning 4' => false]);

    $screen->call('refresh');

    expect((new LocalCache)->pendingReads())->toBe([])
        ->and($this->appBadge->last())->toBe(0);
    expect(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/alerts/4/read'))->count())->toBe(2);
});

test('a read still waiting for the server stays read when a feed load says otherwise', function () {
    Http::fake([
        'wx.test/api/v1/alerts/4/read' => Http::response([], 500),
        'wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(4, ['read' => false, 'product_url' => 'https://wx.test/view-product/abc']), apiEntry(5, ['read' => false])])),
    ]);

    $screen = Native::test(AlertsScreen::class)->tap('Tornado Warning 4')->call('refresh');

    expect(unreadDots($screen->tree()))->toBe(['Tornado Warning 4' => false, 'Tornado Warning 5' => true])
        ->and($this->appBadge->last())->toBe(1);
});

test('offline, tapping an unread alert still opens it and marks it read on the phone', function () {
    (new LocalCache)->replaceFeed([apiEntry(4, ['read' => false]), apiEntry(5, ['read' => false])]);
    (new LocalCache)->rememberUnreadCount(2);
    $this->network->connected = false;
    Http::fake();

    $screen = Native::test(AlertsScreen::class)->tap('Tornado Warning 4')->assertNavigatedTo('/alerts/4');

    expect(unreadDots($screen->tree()))->toBe(['Tornado Warning 4' => false, 'Tornado Warning 5' => true])
        ->and($this->appBadge->last())->toBe(1)
        ->and((new LocalCache)->pendingReads())->toBe([4]);
    Http::assertNothingSent();
});

test('offline, the cached feed keeps each alert\'s read flag', function () {
    (new LocalCache)->replaceFeed([apiEntry(4, ['read' => false]), apiEntry(5)]);
    $this->network->connected = false;
    Http::fake();

    $screen = Native::test(AlertsScreen::class)->assertSet('offline', true);

    expect(unreadDots($screen->tree()))->toBe(['Tornado Warning 4' => true, 'Tornado Warning 5' => false]);
});

test('Mark all as read clears every dot and sets the badge to 0', function () {
    Http::fake([
        'wx.test/api/v1/alerts/read-all' => Http::response(['data' => ['unread_count' => 0]]),
        'wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(4, ['read' => false]), apiEntry(5, ['read' => false])])),
    ]);

    $screen = Native::test(AlertsScreen::class)->tap('Mark all as read');

    expect(unreadDots($screen->tree()))->toBe(['Tornado Warning 4' => false, 'Tornado Warning 5' => false])
        ->and($this->appBadge->last())->toBe(0);
    $screen->assertDontSee('Mark all as read')->assertDontSee('unread');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://wx.test/api/v1/alerts/read-all');
});

test('Mark all as read survives a server failure and is retried on the next feed load', function () {
    Http::fake([
        'wx.test/api/v1/alerts/read-all' => Http::sequence()->push([], 503)->push(['data' => ['unread_count' => 0]]),
        'wx.test/api/v1/alerts?*' => Http::sequence()
            ->push(feedResponse([apiEntry(4, ['read' => false])]))
            ->push(feedResponse([apiEntry(4)])),
    ]);

    $screen = Native::test(AlertsScreen::class)->tap('Mark all as read');

    expect(unreadDots($screen->tree()))->toBe(['Tornado Warning 4' => false])
        ->and((new LocalCache)->pendingReadAll())->toBeTrue();

    $screen->call('refresh');

    expect((new LocalCache)->pendingReadAll())->toBeFalse()
        ->and(unreadDots($screen->tree()))->toBe(['Tornado Warning 4' => false])
        ->and($this->appBadge->last())->toBe(0);
    expect(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/read-all'))->count())->toBe(2);
});

test('Mark all as read is not offered when nothing is unread', function () {
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(feedResponse([apiEntry(4)]))]);

    Native::test(AlertsScreen::class)->assertDontSee('Mark all as read')->assertDontSee('unread');
});

test('a refused token on a pending read does not loop: one try, then the feed signs out', function () {
    (new LocalCache)->rememberPendingReads([4, 5]);
    Http::fake(['wx.test/*' => Http::response([], 401)]);

    Native::test(AlertsScreen::class)->assertReplacedWith('/sign-in');

    expect(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/read'))->count())->toBe(1)
        ->and((new TokenStore)->token())->toBeNull();
});
