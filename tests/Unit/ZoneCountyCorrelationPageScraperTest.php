<?php

use App\Support\Shapefiles\ZoneCountyCorrelationPageScraper;

test('it scans the correlation page, skips the header row, and finds the latest version', function () {
    $html = file_get_contents(dirname(__DIR__).'/Fixtures/awips-shapefiles/zone-county-page.html');

    $groups = (new ZoneCountyCorrelationPageScraper)->scan($html);

    expect($groups)->toHaveCount(1);
    $versions = $groups[0]['versions'];
    expect($versions)->toHaveCount(2);

    $latest = collect($versions)->sortByDesc('effective_at')->first();
    expect($latest['filename'])->toBe('bp16ap26.dbx')
        ->and($latest['url'])->toBe('https://www.weather.gov/source/gis/Shapefiles/County/bp16ap26.dbx')
        ->and($latest['checksum'])->toBeNull()
        ->and($latest['record_count'])->toBeNull();
});
