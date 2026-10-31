<?php

use App\Actions\Weather\BuildOutlookReport;
use App\Actions\Weather\LocationsInOutlookAreas;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\Product;
use App\Support\OutlookReportText;
use Illuminate\Database\QueryException;

function olkBox(float $west, float $south, float $east, float $north): array
{
    return [[$west, $south], [$east, $south], [$east, $north], [$west, $north], [$west, $south]];
}

function olkArea(): array
{
    return ['type' => 'MultiPolygon', 'coordinates' => [[olkBox(-98, 35, -96, 37)]]];
}

/**
 * @param  array<string, list<array{label: string, geometry: array}>>  $layers
 */
function olkDay(array $layers): array
{
    return ['issued_at' => '2026-10-31T13:00:00Z', 'last_modified' => null, 'layers' => $layers];
}

function olkFeature(string $label, ?array $geometry = null): array
{
    return ['label' => $label, 'geometry' => $geometry ?? olkArea()];
}

/**
 * @return array<string, ?array>
 */
function olkOutlooks(): array
{
    return [
        'day_1' => olkDay([
            'cat' => [olkFeature('SLGT')],
            'torn' => [olkFeature('0.02')],
            'hail' => [olkFeature('0.05'), olkFeature('0.15')],
            'wind' => [],
        ]),
        'day_2' => olkDay(['cat' => [olkFeature('MRGL')], 'torn' => [], 'hail' => [], 'wind' => []]),
        'day_3' => olkDay(['cat' => [olkFeature('TSTM')], 'prob' => [olkFeature('0.05')]]),
        'day_4' => olkDay(['prob' => []]),
        'day_5' => olkDay(['prob' => [olkFeature('0.15')]]),
        'day_6' => olkDay(['prob' => []]),
        'day_7' => olkDay(['prob' => []]),
        'day_8' => olkDay(['prob' => []]),
    ];
}

function olkQuiet(): array
{
    return [
        'day_1' => olkDay(['cat' => [], 'torn' => [], 'hail' => [], 'wind' => []]),
        'day_2' => olkDay(['cat' => [], 'torn' => [], 'hail' => [], 'wind' => []]),
        'day_3' => olkDay(['cat' => [], 'prob' => []]),
        'day_4' => olkDay(['prob' => []]),
        'day_5' => olkDay(['prob' => []]),
        'day_6' => olkDay(['prob' => []]),
        'day_7' => olkDay(['prob' => []]),
        'day_8' => olkDay(['prob' => []]),
    ];
}

function olkInside(array $attributes = []): Location
{
    return Location::factory()->create($attributes + ['label' => 'Inside', 'lat' => 36.0, 'lng' => -97.0]);
}

test('the payload carries categories, levels and per-location rows for a fixture outlook set', function () {
    $inside = olkInside();
    $hawaii = Location::factory()->create(['label' => 'Honolulu', 'lat' => 21.3, 'lng' => -157.8]);
    $noFix = Location::factory()->create(['label' => 'No fix', 'lat' => null, 'lng' => null]);

    $payload = app(BuildOutlookReport::class)(olkOutlooks(), collect([$inside, $hawaii, $noFix]));

    expect($payload['days'][1])->toBe([
        'available' => true,
        'issued_at' => '2026-10-31T13:00:00Z',
        'category' => 'slight',
        'hail' => '15%',
        'wind' => null,
        'tornado' => '2%',
        'any_severe' => null,
        'missing' => [],
        'locations' => [
            ['label' => 'Inside', 'category' => 'slight', 'hail' => '15%', 'wind' => null, 'tornado' => '2%'],
            ['label' => 'Honolulu', 'category' => 'none', 'hail' => null, 'wind' => null, 'tornado' => null],
        ],
    ]);
    expect($payload['days'][2]['category'])->toBe('marginal')
        ->and($payload['days'][2]['locations'][0])->toBe(['label' => 'Inside', 'category' => 'marginal', 'hail' => null, 'wind' => null, 'tornado' => null])
        ->and($payload['days'][3]['category'])->toBe('thunderstorm')
        ->and($payload['days'][3]['any_severe'])->toBe('5%')
        ->and($payload['days'][3]['locations'][0]['category'])->toBe('thunderstorm')
        ->and($payload['extended'][4])->toBe(['available' => true, 'probability' => null, 'category' => 'none', 'highlight' => false, 'locations' => []])
        ->and($payload['extended'][5])->toBe(['available' => true, 'probability' => '15%', 'category' => 'slight', 'highlight' => true, 'locations' => ['Inside']])
        ->and($payload['pwo'])->toBeNull()
        ->and($payload['something_forecast'])->toBeTrue()
        ->and($payload['day1_missing'])->toBeFalse();
});

