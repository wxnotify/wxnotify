<?php

use App\Actions\Weather\FetchSpcOutlooks;
use App\Enums\OutlookCategory;
use App\Support\Day1OutlookFreshness;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function spcPolygon(): array
{
    return ['type' => 'MultiPolygon', 'coordinates' => [[[[-97.5, 35.5], [-96.5, 35.5], [-96.5, 36.5], [-97.5, 35.5]]]]];
}

function spcCollection(array $labels, string $issue = '202610311300'): string
{
    return json_encode([
        'type' => 'FeatureCollection',
        'features' => array_map(fn (string $label): array => [
            'type' => 'Feature',
            'geometry' => spcPolygon(),
            'properties' => ['LABEL' => $label, 'LABEL2' => 'x', 'ISSUE_ISO' => '2026-10-31T13:00:00Z', 'VALID_ISO' => '2026-10-31T12:00:00Z', 'EXPIRE_ISO' => '2026-11-01T12:00:00Z', 'FORECASTER' => 'A'],
        ], $labels),
    ]);
}

/**
 * @return array<string, mixed>
 */
function spcFiles(): array
{
    $base = 'www.spc.noaa.gov/products/';
    $json = ['Content-Type' => 'application/geo+json'];
    $files = [];

    foreach ([1, 2] as $day) {
        $files["{$base}outlook/day{$day}otlk_cat.nolyr.geojson"] = Http::response(spcCollection(['TSTM', 'SLGT']), 200, $json + ['Last-Modified' => 'Sat, 31 Oct 2026 13:05:00 GMT']);
        $files["{$base}outlook/day{$day}otlk_torn.nolyr.geojson"] = Http::response(spcCollection(['0.05']), 200, $json);
        $files["{$base}outlook/day{$day}otlk_hail.nolyr.geojson"] = Http::response(spcCollection(['0.15']), 200, $json);
        $files["{$base}outlook/day{$day}otlk_wind.nolyr.geojson"] = Http::response(spcCollection(['0.30']), 200, $json);
    }

    $files["{$base}outlook/day3otlk_cat.nolyr.geojson"] = Http::response(spcCollection(['MRGL']), 200, $json);
    $files["{$base}outlook/day3otlk_prob.nolyr.geojson"] = Http::response(spcCollection(['0.05']), 200, $json);

    foreach ([4, 5, 6, 7, 8] as $day) {
        $files["{$base}exper/day4-8/day{$day}prob.nolyr.geojson"] = Http::response(spcCollection(['0.15']), 200, $json);
    }

    return $files;
}

test('a full fetch parses every day into layers of label and geometry', function () {
    Http::fake(spcFiles());

    $result = (new FetchSpcOutlooks)();

    expect(array_keys($result))->toBe(['day_1', 'day_2', 'day_3', 'day_4', 'day_5', 'day_6', 'day_7', 'day_8']);
    expect(array_keys($result['day_1']['layers']))->toBe(['cat', 'torn', 'hail', 'wind']);
    expect($result['day_1']['layers']['cat'])->toBe([
        ['label' => 'TSTM', 'geometry' => spcPolygon()],
        ['label' => 'SLGT', 'geometry' => spcPolygon()],
    ]);
    expect($result['day_1']['layers']['wind'][0]['label'])->toBe('0.30');
    expect($result['day_1']['issued_at'])->toBe('2026-10-31T13:00:00Z');
    expect($result['day_1']['last_modified'])->toBe('Sat, 31 Oct 2026 13:05:00 GMT');
    expect(array_keys($result['day_3']['layers']))->toBe(['cat', 'prob']);
    expect($result['day_3']['issued_at'])->toBe('2026-10-31T13:00:00Z');
    expect(array_keys($result['day_6']['layers']))->toBe(['prob']);
    expect($result['day_6']['layers']['prob'][0]['label'])->toBe('0.15');
});

test('requests go to the fixed SPC host with a user agent, without following redirects', function () {
    Http::fake(spcFiles());

    (new FetchSpcOutlooks)();

    Http::assertSentCount(15);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://www.spc.noaa.gov/products/')
        && $request->hasHeader('User-Agent')
        && $request->header('User-Agent')[0] !== '');
});

