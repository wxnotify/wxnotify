<?php

use App\Enums\ProductFamily;
use App\Filament\Dashboard\Pages\LiveWeather\Products;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use App\Models\Product;
use App\Models\User;
use App\Support\LiveProductCategory;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->actingAs(User::factory()->create());
});

function liveProductsMake(array $attributes = [], ?ProductFamily $family = null): IngestedProduct
{
    if ($family !== null) {
        $attributes['product_id'] = Product::factory()->create(['family' => $family])->id;
    }

    return IngestedProduct::factory()->create($attributes);
}

test('the page sits in the Live Weather group as Products and any signed-in user can open it', function () {
    livewire(Products::class)->assertSuccessful();

    expect(Products::getSlug())->toBe('live-weather/products')
        ->and(Products::getNavigationLabel())->toBe('Products')
        ->and(Products::getNavigationGroup())->toBe('Live Weather')
        ->and(Products::getNavigationSort())->toBe(2);
});

test('mount lists the latest 50 real products newest first, without test products or replays', function () {
    foreach (range(1, 55) as $minute) {
        liveProductsMake(['received_at' => now()->subMinutes(100 - $minute)]);
    }
    $test = liveProductsMake(['is_test' => true, 'received_at' => now()]);
    $replay = liveProductsMake(['replay_of_id' => IngestedProduct::query()->value('id'), 'received_at' => now()]);

    $rows = livewire(Products::class)->get('rows');

    expect($rows)->toHaveCount(50)
        ->and(array_column($rows, 'id'))->not->toContain($test->id, $replay->id)
        ->and(array_column($rows, 'issued_at'))->toBe(collect(array_column($rows, 'issued_at'))->sortDesc()->values()->all());
});

test('a row carries the name, office, headline, category and public page link', function () {
    config(['wxnotify.product_domain' => 'products.example.com']);
    $ingested = liveProductsMake(['cccc' => 'ktbw'], ProductFamily::Warning);
    IngestedProductSegment::factory()->create(['ingested_product_id' => $ingested->id, 'product_id' => $ingested->product_id, 'position' => 0]);

    $row = livewire(Products::class)->get('rows')[0];

    expect($row)->toMatchArray([
        'id' => $ingested->id,
        'name' => $ingested->product->name,
        'office' => 'TBW',
        'headline' => $ingested->product->name,
        'category' => 'warnings_and_watches',
        'view_url' => $ingested->fresh()->viewUrl(),
    ])->and($row['view_url'])->toStartWith('https://products.example.com/');
});

test('the product name is the row link, only for an https public page', function () {
    config(['wxnotify.product_domain' => 'products.example.com']);
    $ingested = liveProductsMake(['cccc' => 'ktbw'], ProductFamily::Warning);
    $name = $ingested->product->name;
    $href = $ingested->fresh()->viewUrl();

    livewire(Products::class)
        ->assertSeeHtml('<a href="'.e($href).'" target="_blank" rel="noopener"')
        ->assertSeeHtmlInOrder(['data-row-link>', e($name), '</a>'])
        ->assertDontSeeHtml('>View</a>');

    config(['wxnotify.product_domain' => null, 'app.url' => 'http://localhost']);

    livewire(Products::class)
        ->assertDontSeeHtml('target="_blank"')
        ->assertDontSeeHtml('data-row-link')
        ->assertSee($name);
});

test('a product name is escaped inside the row link', function () {
    config(['wxnotify.product_domain' => 'products.example.com']);
    $ingested = liveProductsMake(['cccc' => 'ktbw'], ProductFamily::Warning);
    $ingested->product->update(['name' => '<script>x</script>']);

    livewire(Products::class)
        ->assertSeeHtml('&lt;script&gt;x&lt;/script&gt;</a>')
        ->assertDontSeeHtml('<script>x</script>');
});

test('onProductIngested puts a fresh product at the top and a repeat adds nothing', function () {
    $component = livewire(Products::class);
    liveProductsMake(['received_at' => now()->subHour()]);
    $fresh = liveProductsMake(['received_at' => now()]);

    $component->call('onProductIngested', ['id' => $fresh->id])
        ->call('onProductIngested', ['id' => $fresh->id]);

    expect(array_column($component->get('rows'), 'id'))->toBe([$fresh->id]);
});