test('zero chosen locations give empty location lists', function () {
    $payload = app(BuildOutlookReport::class)(olkOutlooks(), collect());

    expect($payload['days'][1]['locations'])->toBe([])
        ->and($payload['days'][3]['locations'])->toBe([])
        ->and($payload['extended'][5]['locations'])->toBe([])
        ->and($payload['extended'][5]['highlight'])->toBeTrue();
});

test('a location is classified by the highest category layer that contains it', function () {
    $outlooks = olkQuiet();
    $outlooks['day_1'] = olkDay([
        'cat' => [olkFeature('SLGT'), olkFeature('ENH', ['type' => 'Polygon', 'coordinates' => [olkBox(-97.5, 35.5, -96.5, 36.5)]])],
        'torn' => [], 'hail' => [], 'wind' => [olkFeature('0.30', ['type' => 'Polygon', 'coordinates' => [olkBox(-97.5, 35.5, -96.5, 36.5)]])],
    ]);
    $core = olkInside(['label' => 'Core']);
    $rim = Location::factory()->create(['label' => 'Rim', 'lat' => 35.2, 'lng' => -97.9]);

    $day1 = app(BuildOutlookReport::class)($outlooks, collect([$core, $rim]))['days'][1];

    expect($day1['category'])->toBe('enhanced')
        ->and($day1['wind'])->toBe('30%')
        ->and($day1['locations'][0])->toMatchArray(['label' => 'Core', 'category' => 'enhanced', 'wind' => '30%'])
        ->and($day1['locations'][1])->toMatchArray(['label' => 'Rim', 'category' => 'slight', 'wind' => null]);
});

test('an unavailable Day 1 sets day1_missing and renders a question mark', function (array $day1) {
    $outlooks = olkOutlooks();
    $outlooks['day_1'] = $day1['value'];

    $payload = app(BuildOutlookReport::class)($outlooks, collect([olkInside()]));

    expect($payload['day1_missing'])->toBeTrue()
        ->and($payload['days'][1]['available'])->toBeFalse()
        ->and($payload['days'][1]['category'])->toBeNull()
        ->and($payload['days'][1]['locations'])->toBe([])
        ->and(OutlookReportText::subject($payload))->toStartWith('Severe Wx Outlook - ❔ D1 | 🟢 D2')
        ->and(OutlookReportText::brief($payload))->toStartWith('D1 ❔  D2 🟢');
})->with([
    'null' => [['value' => null]],
    'no cat layer' => [['value' => ['issued_at' => null, 'last_modified' => null, 'layers' => ['torn' => []]]]],
]);

