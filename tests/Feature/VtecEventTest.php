<?php

use App\Models\ActiveWwa;
use App\Models\Product;
use App\Support\VtecEvent;

test('a VTEC phenomenon and significance become the NWS event name', function (string $phenomenon, string $significance, string $name) {
    expect(VtecEvent::name($phenomenon, $significance))->toBe($name);
})->with([
    'rip current statement' => ['RP', 'S', 'Rip Current Statement'],
    'storm surge watch' => ['SS', 'A', 'Storm Surge Watch'],
    'gale warning' => ['GL', 'W', 'Gale Warning'],
    'storm warning' => ['SR', 'W', 'Storm Warning'],
    'hurricane force wind warning' => ['HF', 'W', 'Hurricane Force Wind Warning'],
    'special marine warning' => ['MA', 'W', 'Special Marine Warning'],
    'beach hazards statement' => ['BH', 'S', 'Beach Hazards Statement'],
    'high surf advisory' => ['SU', 'Y', 'High Surf Advisory'],
    'coastal flood statement' => ['CF', 'S', 'Coastal Flood Statement'],
    'flood watch (areal flood)' => ['FA', 'A', 'Flood Watch'],
    'flood watch (river flood)' => ['FL', 'A', 'Flood Watch'],
    'extreme heat warning' => ['XH', 'W', 'Extreme Heat Warning'],
    'lower case is accepted' => ['rp', 's', 'Rip Current Statement'],
]);

test('a code the table does not know has no name', function (string $phenomenon, string $significance) {
    expect(VtecEvent::name($phenomenon, $significance))->toBeNull();
})->with([
    'unknown phenomenon' => ['ZZ', 'W'],
    'unknown significance' => ['RP', 'Q'],
]);

test('the significance letter gives the family the widget colours and sorts by', function (string $significance, ?string $family) {
    expect(VtecEvent::family($significance))->toBe($family);
})->with([
    'warning' => ['W', 'warning'],
    'watch' => ['A', 'watch'],
    'advisory' => ['Y', 'advisory'],
    'statement' => ['S', 'statement'],
    'forecast' => ['F', null],
    'unknown' => ['Q', null],
]);

test('an active event with no catalog product still names itself, and a catalog product wins when there is one', function () {
    $uncatalogued = ActiveWwa::factory()->make(['product_id' => null, 'vtec_phenomenon' => 'SS', 'vtec_significance' => 'A']);
    $unknown = ActiveWwa::factory()->make(['product_id' => null, 'vtec_phenomenon' => 'ZZ', 'vtec_significance' => 'Q']);
    $catalogued = ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => 'Catalog Name', 'family' => 'advisory'])->id,
        'vtec_phenomenon' => 'SS', 'vtec_significance' => 'A',
    ]);

    expect($uncatalogued->eventName())->toBe('Storm Surge Watch')
        ->and($uncatalogued->eventFamily())->toBe('watch')
        ->and($unknown->eventName())->toBe('Weather alert')
        ->and($unknown->eventFamily())->toBeNull()
        ->and($catalogued->eventName())->toBe('Catalog Name')
        ->and($catalogued->eventFamily())->toBe('advisory');
});
