<?php

use App\NativeComponents\ProductsScreen;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

function productRow(int $id, array $overrides = []): array
{
    return [
        'id' => $id, 'title' => "Area Forecast Discussion {$id}", 'office' => 'KMOB', 'issued_at' => now()->subHours(2)->toIso8601String(),
        'source' => 'nwws', 'product_id' => 4, 'type_label' => 'Other Discussions', 'view_url' => "https://wx.test/view-product/p{$id}",
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $products  the /products response body (default: two rows, no next page)
 */
function fakeProducts(?array $products = null): void
{
    Http::fake([
        'wx.test/api/v1/product-types' => Http::response(['data' => [
            ['category' => 'tornado_and_severe_thunderstorm', 'label' => 'Tornado and Severe Thunderstorm', 'products' => [['id' => 4, 'name' => 'Tornado Warning'], ['id' => 5, 'name' => 'Tornado Warning']]],
            ['category' => 'winter_weather', 'label' => 'Winter Weather', 'products' => [['id' => 6, 'name' => 'Winter Storm Warning']]],
        ]]),
        'wx.test/api/v1/nws-offices' => Http::response(['data' => [['code' => 'MOB', 'label' => 'MOB — Mobile, AL'], ['code' => 'HFO', 'label' => 'HFO — Honolulu, HI']]]),
        'wx.test/api/v1/products*' => Http::response($products ?? ['data' => [productRow(1), productRow(2)], 'meta' => ['next_cursor' => null]]),
    ]);
}

/**
 * @return array<string, string> the query string of the last /products request
 */
function lastProductsQuery(): array
{
    $query = [];

    Http::assertSent(function (Request $request) use (&$query) {
        if (str_contains($request->url(), '/api/v1/products')) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        }

        return true;
    });

    return $query;
}

beforeEach(function () {
    $this->freezeTime();
    (new TokenStore)->put('9|secret');
});

test('it opens with the search box, the filters and no results', function () {
    fakeProducts();

    Native::test(ProductsScreen::class)
        ->assertNavTitle('Products')
        ->assertHasTab('Products')
        ->assertTabActive('Products')
        ->assertElement('outlined_text_input')
        ->assertElement('select', fn (array $select) => $select['props']['label'] === 'Type')
        ->assertElement('select', fn (array $select) => $select['props']['label'] === 'Office')
        ->assertElement('select', fn (array $select) => $select['props']['label'] === 'Period')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Search')
        ->assertDontSee('Area Forecast Discussion')
        ->assertDontSee('Clear filters')
        ->assertSee('Search by name or ID');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/api/v1/products'));
});

test('the keyboard Search key and the Search button both run the typed search', function () {
    fakeProducts();

    Native::test(ProductsScreen::class)
        ->submit('field-query', 'afd')
        ->assertSee('Area Forecast Discussion 1')
        ->assertSee('KMOB · 2 hours ago');

    expect(lastProductsQuery())->toBe(['q' => 'afd', 'limit' => '25']);

    Native::test(ProductsScreen::class)
        ->input('field-query', 'tornado')
        ->tap('search-products')
        ->assertSee('Area Forecast Discussion 2');

    expect(lastProductsQuery())->toBe(['q' => 'tornado', 'limit' => '25']);
});

test('one typed character is refused with a message and nothing is sent', function () {
    fakeProducts();

    Native::test(ProductsScreen::class)
        ->submit('field-query', 'a')
        ->assertSee('Type at least 2 characters to search')
        ->assertDontSee('Area Forecast Discussion');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/api/v1/products'));
});

test('a filter alone runs a search at once, and each filter is sent as the API expects', function () {
    fakeProducts();

    $screen = Native::test(ProductsScreen::class)
        ->select('field-office', 'MOB — Mobile, AL')
        ->assertSee('Area Forecast Discussion 1');

    expect(lastProductsQuery())->toBe(['wfo' => 'MOB', 'limit' => '25']);

    $screen->select('field-period', 'Past 7 days')->select('field-type', 'Winter Storm Warning');

    expect(lastProductsQuery())->toBe(['type' => '6', 'wfo' => 'MOB', 'period' => 'week', 'limit' => '25']);
});

test('two catalog products with the same name can both be chosen', function () {
    fakeProducts();

    Native::test(ProductsScreen::class)->select('field-type', 'Tornado Warning (5)');

    expect(lastProductsQuery())->toBe(['type' => '5', 'limit' => '25']);
});

test('clearing the filters, with no text, goes back to the opening state', function () {
    fakeProducts();

    Native::test(ProductsScreen::class)
        ->select('field-period', 'Past hour')
        ->assertSee('Clear filters')
        ->tap('clear-filters')
        ->assertDontSee('Area Forecast Discussion')
        ->assertDontSee('Clear filters')
        ->assertSee('Search by name or ID');
});

test('a row opens its product page in the in-app browser, and a row without a page is not tappable', function () {
    fakeProducts(['data' => [productRow(1), productRow(2, ['view_url' => null])], 'meta' => ['next_cursor' => null]]);

    Native::test(ProductsScreen::class)->submit('field-query', 'afd')->tap('product-1');

    expect($this->browser->inAppUrls)->toBe(['https://wx.test/view-product/p1']);

    Native::test(ProductsScreen::class)->submit('field-query', 'afd')
        ->assertMissingElement('pressable', fn (array $pressable) => ($pressable['ref'] ?? null) === 'product-2')
        ->assertSee('Area Forecast Discussion 2');
});

test('no matches says so and keeps the filters', function () {
    fakeProducts(['data' => [], 'meta' => ['next_cursor' => null]]);

    Native::test(ProductsScreen::class)->select('field-period', 'Past hour')->assertSee('No products match')->assertSee('Clear filters');
});

test('Show more appends the next page using the cursor and goes away at the end', function () {
    Http::fake([
        'wx.test/api/v1/product-types' => Http::response(['data' => []]),
        'wx.test/api/v1/nws-offices' => Http::response(['data' => []]),
        'wx.test/api/v1/products*' => Http::sequence()
            ->push(['data' => [productRow(1)], 'meta' => ['next_cursor' => 'cur-1']])
            ->push(['data' => [productRow(2)], 'meta' => ['next_cursor' => null]]),
    ]);

    Native::test(ProductsScreen::class)
        ->submit('field-query', 'afd')
        ->assertSee('Show more')
        ->tap('show-more')
        ->assertSee('Area Forecast Discussion 1')
        ->assertSee('Area Forecast Discussion 2')
        ->assertDontSee('Show more');

    expect(lastProductsQuery())->toBe(['q' => 'afd', 'limit' => '25', 'before' => 'cur-1']);
});

test('a search is saved as a recent search, tapping a recent search refills and re-runs it, and Clear empties the list', function () {
    fakeProducts();

    $screen = Native::test(ProductsScreen::class)
        ->submit('field-query', 'afd')
        ->select('field-office', 'MOB — Mobile, AL');

    expect(array_column((new LocalCache)->recentSearches(), 'wfo'))->toBe(['MOB', '']);

    Native::test(ProductsScreen::class)
        ->assertSee('Recent searches')
        ->assertSee('afd · MOB')
        ->tap('recent-0')
        ->assertSet('query', 'afd')
        ->assertSet('wfo', 'MOB')
        ->assertSee('Area Forecast Discussion 1');

    expect(lastProductsQuery())->toBe(['q' => 'afd', 'wfo' => 'MOB', 'limit' => '25']);

    Native::test(ProductsScreen::class)->tap('clear-recent')->assertDontSee('Recent searches');

    expect((new LocalCache)->recentSearches())->toBe([]);
});

test('offline it says so, keeps the recent searches and the typed text', function () {
    (new LocalCache)->rememberSearch(['q' => 'afd', 'type' => null, 'type_label' => null, 'wfo' => '', 'period' => '']);
    fakeProducts();
    $this->network->connected = false;

    Native::test(ProductsScreen::class)
        ->set('query', 'tornado')
        ->tap('search-products')
        ->assertSee("You're offline. Reconnect to search.")
        ->assertSee('afd')
        ->assertSet('query', 'tornado');
});

test('a failed search shows a message with Try again', function () {
    Http::fake([
        'wx.test/api/v1/product-types' => Http::response(['data' => []]),
        'wx.test/api/v1/nws-offices' => Http::response(['data' => []]),
        'wx.test/api/v1/products*' => Http::response(['message' => 'boom'], 500),
    ]);

    Native::test(ProductsScreen::class)
        ->submit('field-query', 'afd')
        ->assertSee('WxNotify could not search products. Try again in a moment.')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Try again');
});

test('a signed-out visitor is sent to Sign in, and a rejected token signs out', function () {
    (new TokenStore)->forget();
    Native::test(ProductsScreen::class)->assertReplacedWith('/sign-in');

    (new TokenStore)->put('9|secret');
    Http::fake(['wx.test/api/v1/*' => Http::response([], 401)]);
    Native::test(ProductsScreen::class)->assertReplacedWith('/sign-in');
});

test('the filter lists failing to load leaves the selects usable and the next search tries again', function () {
    Http::fake([
        'wx.test/api/v1/product-types' => Http::sequence()->push([], 500)->push(['data' => [['category' => 'x', 'label' => 'X', 'products' => [['id' => 6, 'name' => 'Winter Storm Warning']]]]]),
        'wx.test/api/v1/nws-offices' => Http::response(['data' => []]),
        'wx.test/api/v1/products*' => Http::response(['data' => [productRow(1)], 'meta' => ['next_cursor' => null]]),
    ]);

    $screen = Native::test(ProductsScreen::class)->assertSet('types', []);

    $screen->submit('field-query', 'afd');

    expect($screen->get('types'))->toHaveCount(1);
});

test('Show more keeps using the search that produced the cursor, even after the box text is edited', function () {
    Http::fake([
        'wx.test/api/v1/product-types' => Http::response(['data' => []]),
        'wx.test/api/v1/nws-offices' => Http::response(['data' => []]),
        'wx.test/api/v1/products*' => Http::sequence()
            ->push(['data' => [productRow(1)], 'meta' => ['next_cursor' => 'cur-1']])
            ->push(['data' => [productRow(2)], 'meta' => ['next_cursor' => null]]),
    ]);

    Native::test(ProductsScreen::class)
        ->submit('field-query', 'afd')
        ->set('query', 'tornado')
        ->tap('show-more')
        ->assertSee('Area Forecast Discussion 2');

    expect(lastProductsQuery())->toBe(['q' => 'afd', 'limit' => '25', 'before' => 'cur-1']);
});

test('going offline on a later search shows the message and the recent searches, and keeps the typed text', function () {
    fakeProducts();

    $screen = Native::test(ProductsScreen::class)->submit('field-query', 'afd');

    $this->network->connected = false;

    $screen->set('query', 'tornado')
        ->tap('search-products')
        ->assertSee("You're offline. Reconnect to search.")
        ->assertSee('Recent searches')
        ->assertSee('afd')
        ->assertDontSee('Area Forecast Discussion')
        ->assertSet('query', 'tornado');
});

test('a failing later search shows the message, Try again and the recent searches', function () {
    Http::fake([
        'wx.test/api/v1/product-types' => Http::response(['data' => []]),
        'wx.test/api/v1/nws-offices' => Http::response(['data' => []]),
        'wx.test/api/v1/products*' => Http::sequence()
            ->push(['data' => [productRow(1)], 'meta' => ['next_cursor' => null]])
            ->push(['message' => 'boom'], 500),
    ]);

    Native::test(ProductsScreen::class)
        ->submit('field-query', 'afd')
        ->submit('field-query', 'tornado')
        ->assertSee('WxNotify could not search products. Try again in a moment.')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Try again')
        ->assertSee('Recent searches')
        ->assertSee('afd')
        ->assertDontSee('Area Forecast Discussion');
});
