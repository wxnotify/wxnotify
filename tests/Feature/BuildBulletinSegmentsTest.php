<?php

use App\Actions\Pipeline\BuildBulletinSegments;
use App\Actions\Pipeline\ParseBulletinSegments;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use App\Models\Product;

/** A bulletin made without the observer, so no job builds segments behind the test's back. */
function buildSegmentsBulletin(array $attributes = []): IngestedProduct
{
    return IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create($attributes));
}

function buildSegmentsFixtureBulletin(string $example, array $attributes = []): IngestedProduct
{
    return buildSegmentsBulletin([
        'text' => file_get_contents(base_path("database/product_examples/bulletins/{$example}.txt")),
        'format' => 'text',
        'vtec_office' => 'KMOB',
        ...$attributes,
    ]);
}

test('stores one row per section and VTEC line, numbered in bulletin order', function () {
    $bulletin = buildSegmentsFixtureBulletin('TCV_MOB');

    app(BuildBulletinSegments::class)($bulletin);

    $segments = $bulletin->segments()->get();
    expect($segments)->toHaveCount(19)
        ->and($segments->pluck('position')->all())->toBe(range(0, 18))
        ->and($segments[0]->ugc_codes)->toBe(['ALZ266'])
        ->and($segments[0]->product_id)->toBe(Product::where('code', 'HU.A')->sole()->id);
});

test('is idempotent: building again replaces the segments rather than adding to them', function () {
    $bulletin = buildSegmentsFixtureBulletin('MWW_MOB');

    app(BuildBulletinSegments::class)($bulletin);
    app(BuildBulletinSegments::class)($bulletin);

    expect(IngestedProductSegment::count())->toBe(3);
});

test('rebuilding after the text changed leaves no stale segment', function () {
    $bulletin = buildSegmentsFixtureBulletin('MWW_MOB');
    app(BuildBulletinSegments::class)($bulletin);

    $bulletin->update(['text' => file_get_contents(base_path('database/product_examples/bulletins/NPW_FGZ.txt'))]);
    app(BuildBulletinSegments::class)($bulletin->fresh());

    expect(IngestedProductSegment::count())->toBe(1)
        ->and(IngestedProductSegment::sole()->vtec_phenomenon)->toBe('WI');
});

test('a CAP message gets exactly one segment, taken from its own columns', function () {
    $hurricaneWatch = Product::where('code', 'HU.A')->sole();
    $bulletin = buildSegmentsBulletin([
        'format' => 'cap', 'text' => '<?xml version="1.0"?><alert/>', 'product_id' => $hurricaneWatch->id,
        'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'HU', 'vtec_significance' => 'A', 'vtec_event_number' => '1009',
        'vtec_year' => 2026, 'vtec_action' => 'CON', 'ugc_codes' => ['FLZ206'],
        'polygon' => [['lat' => 30.0, 'lon' => -86.0], ['lat' => 30.5, 'lon' => -86.0], ['lat' => 30.5, 'lon' => -86.5]],
    ]);

    app(BuildBulletinSegments::class)($bulletin);

    $segment = IngestedProductSegment::sole();
    expect($segment->ingested_product_id)->toBe($bulletin->id)
        ->and($segment->position)->toBe(0)
        ->and($segment->product_id)->toBe($hurricaneWatch->id)
        ->and($segment->vtec_action)->toBe('CON')
        ->and($segment->ugc_codes)->toBe(['FLZ206'])
        ->and($segment->polygon)->toHaveCount(3);
});

test('a text bulletin whose sections cannot be read falls back to one segment from its own columns', function () {
    $bulletin = buildSegmentsBulletin([
        'format' => 'text', 'text' => 'Garbled bulletin with no readable sections.', 'product_id' => Product::where('code', 'TO.W')->sole()->id,
        'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W', 'vtec_event_number' => '0042',
        'vtec_year' => 2026, 'vtec_action' => 'NEW', 'ugc_codes' => ['NYZ069'],
    ]);

    app(BuildBulletinSegments::class)($bulletin);

    $segment = IngestedProductSegment::sole();
    expect($segment->ugc_codes)->toBe(['NYZ069'])
        ->and($segment->vtec_phenomenon)->toBe('TO')
        ->and($segment->product_id)->toBe($bulletin->product_id);
});

test('a bulletin with no VTEC office gets no segments', function () {
    $bulletin = buildSegmentsBulletin(['text' => 'Area Forecast Discussion', 'vtec_office' => null]);

    app(BuildBulletinSegments::class)($bulletin);

    expect(IngestedProductSegment::count())->toBe(0);
});

test('a parse failure propagates and leaves the existing segments in place', function () {
    $bulletin = buildSegmentsFixtureBulletin('MWW_MOB');
    app(BuildBulletinSegments::class)($bulletin);
    $failing = Mockery::mock(ParseBulletinSegments::class);
    $failing->shouldReceive('__invoke')->andThrow(new RuntimeException('boom'));

    expect(fn () => (new BuildBulletinSegments($failing))($bulletin))->toThrow(RuntimeException::class, 'boom')
        ->and(IngestedProductSegment::count())->toBe(3);
});