test('a 200 with an html body or invalid json makes that layer absent, never no storms', function (string $body) {
    Http::fake(['www.spc.noaa.gov/products/outlook/day1otlk_cat.nolyr.geojson' => Http::response($body, 200)] + spcFiles());

    $day = (new FetchSpcOutlooks)()['day_1'];

    expect($day['layers'])->not->toHaveKey('cat')
        ->and($day['layers'])->toHaveKeys(['torn', 'hail', 'wind']);
})->with([
    'html' => ['<html><body>Service unavailable</body></html>'],
    'invalid json' => ['{"type": "FeatureCollection", "features": ['],
    'wrong type' => ['{"type": "Feature", "features": []}'],
    'features not a list' => ['{"type": "FeatureCollection", "features": "x"}'],
]);

test('a day with no parseable file is null', function () {
    Http::fake([
        'www.spc.noaa.gov/products/exper/day4-8/day5prob.nolyr.geojson' => Http::response('<html></html>', 200),
        'www.spc.noaa.gov/products/outlook/day2otlk_*' => Http::response('', 503),
    ] + spcFiles());

    $result = (new FetchSpcOutlooks)();

    expect($result['day_5'])->toBeNull()
        ->and($result['day_2'])->toBeNull()
        ->and($result['day_1'])->not->toBeNull();
});

test('a connection failure nulls only the files it hit', function () {
    Http::fake([
        'www.spc.noaa.gov/products/outlook/day1otlk_hail.nolyr.geojson' => fn () => throw new ConnectionException('timeout'),
    ] + spcFiles());

    $day = (new FetchSpcOutlooks)()['day_1'];

    expect($day['layers'])->not->toHaveKey('hail')
        ->and($day['layers'])->toHaveKeys(['cat', 'torn', 'wind']);
});

test('an empty features list is a valid quiet day carrying its last modified header', function () {
    Http::fake([
        'www.spc.noaa.gov/products/outlook/day1otlk_cat.nolyr.geojson' => Http::response(spcCollection([]), 200, ['Last-Modified' => 'Sat, 31 Oct 2026 06:10:00 GMT']),
    ] + spcFiles());

    $day = (new FetchSpcOutlooks)()['day_1'];

    expect($day['issued_at'])->toBeNull()
        ->and($day['last_modified'])->toBe('Sat, 31 Oct 2026 06:10:00 GMT')
        ->and($day['layers']['cat'])->toBe([]);
});

test('the less than 2 percent placeholder yields no tornado entry', function () {
    Http::fake([
        'www.spc.noaa.gov/products/outlook/day1otlk_torn.nolyr.geojson' => Http::response(spcCollection(['Less Than 2% All Areas']), 200),
    ] + spcFiles());

    expect((new FetchSpcOutlooks)()['day_1']['layers']['torn'])->toBe([]);
});

test('a result with a null day is not cached, so the next call asks SPC again', function () {
    Http::fake(['www.spc.noaa.gov/products/exper/day4-8/day8prob.nolyr.geojson' => Http::response('', 500)] + spcFiles());

    (new FetchSpcOutlooks)();
    expect(Cache::has('spc-outlooks'))->toBeFalse();
    (new FetchSpcOutlooks)();

    Http::assertSentCount(30);
});

test('a complete result is cached for the next call', function () {
    Http::fake(spcFiles());

    (new FetchSpcOutlooks)();
    (new FetchSpcOutlooks)();

    expect(Cache::has('spc-outlooks'))->toBeTrue();
    Http::assertSentCount(15);
});

