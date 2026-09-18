<?php

use App\Actions\Testing\GenerateTestProduct;
use App\Models\ActiveWwa;
use App\Models\IngestedProduct;
use App\Models\Product;
use App\Models\TestProductScenario;

test('generates a warning-shape product, marks it is_test, and links it to the scenario', function () {
    $product = Product::where('code', 'SV.W')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => [
            'mode' => 'new',
            'vtec_action' => 'NEW',
            'vtec_event_number' => '9010',
            'wfo' => 'PAH',
            'expires_minutes' => 30,
            'ugc_codes' => ['MOC023'],
            'hazard' => '60 mph wind gusts.',
            'hazard_source' => 'Radar indicated.',
            'impact' => 'Tree damage possible.',
            'call_to_action' => 'Move to an interior room.',
            'polygon_mode' => 'point_radius',
            'polygon_center_lat' => 36.83,
            'polygon_center_lon' => -89.12,
            'polygon_radius_miles' => 8,
            'damage_tags' => [],
        ],
    ]);

    $result = app(GenerateTestProduct::class)($scenario);

    expect($result)->toBeInstanceOf(IngestedProduct::class)
        ->and($result->is_test)->toBeTrue()
        ->and($result->test_product_scenario_id)->toBe($scenario->id)
        ->and($result->vtec_office)->toBe('KPAH')
        ->and($result->vtec_event_number)->toBe('9010')
        ->and($result->product_id)->toBe($product->id);
});

test('a second generation from the same scenario creates a second, distinct IngestedProduct row', function () {
    $product = Product::where('code', 'AFD')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => ['mode' => 'new', 'wfo' => 'PIH', 'ugc_codes' => [], 'body_text' => 'Clear skies expected.'],
    ]);

    $first = app(GenerateTestProduct::class)($scenario);
    $second = app(GenerateTestProduct::class)($scenario);

    expect($first->id)->not->toBe($second->id)
        ->and($scenario->fresh()->ingestedProducts)->toHaveCount(2)
        ->and(IngestedProduct::where('is_test', true)->count())->toBe(2);
});

test('bails out with a clear exception instead of looping forever once the reserved event-number pool is exhausted', function () {
    $product = Product::where('code', 'TO.W')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => [
            'mode' => 'new', 'vtec_action' => 'NEW', 'wfo' => 'TWC', 'expires_minutes' => 45,
            'ugc_codes' => ['AZC019'], 'hazard' => 'a', 'hazard_source' => 'b', 'impact' => 'c', 'call_to_action' => 'd',
            'polygon_mode' => 'point_radius', 'polygon_center_lat' => 32.2, 'polygon_center_lon' => -110.9, 'polygon_radius_miles' => 5,
            'damage_tags' => [],
        ],
    ]);

    $this->partialMock(GenerateTestProduct::class, function ($mock) {
        $mock->shouldAllowMockingProtectedMethods()
            ->shouldReceive('reservedEventNumberIsTaken')
            ->andReturn(true);
    });

    app(GenerateTestProduct::class)($scenario);
})->throws(RuntimeException::class, 'Unable to find an unused reserved VTEC event number in the 9000-9999 range — too many open synthetic events. Close some out via the continue-existing-event flow.');

test('the collision check is scoped to the full VTEC composite key, not just the event number', function () {
    $action = app(GenerateTestProduct::class);
    $year = (int) now()->year;

    ActiveWwa::factory()->create([
        'vtec_office' => 'KPAH',
        'vtec_phenomenon' => 'SV',
        'vtec_significance' => 'W',
        'vtec_event_number' => '9099',
        'vtec_year' => $year,
        'status' => 'Active',
    ]);

    $method = new ReflectionMethod($action, 'reservedEventNumberIsTaken');
    $method->setAccessible(true);

    // Same event number, different office/phenomenon -- not a collision.
    expect($method->invoke($action, [
        'vtec_office' => 'KTWC',
        'vtec_phenomenon' => 'TO',
        'vtec_significance' => 'W',
        'vtec_year' => $year,
    ], 9099))->toBeFalse();

    // Exact same composite key -- a real collision.
    expect($method->invoke($action, [
        'vtec_office' => 'KPAH',
        'vtec_phenomenon' => 'SV',
        'vtec_significance' => 'W',
        'vtec_year' => $year,
    ], 9099))->toBeTrue();
});

test('draws warning-family event numbers from the reserved 9000-9999 range, never colliding with a real event', function () {
    $product = Product::where('code', 'TO.W')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => [
            'mode' => 'new', 'vtec_action' => 'NEW', 'wfo' => 'TWC', 'expires_minutes' => 45,
            'ugc_codes' => ['AZC019'], 'hazard' => 'a', 'hazard_source' => 'b', 'impact' => 'c', 'call_to_action' => 'd',
            'polygon_mode' => 'point_radius', 'polygon_center_lat' => 32.2, 'polygon_center_lon' => -110.9, 'polygon_radius_miles' => 5,
            'damage_tags' => [],
        ],
    ]);

    $result = app(GenerateTestProduct::class)($scenario);

    expect((int) $result->vtec_event_number)->toBeGreaterThanOrEqual(9000)
        ->and((int) $result->vtec_event_number)->toBeLessThanOrEqual(9999);
});
