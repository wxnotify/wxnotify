<?php

use App\Enums\ProductCategory;
use App\Models\IngestedProduct;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->freezeTime();

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('iPhone', ['app'])->plainTextToken;

    $boundary = DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)");

    foreach ([['MOB', 'Mobile, AL'], ['HFO', 'Honolulu, HI'], ['SJU', 'San Juan, PR'], ['PPG', 'Pago Pago, AS'], ['OKX', 'New York, NY']] as [$cwa, $citystate]) {
        DB::table('cwa_boundaries')->insert(['wfo' => $cwa, 'cwa' => $cwa, 'citystate' => $citystate, 'lon' => -72.5, 'lat' => 40.9, 'boundary' => $boundary, 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->discussion = Product::factory()->create(['name' => 'Area Forecast Discussion', 'category' => ProductCategory::OtherForecastsOutlooksDiscussionsAndReports]);

    $this->make = fn (array $overrides = []) => IngestedProduct::factory()->create([
        'cccc' => 'kmob', 'ttaaii' => 'fxus64', 'awipsid' => 'afdmob', 'wmo_ddhhmm' => '111355',
        'received_at' => now()->subHour(), 'product_id' => $this->discussion->id,
        ...$overrides,
    ]);

    $this->search = fn (array $query = []) => $this->withToken($this->token)->getJson('/api/v1/products?'.http_build_query($query));
});

test('it needs an app token', function () {
    $this->getJson('/api/v1/products')->assertUnauthorized();

    $beacon = $this->user->createToken('beacon', ['location'])->plainTextToken;
    $this->withToken($beacon)->getJson('/api/v1/products')->assertForbidden();
});

test('with no filters every product is listed newest first, in the documented shape', function () {
    $older = ($this->make)(['received_at' => now()->subHours(3)]);
    $newer = ($this->make)(['received_at' => now()->subHour(), 'awipsid' => 'afdhfo', 'cccc' => 'phfo']);

    $response = ($this->search)()->assertOk();

    $response->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id)
        ->assertJsonPath('data.0.title', 'Area Forecast Discussion')
        ->assertJsonPath('data.0.office', 'PHFO')
        ->assertJsonPath('data.0.product_id', $this->discussion->id)
        ->assertJsonPath('data.0.type_label', 'Other Forecasts, Outlooks, Discussions, and Reports')
        ->assertJsonPath('data.0.source', 'nwws')
        ->assertJsonPath('data.0.view_url', $newer->viewUrl())
        ->assertJsonPath('meta.next_cursor', null);

    expect($response->json('data.0.issued_at'))->toBeString();
});

test('the response never carries the bulletin text or other columns', function () {
    ($this->make)(['text' => 'SECRET-BULLETIN-BODY', 'ugc_codes' => ['ALC097'], 'polygon' => [[1, 2]]]);

    $response = ($this->search)()->assertOk();

    expect(array_keys($response->json('data.0')))->toBe(['id', 'title', 'office', 'issued_at', 'source', 'product_id', 'type_label', 'view_url'])
        ->and($response->getContent())->not->toContain('SECRET-BULLETIN-BODY')->not->toContain('ALC097');
});

test('a product with no catalog entry and no view slug still lists with a fallback title and no link', function () {
    $bare = ($this->make)(['product_id' => null, 'awipsid' => null, 'ttaaii' => null]);
    IngestedProduct::query()->whereKey($bare->id)->update(['view_slug' => null]);

    ($this->search)()->assertOk()
        ->assertJsonPath('data.0.title', 'Weather product')
        ->assertJsonPath('data.0.type_label', null)
        ->assertJsonPath('data.0.view_url', null);
});

test('the title falls back to the AWIPS ID, then the WMO header code', function () {
    ($this->make)(['product_id' => null, 'awipsid' => 'afdmob', 'received_at' => now()->subMinutes(10)]);
    ($this->make)(['product_id' => null, 'awipsid' => null, 'ttaaii' => 'fxus64', 'received_at' => now()->subMinutes(20)]);

    ($this->search)()->assertOk()
        ->assertJsonPath('data.0.title', 'AFDMOB')
        ->assertJsonPath('data.1.title', 'FXUS64');
});