test('other unavailable days render a question mark and are ignored for something_forecast', function () {
    $outlooks = olkQuiet();
    $outlooks['day_2'] = null;
    $outlooks['day_3'] = olkDay(['prob' => []]);
    $outlooks['day_4'] = null;
    $outlooks['day_5'] = olkDay([]);

    $payload = app(BuildOutlookReport::class)($outlooks, collect([olkInside()]));

    expect($payload['days'][2]['available'])->toBeFalse()
        ->and($payload['days'][3]['available'])->toBeFalse()
        ->and($payload['extended'][4])->toBe(['available' => false, 'probability' => null, 'category' => null, 'highlight' => false, 'locations' => []])
        ->and($payload['extended'][5]['available'])->toBeFalse()
        ->and($payload['something_forecast'])->toBeFalse()
        ->and(OutlookReportText::subject($payload))->toBe('Severe Wx Outlook - ⚪ D1 | ❔ D2 | ❔ D3 | ❔ D4 | ❔ D5 | ⚪ D6 | ⚪ D7 | ⚪ D8')
        ->and(OutlookReportText::brief($payload))->toBe('D1 ⚪  D2 ❔  D3 ❔  D4-8 ⚪');
});

test('something_forecast follows the thresholds', function (string $day, array $layers, bool $expected) {
    $outlooks = olkQuiet();
    $outlooks[$day] = olkDay($layers);

    $payload = app(BuildOutlookReport::class)($outlooks, collect());

    expect($payload['something_forecast'])->toBe($expected);
})->with([
    'quiet' => ['day_1', ['cat' => []], false],
    'thunderstorms only' => ['day_2', ['cat' => [olkFeature('TSTM')]], false],
    'marginal day 1' => ['day_1', ['cat' => [olkFeature('MRGL')]], true],
    'slight day 3' => ['day_3', ['cat' => [olkFeature('SLGT')], 'prob' => []], true],
    '5 percent day 6' => ['day_6', ['prob' => [olkFeature('0.05')]], false],
    '15 percent day 8' => ['day_8', ['prob' => [olkFeature('0.15')]], true],
]);

test('the PWO is included only for a Moderate or High Day 1', function (string $label, bool $included) {
    $outlooks = olkQuiet();
    $outlooks['day_1'] = olkDay(['cat' => [olkFeature($label)], 'torn' => [], 'hail' => [], 'wind' => []]);
    $product = IngestedProduct::factory()->create([
        'text' => 'PWO TEXT',
        'received_at' => '2026-10-31 12:00:00',
        'product_id' => Product::factory()->create(['name' => 'Public Severe Weather Outlook (Test)']),
    ]);

    $payload = app(BuildOutlookReport::class)($outlooks, collect(), $product);

    expect($payload['pwo'])->toBe($included
        ? ['title' => 'Public Severe Weather Outlook (Test)', 'text' => 'PWO TEXT', 'issued_at' => '2026-10-31T12:00:00+00:00']
        : null);
})->with([
    'slight' => ['SLGT', false],
    'enhanced' => ['ENH', false],
    'moderate' => ['MDT', true],
    'high' => ['HIGH', true],
]);

test('no PWO product means no pwo entry even for a High Day 1', function () {
    $outlooks = olkQuiet();
    $outlooks['day_1'] = olkDay(['cat' => [olkFeature('HIGH')], 'torn' => [], 'hail' => [], 'wind' => []]);

    expect(app(BuildOutlookReport::class)($outlooks, collect())['pwo'])->toBeNull();
});

test('the subject and brief match the documented strings', function () {
    $outlooks = olkOutlooks();
    $outlooks['day_1']['layers']['cat'] = [olkFeature('ENH')];

    $payload = app(BuildOutlookReport::class)($outlooks, collect());

    expect(OutlookReportText::subject($payload))->toBe('Severe Wx Outlook - 🟠 D1 | 🟢 D2 | 🟤 D3 | ⚪ D4 | 🟡 D5 | ⚪ D6 | ⚪ D7 | ⚪ D8')
        ->and(OutlookReportText::brief($payload))->toBe('D1 🟠  D2 🟢  D3 🟤  D4-8 🟡');
});

