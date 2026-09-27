<?php

use App\Actions\Weather\MatchWeatherAlertRules;
use App\Models\ActiveWwa;
use App\Models\IngestedProduct;
use App\Models\Product;

function replayVtecAttributes(array $overrides = []): array
{
    return [
        'product_id' => Product::where('code', 'TO.W')->sole()->id,
        'format' => 'text',
        'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W',
        'vtec_event_number' => '0042', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'ugc_codes' => ['NYZ069'],
        'is_test' => true,
        ...$overrides,
    ];
}

test('a replay copy of a VTEC product is matched without writing active_wwas', function () {
    $spy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $spy);
    $original = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create());

    IngestedProduct::factory()->create(replayVtecAttributes(['replay_of_id' => $original->id]));

    $spy->shouldHaveReceived('__invoke')->once();
    expect(ActiveWwa::count())->toBe(0);
});

test('a VTEC product that is not a replay still writes active_wwas', function () {
    $spy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $spy);

    IngestedProduct::factory()->create(replayVtecAttributes());

    expect(ActiveWwa::count())->toBe(1);
});

test('a replay copy of a non-VTEC product is matched once', function () {
    $spy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $spy);
    $original = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create());

    IngestedProduct::factory()->create(['is_test' => true, 'replay_of_id' => $original->id]);

    $spy->shouldHaveReceived('__invoke')->once();
});

test('replay relations link a copy to its original and deleting the original clears the link', function () {
    $original = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create());
    $copy = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create(['is_test' => true, 'replay_of_id' => $original->id]));

    expect($copy->replayOf->is($original))->toBeTrue()
        ->and($original->replays->pluck('id')->all())->toBe([$copy->id]);

    $original->delete();

    expect($copy->fresh()->replay_of_id)->toBeNull();
});
