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

test('a load more straight after a page loaded is ignored, and works again a moment later', function () {
    Http::fake([
        'wx.test/api/v1/alerts?limit=50' => Http::response(feedResponse([apiEntry(3)], 'CURSOR2')),
        'wx.test/api/v1/alerts?limit=25&before=CURSOR2' => Http::response(feedResponse([apiEntry(2)], 'CURSOR1')),
        'wx.test/api/v1/alerts?limit=25&before=CURSOR1' => Http::response(feedResponse([apiEntry(1)])),
    ]);

    $screen = Native::test(AlertsScreen::class)
        ->call('loadMore')
        ->assertSet('loadingMore', false)
        ->assertSet('nextCursor', 'CURSOR1')
        ->call('loadMore')
        ->assertDontSee('Tornado Warning 1')
        ->assertSet('nextCursor', 'CURSOR1');

    Http::assertSentCount(2);

    $this->travel(2)->seconds();

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

test('the rows keep their node ids when a refresh prepends an alert', function () {
    Http::fake(['wx.test/api/v1/alerts*' => Http::sequence()
        ->push(feedResponse([apiEntry(2), apiEntry(1)]))
        ->push(feedResponse([apiEntry(3), apiEntry(2), apiEntry(1)])),
    ]);

    $rowIds = fn (array $tree): array => collect(findRows($tree))->mapWithKeys(fn (array $row) => [$row['props']['headline'] => $row['id']])->all();

    $screen = Native::test(AlertsScreen::class);
    $before = $rowIds($screen->tree());

    $after = $rowIds($screen->call('refresh')->tree());

    expect($after)->toHaveCount(3)
        ->and($after['Tornado Warning 2'])->toBe($before['Tornado Warning 2'])
        ->and($after['Tornado Warning 1'])->toBe($before['Tornado Warning 1'])
        ->and(array_unique($after))->toHaveCount(3);
});

test('load more is unavailable offline', function () {
    Http::fake(['*' => Http::response(feedResponse([apiEntry(2)], 'CURSOR1'))]);

    $screen = Native::test(AlertsScreen::class);
    $this->network->connected = false;
    $screen->call('loadMore')->assertSet('offline', true)->assertSet('loadingMore', false);

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
