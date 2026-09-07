<?php

use App\Support\Shapefiles\ShapefilePageScraper;
use Illuminate\Support\Carbon;

test('it scans a single-group page and takes the latest version', function () {
    $html = file_get_contents(dirname(__DIR__).'/Fixtures/awips-shapefiles/public-zones-page.html');

    $groups = (new ShapefilePageScraper)->scan($html);

    expect($groups)->toHaveCount(1);
    $versions = $groups[0]['versions'];
    expect($versions)->toHaveCount(2);

    $latest = collect($versions)->sortByDesc('effective_at')->first();
    expect($latest['filename'])->toBe('z_16ap26.zip')
        ->and($latest['checksum'])->toBe('004dc6501dc3d50e7b36652cb9d02bd3')
        ->and($latest['record_count'])->toBe(4157)
        ->and($latest['url'])->toBe('https://www.weather.gov/source/gis/Shapefiles/WSOM/z_16ap26.zip')
        ->and($latest['effective_at']->equalTo(Carbon::parse('2026-04-16')))->toBeTrue();
});

test('it scans a 3-group page (Marine Zones) into 3 separate version groups', function () {
    $html = file_get_contents(dirname(__DIR__).'/Fixtures/awips-shapefiles/marine-zones-page.html');

    $groups = (new ShapefilePageScraper)->scan($html);

    expect($groups)->toHaveCount(3);

    $descriptions = collect($groups)->pluck('description');
    expect($descriptions->filter(fn ($d) => str_contains($d, 'Coastal')))->toHaveCount(1)
        ->and($descriptions->filter(fn ($d) => str_contains($d, 'Offshore')))->toHaveCount(1)
        ->and($descriptions->filter(fn ($d) => str_contains($d, 'High Seas')))->toHaveCount(1);

    $highSeas = collect($groups)->first(fn ($g) => str_contains($g['description'], 'High Seas'));
    expect($highSeas['versions'])->toHaveCount(2);
    $latest = collect($highSeas['versions'])->sortByDesc('effective_at')->first();
    expect($latest['filename'])->toBe('hz17fe26.zip')
        ->and($latest['record_count'])->toBe(5);
});