test('the search text matches a catalog name, an AWIPS ID prefix or a WMO header prefix, in any case', function () {
    $byName = ($this->make)(['awipsid' => 'zzzzzz', 'ttaaii' => 'aaaaaa']);
    $warning = Product::factory()->create(['name' => 'Tornado Warning']);
    $byAwips = ($this->make)(['product_id' => $warning->id, 'awipsid' => 'tormob', 'ttaaii' => 'wfus54']);
    $byHeader = ($this->make)(['product_id' => $warning->id, 'awipsid' => 'pnsmob', 'ttaaii' => 'noxx99', 'cccc' => 'kbmx']);

    $ids = fn (string $q) => collect(($this->search)(['q' => $q])->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('forecast disc'))->toBe([$byName->id])
        ->and($ids('FORECAST DISC'))->toBe([$byName->id])
        ->and($ids('TORM'))->toBe([$byAwips->id])
        ->and($ids('tormob'))->toBe([$byAwips->id])
        ->and($ids('NOXX99 KB'))->toBe([$byHeader->id])
        ->and($ids('nothing matches this'))->toBe([]);
});

test('percent and underscore in the search text are literal', function () {
    ($this->make)(['awipsid' => 'afdmob']);

    expect(($this->search)(['q' => '%%'])->assertOk()->json('data'))->toBe([])
        ->and(($this->search)(['q' => '_f'])->assertOk()->json('data'))->toBe([])
        ->and(($this->search)(['q' => 'a_d'])->assertOk()->json('data'))->toBe([]);
});

test('the search text needs 2 to 100 characters', function () {
    ($this->search)(['q' => 'a'])->assertUnprocessable()->assertJsonValidationErrors('q');
    ($this->search)(['q' => str_repeat('a', 101)])->assertUnprocessable()->assertJsonValidationErrors('q');
    ($this->search)(['q' => 'ab'])->assertOk();
});

test('the type filter returns only that catalog product', function () {
    $warning = Product::factory()->create(['name' => 'Tornado Warning']);
    ($this->make)();
    $tornado = ($this->make)(['product_id' => $warning->id]);

    $ids = collect(($this->search)(['type' => $warning->id])->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$tornado->id]);
});

test('the type filter on a 3-character category code also returns the unclassified rows of that AWIPS category', function () {
    $taf = Product::factory()->create(['code' => 'TAF', 'name' => 'Terminal Aerodrome Forecast']);
    $mobile = ($this->make)(['product_id' => null, 'awipsid' => 'tafmob', 'received_at' => now()->subMinutes(10)]);
    $other = ($this->make)(['product_id' => null, 'awipsid' => 'tafkxx', 'received_at' => now()->subMinutes(20)]);
    $classified = ($this->make)(['product_id' => $taf->id, 'awipsid' => 'tafhfo', 'received_at' => now()->subMinutes(30)]);
    ($this->make)(['product_id' => null, 'awipsid' => 'rwrmob']);
    ($this->make)(['product_id' => null, 'awipsid' => null]);
    ($this->make)(['product_id' => $this->discussion->id, 'awipsid' => 'tafoth']);

    $ids = collect(($this->search)(['type' => $taf->id])->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$mobile->id, $other->id, $classified->id]);
});

test('a catalog name match on a 3-character category code also returns the unclassified rows of that AWIPS category', function () {
    Product::factory()->create(['code' => 'TAF', 'name' => 'Terminal Aerodrome Forecast']);
    $mobile = ($this->make)(['product_id' => null, 'awipsid' => 'tafmob', 'received_at' => now()->subMinutes(10)]);
    $other = ($this->make)(['product_id' => null, 'awipsid' => 'tafkxx', 'received_at' => now()->subMinutes(20)]);
    ($this->make)(['product_id' => null, 'awipsid' => 'rwrmob']);

    $ids = fn (string $q) => collect(($this->search)(['q' => $q])->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('terminal aero'))->toBe([$mobile->id, $other->id])
        ->and($ids('TERMINAL AERO'))->toBe([$mobile->id, $other->id]);
});

