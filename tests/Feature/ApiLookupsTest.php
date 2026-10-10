<?php

use App\Models\User;
use App\Rules\KnownUgcCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('Pixel 9', ['app'])->plainTextToken;

    $boundary = DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)");

    DB::table('cwa_boundaries')->insert([
        ['wfo' => 'OKX', 'cwa' => 'OKX', 'citystate' => 'New York, NY', 'lon' => -72.5, 'lat' => 40.9, 'boundary' => $boundary, 'created_at' => now(), 'updated_at' => now()],
        ['wfo' => 'ABQ', 'cwa' => 'ABQ', 'citystate' => 'Albuquerque, NM', 'lon' => -72.5, 'lat' => 40.9, 'boundary' => $boundary, 'created_at' => now(), 'updated_at' => now()],
    ]);

    foreach ([['NY', '36103', 'Suffolk'], ['NY', '36059', 'Nassau'], ['NJ', '34003', 'Bergen']] as [$state, $fips, $name]) {
        DB::table('counties')->insert([
            'state' => $state, 'cwa' => 'OKX', 'countyname' => $name, 'fips' => $fips, 'lon' => -72.5, 'lat' => 40.9,
            'boundary' => $boundary, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    foreach ([['NY', '072', 'Suffolk'], ['NY', '078', 'Southern Nassau'], ['NJ', '104', 'Eastern Bergen']] as [$state, $zone, $name]) {
        DB::table('public_zones')->insert([
            'state' => $state, 'cwa' => 'OKX', 'zone' => $zone, 'name' => $name, 'state_zone' => $state.$zone,
            'lon' => -72.5, 'lat' => 40.9, 'boundary' => $boundary, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
});

function lookup($test, string $uri)
{
    app('auth')->forgetGuards();

    return $test->withToken($test->token)->getJson($uri);
}

test('the offices come back with their codes and labels', function () {
    $response = lookup($this, '/api/v1/nws-offices')->assertOk();

    expect($response->json('data'))->toBe([
        ['code' => 'ABQ', 'label' => 'ABQ — Albuquerque, NM'],
        ['code' => 'OKX', 'label' => 'OKX — New York, NY'],
    ]);
});

test('zones are searched by state and text and carry the UGC codes the rule form stores', function () {
    $byState = lookup($this, '/api/v1/zones?state=NY')->assertOk();
    $byText = lookup($this, '/api/v1/zones?q=bergen')->assertOk();

    expect(collect($byState->json('data'))->pluck('code')->all())->toBe(['NYC059', 'NYC103', 'NYZ072', 'NYZ078'])
        ->and($byState->json('data.1'))->toBe(['code' => 'NYC103', 'label' => 'NYC103 — Suffolk County, NY'])
        ->and($byState->json('data.2'))->toBe(['code' => 'NYZ072', 'label' => 'NYZ072 — Suffolk, NY'])
        ->and(collect($byText->json('data'))->pluck('code')->all())->toBe(['NJC003', 'NJZ104']);
});

test('zones page by the last code seen and never return the whole table', function () {
    $first = lookup($this, '/api/v1/zones?state=NY&limit=2')->assertOk();
    $second = lookup($this, '/api/v1/zones?state=NY&limit=2&after=NYC103')->assertOk();

    expect(collect($first->json('data'))->pluck('code')->all())->toBe(['NYC059', 'NYC103'])
        ->and($first->json('meta.next_after'))->toBe('NYC103')
        ->and(collect($second->json('data'))->pluck('code')->all())->toBe(['NYZ072', 'NYZ078'])
        ->and($second->json('meta.next_after'))->toBeNull();

    lookup($this, '/api/v1/zones')->assertStatus(422);
    lookup($this, '/api/v1/zones?q=a')->assertStatus(422);
    lookup($this, '/api/v1/zones?state=NY&limit=500')->assertStatus(422);
});

test('a search treats like wildcards as plain text', function () {
    expect(lookup($this, '/api/v1/zones?q=%25%25')->assertOk()->json('data'))->toBe([]);
});

test('the known UGC code rule accepts real counties and zones and refuses everything else', function (string $code, bool $valid) {
    $validator = Validator::make(['code' => $code], ['code' => [new KnownUgcCode]]);

    expect($validator->passes())->toBe($valid);
})->with([
    'a county' => ['NYC103', true],
    'a zone' => ['NYZ072', true],
    'lower case is not a code' => ['nyc103', false],
    'an unknown county' => ['NYC999', false],
    'an unknown zone' => ['NYZ999', false],
    'a bad shape' => ['NY103', false],
    'a number' => ['12345', false],
]);

test('the lookups need an app token', function () {
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/nws-offices')->assertUnauthorized();
    $this->getJson('/api/v1/zones?state=NY')->assertUnauthorized();
});
