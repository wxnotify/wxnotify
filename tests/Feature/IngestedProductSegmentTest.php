<?php

use App\Models\ActiveWwa;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use App\Models\Product;

/** A bulletin made without the observer, so no job builds segments behind the test's back. */
function segmentTestBulletin(array $attributes = []): IngestedProduct
{
    return IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create($attributes));
}

test('a bulletin lists its segments in position order whatever order they were saved in', function () {
    $bulletin = segmentTestBulletin();
    IngestedProductSegment::factory()->for($bulletin)->create(['position' => 2, 'vtec_event_number' => '0003']);
    IngestedProductSegment::factory()->for($bulletin)->create(['position' => 0, 'vtec_event_number' => '0001']);
    IngestedProductSegment::factory()->for($bulletin)->create(['position' => 1, 'vtec_event_number' => '0002']);

    expect($bulletin->segments->pluck('vtec_event_number')->all())->toBe(['0001', '0002', '0003']);
});

test('a segment is named by its catalog product, else by its VTEC codes, else generically', function () {
    $catalogued = IngestedProductSegment::factory()->forCatalogCode('HU.A')->create();
    // The catalog is a curated subset: it has no Rip Current Statement (RP.S).
    expect(Product::where('code', 'RP.S')->exists())->toBeFalse();
    $uncatalogued = IngestedProductSegment::factory()->create(['vtec_phenomenon' => 'RP', 'vtec_significance' => 'S', 'product_id' => null]);
    $unknown = IngestedProductSegment::factory()->create(['vtec_phenomenon' => 'XX', 'vtec_significance' => 'Q', 'product_id' => null]);

    expect($catalogued->eventName())->toBe('Hurricane Watch')
        ->and($uncatalogued->eventName())->toBe('Rip Current Statement')
        ->and($unknown->eventName())->toBe('Weather alert');
});

test('deleting a bulletin deletes its segments, and deleting a segment unlinks its deliveries', function () {
    $bulletin = segmentTestBulletin();
    $segment = IngestedProductSegment::factory()->for($bulletin)->create();
    $log = DeliveryLog::factory()->create();
    $log->segments()->attach($segment);

    expect($log->segments()->count())->toBe(1);

    $bulletin->delete();

    expect(IngestedProductSegment::count())->toBe(0)
        ->and($log->segments()->count())->toBe(0)
        ->and(DeliveryLog::count())->toBe(1);
});

test('asSegment gives an unsaved copy of the bulletin that carries the segment\'s identity', function () {
    $hurricaneWatch = Product::where('code', 'HU.A')->sole();
    $bulletin = segmentTestBulletin([
        'cccc' => 'kmob', 'is_test' => false, 'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'SS', 'vtec_significance' => 'A',
        'vtec_event_number' => '1009', 'vtec_year' => 2026, 'ugc_codes' => ['ALZ266'], 'polygon' => null, 'product_id' => null,
    ]);
    $segment = IngestedProductSegment::factory()->for($bulletin)->forCatalogCode('HU.A')->create([
        'vtec_office' => 'KMOB', 'vtec_event_number' => '1009', 'vtec_year' => 2026, 'ugc_codes' => ['FLZ206'],
        'polygon' => [['lat' => 30.0, 'lon' => -86.0], ['lat' => 30.5, 'lon' => -86.0], ['lat' => 30.5, 'lon' => -86.5]],
    ]);

    $view = $bulletin->asSegment($segment);

    expect($view->exists)->toBeFalse()
        ->and($view->id)->toBe($bulletin->id)
        ->and($view->cccc)->toBe('kmob')
        ->and($view->product_id)->toBe($hurricaneWatch->id)
        ->and($view->product->code)->toBe('HU.A')
        ->and($view->ugc_codes)->toBe(['FLZ206'])
        ->and($view->polygon)->toHaveCount(3)
        ->and($view->vtec_phenomenon)->toBe('HU')
        ->and($view->vtec_significance)->toBe('A')
        ->and($bulletin->fresh()->ugc_codes)->toBe(['ALZ266'])
        ->and($bulletin->fresh()->vtec_phenomenon)->toBe('SS');
});

test('matchedSegments loads only the listed segments that still exist, in position order, with their products', function () {
    $bulletin = segmentTestBulletin();
    $second = IngestedProductSegment::factory()->for($bulletin)->forCatalogCode('SC.Y')->create(['position' => 1]);
    $first = IngestedProductSegment::factory()->for($bulletin)->forCatalogCode('HU.A')->create(['position' => 0]);
    IngestedProductSegment::factory()->for($bulletin)->create(['position' => 2]);

    $segments = $bulletin->matchedSegments([$second->id, $first->id, 999999]);

    expect($segments->modelKeys())->toBe([$first->id, $second->id])
        ->and($segments->first()->relationLoaded('product'))->toBeTrue()
        ->and($bulletin->matchedSegments([])->isEmpty())->toBeTrue();
});

test('event names are unique, in bulletin order, and fall back to the bulletin\'s own catalog product without segments', function () {
    $hurricaneWatch = Product::where('code', 'HU.A')->sole();
    $bulletin = segmentTestBulletin(['product_id' => $hurricaneWatch->id]);
    $watchOne = IngestedProductSegment::factory()->for($bulletin)->forCatalogCode('HU.A')->create(['position' => 0]);
    $advisory = IngestedProductSegment::factory()->for($bulletin)->forCatalogCode('SC.Y')->create(['position' => 1]);
    $watchTwo = IngestedProductSegment::factory()->for($bulletin)->forCatalogCode('HU.A')->create(['position' => 2]);

    expect($bulletin->eventNamesFor($bulletin->matchedSegments([$watchOne->id, $advisory->id, $watchTwo->id])))->toBe(['Hurricane Watch', 'Small Craft Advisory'])
        ->and($bulletin->eventNamesFor($bulletin->matchedSegments([])))->toBe(['Hurricane Watch'])
        ->and(segmentTestBulletin(['product_id' => null])->eventNamesFor(collect()))->toBe([]);
});

test('forSegment finds the active event of a segment\'s VTEC key, scoped to its bulletin\'s is_test flag', function () {
    $real = ActiveWwa::factory()->create(['vtec_office' => 'KMOB', 'vtec_phenomenon' => 'HU', 'vtec_significance' => 'A', 'vtec_event_number' => '1009', 'vtec_year' => 2026, 'is_test' => false]);
    ActiveWwa::factory()->create(['vtec_office' => 'KMOB', 'vtec_phenomenon' => 'SC', 'vtec_significance' => 'Y', 'vtec_event_number' => '0031', 'vtec_year' => 2026, 'is_test' => false]);
    $segment = IngestedProductSegment::factory()->for(segmentTestBulletin(['is_test' => false]))->create([
        'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'HU', 'vtec_significance' => 'A', 'vtec_event_number' => '1009', 'vtec_year' => 2026,
    ]);
    $testSegment = IngestedProductSegment::factory()->for(segmentTestBulletin(['is_test' => true]))->create([
        'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'HU', 'vtec_significance' => 'A', 'vtec_event_number' => '1009', 'vtec_year' => 2026,
    ]);

    expect(ActiveWwa::query()->forSegment($segment)->sole()->id)->toBe($real->id)
        ->and(ActiveWwa::query()->forSegment($testSegment)->count())->toBe(0);
});