test('a VTEC-style catalog code matches by product id only', function () {
    $tornado = Product::factory()->create(['code' => 'ZZ.TOW', 'name' => 'Tornado Warning']);
    $classified = ($this->make)(['product_id' => $tornado->id, 'awipsid' => 'tormob', 'received_at' => now()->subMinutes(10)]);
    ($this->make)(['product_id' => null, 'awipsid' => 'zz.mob']);
    ($this->make)(['product_id' => null, 'awipsid' => 'zzt000']);
    ($this->make)(['product_id' => null, 'awipsid' => 'towmob']);

    $byType = collect(($this->search)(['type' => $tornado->id])->assertOk()->json('data'))->pluck('id')->all();
    $byName = collect(($this->search)(['q' => 'tornado warn'])->assertOk()->json('data'))->pluck('id')->all();

    expect($byType)->toBe([$classified->id])
        ->and($byName)->toBe([$classified->id]);
});

test('a classified row that matches both ways is returned once', function () {
    $taf = Product::factory()->create(['code' => 'TAF', 'name' => 'Terminal Aerodrome Forecast']);
    $row = ($this->make)(['product_id' => $taf->id, 'awipsid' => 'tafmob']);

    expect(collect(($this->search)(['q' => 'taf'])->assertOk()->json('data'))->pluck('id')->all())->toBe([$row->id])
        ->and(collect(($this->search)(['q' => 'terminal aero'])->assertOk()->json('data'))->pluck('id')->all())->toBe([$row->id])
        ->and(collect(($this->search)(['type' => $taf->id])->assertOk()->json('data'))->pluck('id')->all())->toBe([$row->id]);
});

test('wildcards in a catalog code never widen the category match', function () {
    $wildcard = Product::factory()->create(['code' => 'T%F', 'name' => 'Percent Code Product']);
    $underscore = Product::factory()->create(['code' => 'T_F', 'name' => 'Underscore Code Product']);
    ($this->make)(['product_id' => null, 'awipsid' => 'tafmob']);
    ($this->make)(['product_id' => null, 'awipsid' => 'tffmob']);

    expect(($this->search)(['type' => $wildcard->id])->assertOk()->json('data'))->toBe([])
        ->and(($this->search)(['type' => $underscore->id])->assertOk()->json('data'))->toBe([])
        ->and(($this->search)(['q' => 'percent code'])->assertOk()->json('data'))->toBe([])
        ->and(($this->search)(['q' => 'underscore code'])->assertOk()->json('data'))->toBe([]);
});

test('spaces typed in the search text still find an AWIPS ID', function () {
    $discussion = ($this->make)(['awipsid' => 'afdmob']);
    ($this->make)(['awipsid' => 'afdhfo', 'cccc' => 'phfo']);

    $ids = fn (string $q) => collect(($this->search)(['q' => $q])->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('AFD MOB'))->toBe([$discussion->id])
        ->and($ids('afd  mob'))->toBe([$discussion->id])
        ->and(count($ids('AFD')))->toBe(2);
});

test('the office filter matches the issuing centre by its K or P prefix and the San Juan and Pago Pago exceptions', function () {
    $mobile = ($this->make)(['cccc' => 'kmob']);
    $honolulu = ($this->make)(['cccc' => 'phfo']);
    $sanJuan = ($this->make)(['cccc' => 'tjsj']);
    $pagoPago = ($this->make)(['cccc' => 'nstu']);
    ($this->make)(['cccc' => 'kokx']);

    $ids = fn (string $wfo) => collect(($this->search)(['wfo' => $wfo])->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('MOB'))->toBe([$mobile->id])
        ->and($ids('hfo'))->toBe([$honolulu->id])
        ->and($ids('SJU'))->toBe([$sanJuan->id])
        ->and($ids('PPG'))->toBe([$pagoPago->id]);
});

