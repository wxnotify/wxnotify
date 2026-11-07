<?php

use App\Enums\ProductFamily;
use App\Filament\Dashboard\Pages\LiveWeather\Products;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use App\Models\Product;
use App\Models\User;
use App\Support\LiveProductCategory;
use Filament\Facades\Filament;

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

test('the View link shows only for an https public page', function () {
    config(['wxnotify.product_domain' => 'products.example.com']);
    liveProductsMake(['cccc' => 'ktbw']);

    livewire(Products::class)->assertSeeHtml('target="_blank"')->assertSeeHtml('rel="noopener"');

    config(['wxnotify.product_domain' => null, 'app.url' => 'http://localhost']);

    livewire(Products::class)->assertDontSeeHtml('target="_blank"');
});

test('onProductIngested puts a fresh product at the top and a repeat adds nothing', function () {
    liveProductsMake(['received_at' => now()->subHour()]);
    $fresh = liveProductsMake(['received_at' => now()]);

    $component = livewire(Products::class)->set('rows', []);
    $component->call('onProductIngested', ['id' => $fresh->id])
        ->call('onProductIngested', ['id' => $fresh->id]);

    expect(array_column($component->get('rows'), 'id'))->toBe([$fresh->id]);
});

test('an out-of-order older product lands in order', function () {
    $older = liveProductsMake(['received_at' => now()->subHours(2)]);
    $newest = liveProductsMake(['received_at' => now()]);
    $middle = liveProductsMake(['received_at' => now()->subHour()]);

    $component = livewire(Products::class)->set('rows', []);
    $component->call('onProductIngested', ['id' => $newest->id])
        ->call('onProductIngested', ['id' => $older->id])
        ->call('onProductIngested', ['id' => $middle->id]);

    expect(array_column($component->get('rows'), 'id'))->toBe([$newest->id, $middle->id, $older->id]);
});

test('the list never exceeds 100 rows', function () {
    $component = livewire(Products::class)->set('rows', []);

    foreach (range(1, 101) as $minute) {
        $product = liveProductsMake(['received_at' => now()->subMinutes(200 - $minute)]);
        $component->call('onProductIngested', ['id' => $product->id]);
    }

    expect($component->get('rows'))->toHaveCount(100)
        ->and($component->get('rows')[0]['id'])->toBe($product->id);
});

test('a payload for a test product, a replay, a missing id or garbage adds nothing', function () {
    $test = liveProductsMake(['is_test' => true]);
    $replay = liveProductsMake(['replay_of_id' => liveProductsMake()->id]);

    $component = livewire(Products::class)->set('rows', []);
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