test('an out-of-order older product lands in order', function () {
    $component = livewire(Products::class);
    $older = liveProductsMake(['received_at' => now()->subHours(2)]);
    $newest = liveProductsMake(['received_at' => now()]);
    $middle = liveProductsMake(['received_at' => now()->subHour()]);

    $component->call('onProductIngested', ['id' => $newest->id])
        ->call('onProductIngested', ['id' => $older->id])
        ->call('onProductIngested', ['id' => $middle->id]);

    expect(array_column($component->get('rows'), 'id'))->toBe([$newest->id, $middle->id, $older->id]);
});

test('the list never exceeds 100 rows', function () {
    $component = livewire(Products::class);

    foreach (range(1, 101) as $minute) {
        $product = liveProductsMake(['received_at' => now()->subMinutes(200 - $minute)]);
        $component->call('onProductIngested', ['id' => $product->id]);
    }

    expect($component->get('rows'))->toHaveCount(100)
        ->and($component->get('rows')[0]['id'])->toBe($product->id);
});

test('a payload for a test product, a replay, a missing id or garbage adds nothing', function () {
    $component = livewire(Products::class);
    $test = liveProductsMake(['is_test' => true]);
    $replay = liveProductsMake(['replay_of_id' => liveProductsMake()->id]);

    $component->call('onProductIngested', ['id' => $test->id])
        ->call('onProductIngested', ['id' => $replay->id])
        ->call('onProductIngested', ['id' => 999999])
        ->call('onProductIngested', ['id' => ['x']])
        ->call('onProductIngested', []);

    expect($component->get('rows'))->toBe([])
        ->and($component->get('pending'))->toBe(0);
});

test('fetchSince returns and merges only newer real products without duplicates', function () {
    $known = liveProductsMake(['received_at' => now()->subHours(3)]);
    $component = livewire(Products::class);
    $newer = liveProductsMake(['received_at' => now()->subHour()]);
    liveProductsMake(['is_test' => true, 'received_at' => now()]);

    $component->call('fetchSince', $known->id)->call('fetchSince', $known->id);

    expect(array_column($component->get('rows'), 'id'))->toBe([$newer->id, $known->id]);
});

test('category filtering hides the other categories and rejects an unknown value', function () {
    $warning = liveProductsMake([], ProductFamily::Warning);
    $discussion = liveProductsMake([], ProductFamily::ForecastDiscussion);
    $other = liveProductsMake([], ProductFamily::Aviation);
    $uncatalogued = liveProductsMake();

    $component = livewire(Products::class)->call('setCategories', ['outlooks_and_discussions']);
    expect(array_column($component->get('rows'), 'id'))->toBe([$discussion->id]);

    $component->call('setCategories', ['other']);
    expect(array_column($component->get('rows'), 'id'))->toEqualCanonicalizing([$other->id, $uncatalogued->id]);

    $component->call('setCategories', ['warnings_and_watches', 'bogus']);
    expect($component->get('categories'))->toBe(['other'])
        ->and(array_column($component->get('rows'), 'id'))->not->toContain($warning->id);

    $component->call('setCategories', ['warnings_and_watches']);
    expect(array_column($component->get('rows'), 'id'))->toBe([$warning->id]);
});

test('a live product outside the selected categories is not added', function () {
    $component = livewire(Products::class)->call('setCategories', ['outlooks_and_discussions']);
    $warning = liveProductsMake([], ProductFamily::Warning);

    $component->call('onProductIngested', ['id' => $warning->id]);

    expect($component->get('rows'))->toBe([]);
});

test('LiveProductCategory maps catalog families', function (?ProductFamily $family, LiveProductCategory $expected) {
    $product = $family === null ? null : Product::factory()->make(['family' => $family]);

    expect(LiveProductCategory::forProduct($product))->toBe($expected);
})->with([
    'warning' => [ProductFamily::Warning, LiveProductCategory::WarningsAndWatches],
    'watch' => [ProductFamily::Watch, LiveProductCategory::WarningsAndWatches],
    'advisory' => [ProductFamily::Advisory, LiveProductCategory::WarningsAndWatches],
    'statement' => [ProductFamily::Statement, LiveProductCategory::WarningsAndWatches],
    'spc_wpc' => [ProductFamily::SpcWpc, LiveProductCategory::OutlooksAndDiscussions],
    'discussion' => [ProductFamily::ForecastDiscussion, LiveProductCategory::OutlooksAndDiscussions],
    'aviation' => [ProductFamily::Aviation, LiveProductCategory::Other],
    'none' => [null, LiveProductCategory::Other],
]);

