<?php

use App\Actions\Map\GetActiveMesoscaleDiscussions;
use App\Models\IngestedProduct;
use App\Models\Product;

function mesoscaleDiscussionText(?string $valid = 'VALID 202355Z - 210200Z', ?string $number = '1572', ?string $areas = 'CENTRAL/SOUTHERN AZ...FAR ERN CA...FAR SRN NV'): string
{
    return implode("\n", array_filter([
        'ACUS11 KWNS 202355',
        'SWOMCD',
        'SPC MCD 202355',
        '',
        $number === null ? null : "MESOSCALE DISCUSSION {$number}",
        'NWS STORM PREDICTION CENTER NORMAN OK',
        '0655 PM CDT SAT AUG 20 2016',
        '',
        $areas === null ? null : "AREAS AFFECTED...{$areas}",
        '',
        'CONCERNING...SEVERE POTENTIAL...WATCH UNLIKELY',
        '',
        $valid,
        '',
        'SUMMARY...ISOLATED INSTANCES OF HAIL AND DAMAGING WIND WILL BE POSSIBLE.',
    ], fn (?string $line): bool => $line !== null));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function mesoscaleDiscussionCreate(array $attributes = []): IngestedProduct
{
    return IngestedProduct::factory()->createQuietly([
        'product_id' => Product::query()->where('code', 'SPC.MD')->value('id'),
        'awipsid' => 'swomcd',
        'cccc' => 'kwns',
        'text' => mesoscaleDiscussionText(),
        'polygon' => [['lat' => 33.31, 'lon' => -114.29], ['lat' => 34.5, 'lon' => -112.0], ['lat' => 32.0, 'lon' => -111.5]],
        'received_at' => now()->subMinutes(5),
        'view_slug' => 'kwns-swomcd-'.fake()->unique()->numerify('######'),
        ...$attributes,
    ]);
}

test('a fresh discussion gives its number, areas, VALID end, product page and closed lng,lat polygon', function () {
    config(['wxnotify.product_domain' => 'products.example.test']);
    $this->travelTo('2026-08-20 23:58:00');
    $md = mesoscaleDiscussionCreate(['received_at' => '2026-08-20 23:55:00']);

    expect((new GetActiveMesoscaleDiscussions)())->toBe([[
        'id' => $md->id,
        'number' => '1572',
        'areas' => 'CENTRAL/SOUTHERN AZ...FAR ERN CA...FAR SRN NV',
        'expires' => '2026-08-21T02:00:00+00:00',
        'view_url' => 'https://products.example.test/view-product/'.$md->view_slug,
        'geometry' => ['type' => 'Polygon', 'coordinates' => [[[-114.29, 33.31], [-112.0, 34.5], [-111.5, 32.0], [-114.29, 33.31]]]],
    ]]);
});

test('a VALID end on an earlier day of the month rolls into the next month', function () {
    $this->travelTo('2026-10-31 23:30:00');
    mesoscaleDiscussionCreate(['received_at' => '2026-10-31 23:20:00', 'text' => mesoscaleDiscussionText('VALID 312320Z - 010130Z')]);

    expect((new GetActiveMesoscaleDiscussions)()[0]['expires'])->toBe('2026-11-01T01:30:00+00:00');
});

test('a discussion that arrives just after its VALID end is expired, not rolled a month ahead', function () {
    $this->travelTo('2026-08-21 02:10:00');
    mesoscaleDiscussionCreate(['received_at' => '2026-08-21 02:05:00']);

    expect((new GetActiveMesoscaleDiscussions)())->toBe([]);
});

test('a discussion with no readable VALID line is shown until three hours after it arrived', function (string $valid) {
    $this->travelTo('2026-08-20 12:00:00');
    mesoscaleDiscussionCreate(['received_at' => '2026-08-20 10:00:00', 'text' => mesoscaleDiscussionText($valid)]);

    expect((new GetActiveMesoscaleDiscussions)()[0]['expires'])->toBe('2026-08-20T13:00:00+00:00');

    $this->travelTo('2026-08-20 13:00:01');

    expect((new GetActiveMesoscaleDiscussions)())->toBe([]);
})->with([
    'no VALID line' => ['CONCERNING...HEAVY SNOW'],
    'impossible times' => ['VALID 209999Z - 219999Z'],
    'impossible day' => ['VALID 202355Z - 450200Z'],
]);

test('a discussion is hidden when it is not current, live or drawable', function (array $attributes) {
    $this->travelTo('2026-08-20 23:58:00');
    $original = mesoscaleDiscussionCreate(['received_at' => '2026-08-20 20:00:00', 'text' => mesoscaleDiscussionText('VALID 202000Z - 202300Z')]);
    $attributes = array_map(fn (mixed $value): mixed => match ($value) {
        'original' => $original->id,
        'another product' => Product::query()->where('code', 'WPC.MPD')->value('id'),
        default => $value,
    }, $attributes);
    mesoscaleDiscussionCreate(['received_at' => '2026-08-20 23:55:00', ...$attributes]);

    expect((new GetActiveMesoscaleDiscussions)())->toBe([]);
})->with([
    'test product' => [['is_test' => true]],
    'replay copy' => [['replay_of_id' => 'original']],
    'no polygon' => [['polygon' => null]],
    'too few points' => [['polygon' => [['lat' => 33.0, 'lon' => -114.0], ['lat' => 34.0, 'lon' => -112.0]]]],
    'non-numeric point' => [['polygon' => [['lat' => 'x', 'lon' => -114.0], ['lat' => 34.0, 'lon' => -112.0], ['lat' => 32.0, 'lon' => -111.0]]]],
    'older than 12 hours' => [['received_at' => '2026-08-20 11:50:00', 'text' => mesoscaleDiscussionText('VALID 201150Z - 210100Z')]],
    'another product' => [['product_id' => 'another product']],
]);

test('number and areas are null when the text has neither, and long areas are cut to 160 characters', function () {
    $this->travelTo('2026-08-20 23:58:00');
    mesoscaleDiscussionCreate(['text' => mesoscaleDiscussionText(number: null, areas: null)]);
    mesoscaleDiscussionCreate(['text' => mesoscaleDiscussionText(areas: '  '.str_repeat('ABCDEFGHIJ', 20).'  ')]);

    $discussions = collect((new GetActiveMesoscaleDiscussions)())->keyBy(fn (array $discussion): string => $discussion['areas'] === null ? 'bare' : 'long');

    expect($discussions['bare']['number'])->toBeNull()
        ->and($discussions['long']['areas'])->toBe(substr(str_repeat('ABCDEFGHIJ', 20), 0, 160));
});

test('the newest discussion comes first', function () {
    $this->travelTo('2026-08-20 23:58:00');
    $older = mesoscaleDiscussionCreate(['received_at' => '2026-08-20 23:00:00']);
    $newer = mesoscaleDiscussionCreate(['received_at' => '2026-08-20 23:50:00']);

    expect(array_column((new GetActiveMesoscaleDiscussions)(), 'id'))->toBe([$newer->id, $older->id]);
});
