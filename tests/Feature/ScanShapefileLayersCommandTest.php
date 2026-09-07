<?php

use App\Models\ShapefileLayer;
use Database\Seeders\ShapefileLayerSeeder;
use Illuminate\Support\Facades\Http;

test('it scans every seeded layer and reports how many staged a new version', function () {
    (new ShapefileLayerSeeder)->run();

    Http::fake(fn () => Http::response('<table><tbody><tr><td>x</td></tr></tbody></table>'));

    $this->artisan('shapefile:scan')
        ->assertSuccessful();

    expect(ShapefileLayer::count())->toBe(9);
    Http::assertSentCount(9);
});