test('product names and offices are escaped, never rendered as HTML', function () {
    $product = Product::factory()->create(['name' => '<script>x</script>', 'family' => ProductFamily::Warning]);
    liveProductsMake(['product_id' => $product->id, 'cccc' => 'ktbw']);

    livewire(Products::class)
        ->assertSee('<script>x</script>', escape: true)
        ->assertDontSeeHtml('<script>x</script>');
});

test('the client resets pending whenever the viewer is not scrolled down', function () {
    livewire(Products::class)
        ->assertSeeHtml('x-effect="if (! scrolled && $wire.pending > 0) { $wire.pending = 0; }"')
        ->assertSeeHtml('x-show="scrolled && $wire.pending > 0"');
});

test('pending counts only a product that made it into the capped list', function () {
    $component = livewire(Products::class);
    foreach (range(1, 100) as $minute) {
        $product = liveProductsMake(['received_at' => now()->subMinutes(10 + $minute)]);
        $component->call('onProductIngested', ['id' => $product->id]);
    }
    $oldest = liveProductsMake(['received_at' => now()->subYear()]);

    $component->call('onProductIngested', ['id' => $oldest->id]);

    expect($component->get('rows'))->toHaveCount(100)
        ->and(array_column($component->get('rows'), 'id'))->not->toContain($oldest->id)
        ->and($component->get('pending'))->toBe(100);
});

test('rows and categories cannot be written from the browser', function () {
    expect(fn () => livewire(Products::class)->set('rows', []))->toThrow('Cannot update locked property')
        ->and(fn () => livewire(Products::class)->set('categories', ['other']))->toThrow('Cannot update locked property');
});

test('garbage arguments never throw and change nothing', function () {
    liveProductsMake();
    $component = livewire(Products::class);
    $before = $component->get('rows');

    foreach (['abc', -5, null, ['x'], '9999999999999999999999', 1.5] as $garbage) {
        $component->call('fetchSince', $garbage)->assertSuccessful();
    }
    $component->call('setCategories', 'other')->call('setCategories', [['x'], 5])->call('setCategories', [null]);

    expect($component->get('rows'))->toBe($before)
        ->and($component->get('categories'))->toHaveCount(3);
});

test('the query budget is 120 calls a minute per viewer, shared by fetchSince and setCategories, and does no query once spent', function () {
    $product = liveProductsMake();
    $component = livewire(Products::class);

    foreach (range(1, 60) as $i) {
        $component->call('fetchSince', $product->id)->call('setCategories', ['other', 'warnings_and_watches', 'outlooks_and_discussions']);
    }

    DB::enableQueryLog();
    DB::flushQueryLog();
    $component->call('fetchSince', 0);
    $limitedQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'ingested_products'))->count();
    DB::disableQueryLog();

    expect($limitedQueries)->toBe(0)
        ->and($component->get('rows'))->toHaveCount(1);

    $component->call('setCategories', ['other']);
    expect($component->get('categories'))->toHaveCount(3);

    $this->travel(61)->seconds();
    $component->call('setCategories', ['other']);
    expect($component->get('categories'))->toBe(['other']);
});

test('another viewer has their own query budget', function () {
    $product = liveProductsMake();
    $first = User::factory()->create();
    RateLimiter::hit('live-products-query:'.$first->id, 60);
    foreach (range(1, 120) as $i) {
        RateLimiter::hit('live-products-query:'.$first->id, 60);
    }

    $this->actingAs($first);
    expect(livewire(Products::class)->call('fetchSince', 0)->get('rows'))->toHaveCount(1);

    $other = User::factory()->create();
    $this->actingAs($other);
    $component = livewire(Products::class)->set('pending', 0);
    $component->call('setCategories', ['other']);
    expect($component->get('categories'))->toBe(['other']);
});