test('an unknown office code is a validation error', function () {
    ($this->search)(['wfo' => 'ZZZ'])->assertUnprocessable()->assertJsonValidationErrors('wfo');
    ($this->search)(['wfo' => 'MOBILE'])->assertUnprocessable()->assertJsonValidationErrors('wfo');
});

test('each time period keeps only products received within it', function () {
    ($this->make)(['received_at' => now()->subMinutes(30)]);
    ($this->make)(['received_at' => now()->subHours(3)]);
    ($this->make)(['received_at' => now()->subDays(3)]);
    ($this->make)(['received_at' => now()->subDays(20)]);
    ($this->make)(['received_at' => now()->subDays(40)]);

    $count = fn (string $period) => count(($this->search)(['period' => $period])->assertOk()->json('data'));

    expect($count('hour'))->toBe(1)
        ->and($count('day'))->toBe(2)
        ->and($count('week'))->toBe(3)
        ->and($count('month'))->toBe(4)
        ->and(count(($this->search)()->json('data')))->toBe(5);

    ($this->search)(['period' => 'year'])->assertUnprocessable()->assertJsonValidationErrors('period');
});

test('filters combine with AND', function () {
    $warning = Product::factory()->create(['name' => 'Tornado Warning']);
    $match = ($this->make)(['product_id' => $warning->id, 'cccc' => 'kmob', 'received_at' => now()->subMinutes(5)]);
    ($this->make)(['product_id' => $warning->id, 'cccc' => 'phfo', 'received_at' => now()->subMinutes(5)]);
    ($this->make)(['product_id' => $this->discussion->id, 'cccc' => 'kmob', 'received_at' => now()->subMinutes(5)]);
    ($this->make)(['product_id' => $warning->id, 'cccc' => 'kmob', 'received_at' => now()->subDays(2)]);

    $ids = collect(($this->search)(['type' => $warning->id, 'wfo' => 'MOB', 'period' => 'day'])->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$match->id]);
});

test('pages follow the cursor without a duplicate or a gap, even when received times tie', function () {
    $tied = now()->subHour();
    $all = collect(range(1, 5))->map(fn () => ($this->make)(['received_at' => $tied]))->reverse()->pluck('id')->all();

    $first = ($this->search)(['limit' => 2])->assertOk();
    $second = ($this->search)(['limit' => 2, 'before' => $first->json('meta.next_cursor')])->assertOk();
    $third = ($this->search)(['limit' => 2, 'before' => $second->json('meta.next_cursor')])->assertOk();

    expect(collect([$first, $second, $third])->flatMap(fn ($page) => collect($page->json('data'))->pluck('id'))->all())->toBe($all)
        ->and($first->json('meta.next_cursor'))->not->toBeNull()
        ->and($third->json('meta.next_cursor'))->toBeNull();
});

test('a malformed cursor or an out-of-range limit is a validation error', function () {
    ($this->search)(['before' => 'not-a-cursor'])->assertUnprocessable()->assertJsonValidationErrors('before');
    ($this->search)(['limit' => 51])->assertUnprocessable()->assertJsonValidationErrors('limit');
    ($this->search)(['limit' => 0])->assertUnprocessable()->assertJsonValidationErrors('limit');
});

test('test products are listed outside production and hidden in production', function () {
    $test = ($this->make)(['is_test' => true]);
    $real = ($this->make)(['received_at' => now()->subMinutes(5)]);

    expect(collect(($this->search)()->json('data'))->pluck('id')->all())->toBe([$real->id, $test->id]);

    app()->instance('env', 'production');

    expect(collect(($this->search)()->json('data'))->pluck('id')->all())->toBe([$real->id]);
});

test('the route is rate limited like the rest of the app API', function () {
    ($this->search)()->assertOk()->assertHeader('X-RateLimit-Limit');
});