test('locations in outlook areas honour holes, edges and multipolygons in one query', function () {
    $shape = [
        'type' => 'MultiPolygon',
        'coordinates' => [
            [olkBox(-98, 35, -96, 37), olkBox(-97.4, 35.4, -96.6, 36.6)],
            [olkBox(-90, 30, -89, 31)],
        ],
    ];
    $solid = olkInside(['label' => 'Solid', 'lat' => 35.2, 'lng' => -97.8]);
    $inHole = olkInside(['label' => 'Hole', 'lat' => 36.0, 'lng' => -97.0]);
    $onEdge = olkInside(['label' => 'Edge', 'lat' => 37.0, 'lng' => -97.0]);
    $onHoleEdge = olkInside(['label' => 'Hole edge', 'lat' => 36.0, 'lng' => -97.4]);
    $second = olkInside(['label' => 'Second part', 'lat' => 30.5, 'lng' => -89.5]);
    $hawaii = olkInside(['label' => 'Hawaii', 'lat' => 21.3, 'lng' => -157.8]);
    $noFix = Location::factory()->create(['lat' => null, 'lng' => null]);

    DB::enableQueryLog();
    $ids = app(LocationsInOutlookAreas::class)($shape, collect([$solid, $inHole, $onEdge, $onHoleEdge, $second, $hawaii, $noFix]));
    $queries = DB::getQueryLog();

    expect($ids)->toEqualCanonicalizing([$solid->id, $onEdge->id, $onHoleEdge->id, $second->id])
        ->and($queries)->toHaveCount(1);
});

test('locations in outlook areas returns nothing for no locations or a malformed geometry', function () {
    $location = olkInside();

    expect(app(LocationsInOutlookAreas::class)(olkArea(), collect()))->toBe([])
        ->and(app(LocationsInOutlookAreas::class)(['type' => 'Polygon'], collect([$location])))->toBe([]);
});

test('a geometry PostGIS refuses matches nothing, logs only the class and SQLSTATE, and keeps the transaction usable', function () {
    $location = olkInside();
    $broken = ['type' => 'SECRETTEXT', 'coordinates' => []];
    Log::spy();

    $result = DB::transaction(function () use ($broken, $location): array {
        $found = app(LocationsInOutlookAreas::class)($broken, collect([$location]));

        return [$found, app(LocationsInOutlookAreas::class)(olkArea(), collect([$location]))];
    });

    expect($result)->toBe([[], [$location->id]]);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => array_keys($context) === ['exception', 'sqlstate']
        && $context['exception'] === QueryException::class
        && ! str_contains(json_encode($context), 'SECRETTEXT'));
});

test('a self-intersecting bow-tie polygon is made valid and still finds a point in a lobe', function () {
    $bowTie = ['type' => 'Polygon', 'coordinates' => [[[-98, 35], [-96, 37], [-96, 35], [-98, 37], [-98, 35]]]];
    $lobe = olkInside(['lat' => 36.0, 'lng' => -97.9]);
    $outside = olkInside(['lat' => 36.0, 'lng' => -95.0]);

    $ids = app(LocationsInOutlookAreas::class)($bowTie, collect([$lobe, $outside]));

    expect($ids)->toBe([$lobe->id]);
});

test('an absent hazard layer is listed as missing while an empty one is none', function () {
    $outlooks = olkOutlooks();
    unset($outlooks['day_1']['layers']['torn']);
    $outlooks['day_3'] = olkDay(['cat' => [olkFeature('TSTM')]]);

    $payload = app(BuildOutlookReport::class)($outlooks, collect([olkInside()]));

    expect($payload['days'][1]['missing'])->toBe(['tornado'])
        ->and($payload['days'][1]['tornado'])->toBeNull()
        ->and($payload['days'][1]['wind'])->toBeNull()
        ->and($payload['days'][2]['missing'])->toBe([])
        ->and($payload['days'][3]['missing'])->toBe(['any_severe'])
        ->and($payload['days'][3]['available'])->toBeTrue();
});

test('the payload carries the report date as an ISO date string, or null when none is given', function () {
    expect(app(BuildOutlookReport::class)(olkQuiet(), collect(), null, '2026-10-31')['report_date'])->toBe('2026-10-31')
        ->and(app(BuildOutlookReport::class)(olkQuiet(), collect())['report_date'])->toBeNull();
});