test('the live listener has its own larger budget that fetchSince exhaustion does not touch', function () {
    $product = liveProductsMake();
    $component = livewire(Products::class);
    foreach (range(1, 121) as $i) {
        RateLimiter::hit('live-products-query:'.auth()->id(), 60);
    }

    foreach (range(1, 130) as $i) {
        $component->call('onProductIngested', ['id' => $product->id + 1000 + $i]);
    }
    $fresh = liveProductsMake();
    $component->call('onProductIngested', ['id' => $fresh->id]);

    expect(array_column($component->get('rows'), 'id'))->toContain($fresh->id)
        ->and($component->call('fetchSince', 0)->get('rows'))->toHaveCount(2);
});

test('the page loads the Echo bundle and carries the channel, the event and the three status labels', function () {
    $hotFile = tempnam(sys_get_temp_dir(), 'hot');
    file_put_contents($hotFile, 'http://localhost:5173');
    Vite::useHotFile($hotFile);

    livewire(Products::class)
        ->assertSeeHtml('http://localhost:5173/resources/js/echo.js')
        ->assertSeeHtml("private('live-products')")
        ->assertSeeHtml("'.ProductIngested'")
        ->assertSeeHtml('.error(')
        ->assertSeeHtml('.subscribed(')
        ->assertSeeHtml('role="status"')
        ->assertSeeHtml('aria-live="polite"')
        ->assertSee('Live')
        ->assertSee('Reconnecting…')
        ->assertSee('Offline: refreshing every minute');

    unlink($hotFile);
});

test('the first render is Offline and already polls with no Echo, and no Reverb secret is in the page', function () {
    $this->withoutVite();
    config(['reverb.apps.apps.0.secret' => 'sekret-value-for-test', 'broadcasting.connections.reverb.secret' => 'sekret-value-for-test']);

    $html = livewire(Products::class)->assertSuccessful()->html();

    expect($html)
        ->toContain("status: 'offline'")
        ->toContain('this.setStatus(\'offline\')')
        ->toContain('60000')
        ->toContain('this.$wire.fetchSince(')
        ->toContain('Offline: refreshing every minute')
        ->not->toContain('sekret-value-for-test');
});

test('the Echo listener debounces events into one fetchSince and never calls onProductIngested', function () {
    $this->withoutVite();

    $html = livewire(Products::class)->assertSuccessful()->html();

    expect($html)
        ->toContain('this.onEvent(event)')
        ->toContain('setTimeout(() => this.resolveEvents(), 1000)')
        ->toContain('this.$wire.fetchSince(this.newestId())')
        ->not->toContain('$wire.onProductIngested');
});

test('fetchSince counts only the rows that entered the list into pending', function () {
    $known = liveProductsMake(['received_at' => now()->subMinutes(5)]);
    $page = livewire(Products::class);
    liveProductsMake(['received_at' => now()->subMinute()]);
    liveProductsMake(['received_at' => now()]);

    $page->call('fetchSince', $known->id)->assertSet('pending', 2)
        ->call('fetchSince', $known->id)->assertSet('pending', 2);
});

test('the poll entry point uses the newest id the page holds and merges without duplicates', function () {
    $known = liveProductsMake(['received_at' => now()->subMinutes(5)]);
    $page = livewire(Products::class);
    $newer = liveProductsMake(['received_at' => now()]);

    $page->call('fetchSince', $known->id)->call('fetchSince', $known->id);

    expect(array_column($page->get('rows'), 'id'))->toBe([$newer->id, $known->id]);
});

test('each row carries a tint and a New badge bound to the fresh state, and the page defines the 8 s highlight and reduce-motion handling', function () {
    $this->withoutVite();
    $product = liveProductsMake(['received_at' => now()]);

    $html = livewire(Products::class)->assertSuccessful()->html();

    expect($html)
        ->toContain('data-fresh-badge')
        ->toContain('>New</span>')
        ->toContain('isFresh('.$product->id.')')
        ->toContain('data-fresh-tint')
        ->toContain('const FRESH_MS = 8000;')
        ->toContain('animation: wx-fresh-fade 8s linear forwards')
        ->toContain('prefers-reduced-motion: reduce')
        ->toContain('wx-fresh-static')
        ->toContain('bg-primary-50 dark:bg-primary-500/10')
        ->toContain('this.$wire.$watch(\'rows\'')
        ->not->toContain('aria-live="assertive"');
});

test('a guest is redirected to the panel login', function () {
    auth()->logout();

    $this->get(Products::getUrl())->assertRedirect(Filament::getLoginUrl());
});