test('category labels and probabilities map to outlook categories', function () {
    expect(OutlookCategory::fromLabel('TSTM'))->toBe(OutlookCategory::Thunderstorm)
        ->and(OutlookCategory::fromLabel('MRGL'))->toBe(OutlookCategory::Marginal)
        ->and(OutlookCategory::fromLabel('SLGT'))->toBe(OutlookCategory::Slight)
        ->and(OutlookCategory::fromLabel('ENH'))->toBe(OutlookCategory::Enhanced)
        ->and(OutlookCategory::fromLabel('MDT'))->toBe(OutlookCategory::Moderate)
        ->and(OutlookCategory::fromLabel('HIGH'))->toBe(OutlookCategory::High)
        ->and(OutlookCategory::fromLabel('bogus'))->toBe(OutlookCategory::None)
        ->and(OutlookCategory::fromProbability(0.45))->toBe(OutlookCategory::Enhanced)
        ->and(OutlookCategory::fromProbability(0.30))->toBe(OutlookCategory::Enhanced)
        ->and(OutlookCategory::fromProbability(0.15))->toBe(OutlookCategory::Slight)
        ->and(OutlookCategory::fromProbability(0.05))->toBe(OutlookCategory::None);
});

test('emoji, label and rank follow the scale', function () {
    $cases = OutlookCategory::cases();

    expect(array_map(fn (OutlookCategory $c): string => $c->emoji(), $cases))->toBe(['⚪', '🟤', '🟢', '🟡', '🟠', '🔴', '🟣'])
        ->and(OutlookCategory::Marginal->label())->toBe('Marginal')
        ->and(OutlookCategory::Thunderstorm->label())->toBe('General thunderstorms')
        ->and(OutlookCategory::None->label())->toBe('None')
        ->and(array_map(fn (OutlookCategory $c): int => $c->rank(), $cases))->toBe([0, 1, 2, 3, 4, 5, 6]);
});

test('day 1 freshness is judged against the latest expected cycle', function (string $sendTime, ?array $day1, bool $expected) {
    expect(Day1OutlookFreshness::isCurrent($day1, CarbonImmutable::parse($sendTime, 'UTC')))->toBe($expected);
})->with([
    '14:30 needs 13:00, issued 13:00' => ['2026-10-31 14:30', ['issued_at' => '2026-10-31T13:00:00Z', 'last_modified' => null, 'layers' => []], true],
    '14:30 with the 06:00 issue is stale' => ['2026-10-31 14:30', ['issued_at' => '2026-10-31T06:00:00Z', 'last_modified' => null, 'layers' => []], false],
    '14:30 accepts 12:45, within 15 minutes of the cycle' => ['2026-10-31 14:30', ['issued_at' => '2026-10-31T12:45:00Z', 'last_modified' => null, 'layers' => []], true],
    '08:00 needs 06:00' => ['2026-10-31 08:00', ['issued_at' => '2026-10-31T06:00:00Z', 'last_modified' => null, 'layers' => []], true],
    '08:00 with the 01:00 issue is stale' => ['2026-10-31 08:00', ['issued_at' => '2026-10-31T01:00:00Z', 'last_modified' => null, 'layers' => []], false],
    '00:30 needs the previous 20:00' => ['2026-10-31 00:30', ['issued_at' => '2026-10-30T20:00:00Z', 'last_modified' => null, 'layers' => []], true],
    '00:30 with the previous 16:30 is stale' => ['2026-10-31 00:30', ['issued_at' => '2026-10-30T16:30:00Z', 'last_modified' => null, 'layers' => []], false],
    '14:30 quiet day, last modified current' => ['2026-10-31 14:30', ['issued_at' => null, 'last_modified' => 'Sat, 31 Oct 2026 13:04:00 GMT', 'layers' => []], true],
    '14:30 quiet day, last modified old' => ['2026-10-31 14:30', ['issued_at' => null, 'last_modified' => 'Sat, 31 Oct 2026 06:04:00 GMT', 'layers' => []], false],
    'nothing readable is treated as current' => ['2026-10-31 14:30', ['issued_at' => null, 'last_modified' => null, 'layers' => []], true],
    'an unparseable stamp is treated as current' => ['2026-10-31 14:30', ['issued_at' => 'garbage', 'last_modified' => null, 'layers' => []], true],
    'a null day 1 is not current' => ['2026-10-31 14:30', null, false],
]);
