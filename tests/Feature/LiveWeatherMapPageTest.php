<?php

use App\Filament\Dashboard\Pages\LiveWeather\Map;
use App\Models\ActiveWwa;
use App\Models\ActiveWwaArea;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Support\LiveWarningKinds;
use App\Support\NexradSites;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Renderless;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
});

test('a signed-in subscriber can open the Map page', function () {
    $this->actingAs(User::factory()->create());

    livewire(Map::class)->assertSuccessful();

    expect(Map::getSlug())->toBe('live-weather/map')
        ->and(Map::getUrl())->toEndWith('/live-weather/map');
});

test('the page sits in the Live Weather group as Map', function () {
    $this->actingAs(User::factory()->create());

    $group = collect(Filament::getCurrentPanel()->getNavigation())
        ->first(fn ($group) => $group->getLabel() === 'Live Weather');

    expect($group)->not->toBeNull()
        ->and(Map::getNavigationLabel())->toBe('Map')
        ->and(Map::getNavigationGroup())->toBe('Live Weather')
        ->and(Map::getNavigationSort())->toBe(1)
        ->and($group->getItems()->map->getLabel()->all())->toContain('Map');
});

test('a guest is redirected to the panel login', function () {
    $this->get(Map::getUrl())->assertRedirect(Filament::getCurrentPanel()->getLoginUrl());
});

test('the map opens on the whole United States when the viewer has no located place', function () {
    $user = User::factory()->create();
    Location::factory()->for($user)->create(['lat' => null, 'lng' => null]);
    $this->actingAs($user);

    livewire(Map::class)
        ->assertSeeHtml('data-center="39.83,-98.58"')
        ->assertSeeHtml('data-zoom="4"')
        ->assertSeeHtml('data-config=');
});

test('the map centres on the first located saved place', function () {
    $user = User::factory()->create();
    Location::factory()->for($user)->create(['lat' => null, 'lng' => null]);
    Location::factory()->for($user)->create(['lat' => 35.5, 'lng' => -97.25]);
    Location::factory()->for($user)->create(['lat' => 41.0, 'lng' => -90.0]);
    $this->actingAs($user);

    livewire(Map::class)
        ->assertSeeHtml('data-center="35.5,-97.25"')
        ->assertSeeHtml('data-zoom="8"');
});

test('another subscriber\'s place never centres the map', function () {
    Location::factory()->create(['lat' => 35.5, 'lng' => -97.25]);
    $this->actingAs(User::factory()->create());

    livewire(Map::class)->assertSeeHtml('data-center="39.83,-98.58"');
});

function liveWeatherMapCapabilities(string $layer): string
{
    return '<?xml version="1.0" encoding="UTF-8"?><WMS_Capabilities version="1.3.0" xmlns="http://www.opengis.net/wms"><Capability><Layer><Title>root</Title>'
        ."<Layer><Name>{$layer}</Name>"
        .'<Dimension name="time" units="ISO8601">2026-11-06T00:10:06.000Z,2026-11-06T00:04:06.000Z</Dimension></Layer>'
        .'</Layer></Capability></WMS_Capabilities>';
}

test('the radar frames method gives the frames of a known site layer', function () {
    Cache::flush();
    Http::fake(['opengeo.ncep.noaa.gov/geoserver/kfws/ows*' => Http::response(liveWeatherMapCapabilities('kfws_sr_bvel'))]);
    $this->actingAs(User::factory()->create());

    $result = livewire(Map::class)->instance()->radarFrames('site_velocity', 'KFWS');

    expect($result)->toBe([
        'layer' => 'site_velocity',
        'status' => 'ok',
        'wms_url' => 'https://opengeo.ncep.noaa.gov/geoserver/kfws/ows',
        'wms_layer' => 'kfws_sr_bvel',
        'times' => ['2026-11-06T00:04:06.000Z', '2026-11-06T00:10:06.000Z'],
    ]);
});

test('the radar frames method is unavailable for a bad layer or an unknown site, without asking NOAA', function (string $layer, ?string $site) {
    Http::fake(['opengeo.ncep.noaa.gov/*' => Http::response(liveWeatherMapCapabilities('kfws_sr_bref'))]);
    $this->actingAs(User::factory()->create());

    $result = livewire(Map::class)->instance()->radarFrames($layer, $site);

    expect($result)->toMatchArray(['status' => 'unavailable', 'wms_url' => null, 'wms_layer' => null, 'times' => []]);
    Http::assertNothingSent();
})->with([
    'unknown layer' => ['echo_tops', 'kfws'],
    'unknown site' => ['site_reflectivity', 'kzzz'],
    'path in site' => ['site_reflectivity', '../conus'],
    'site layer with no site' => ['site_velocity', null],
]);

test('the radar frames method is reachable from the browser without re-rendering the page', function () {
    $method = new ReflectionMethod(Map::class, 'radarFrames');

    expect($method->getAttributes(Renderless::class))->toHaveCount(1)
        ->and((new ReflectionMethod(Map::class, 'nearestRadarSite'))->getAttributes(Renderless::class))->toHaveCount(1);
});

test('the nearest radar site is found for a point', function () {
    $this->actingAs(User::factory()->create());

    $component = livewire(Map::class)->instance();

    expect($component->nearestRadarSite(32.57, -97.30))->toBe('kfws')
        ->and($component->nearestRadarSite(45.45, -98.41))->toBe('kabr');
});

test('the radar sites are listed by name with only an id and a name', function () {
    $this->actingAs(User::factory()->create());

    $sites = livewire(Map::class)->instance()->radarSites();
    $names = array_column($sites, 'name');
    $sorted = $names;
    natcasesort($sorted);

    expect($sites)->toHaveCount(count(NexradSites::all()))
        ->and($sites[0])->toBe(['id' => 'kabr', 'name' => 'Aberdeen'])
        ->and(array_map(fn (array $site): array => array_keys($site), $sites))->each->toBe(['id', 'name'])
        ->and($names)->toBe(array_values($sorted));
});

test('the radar starts on the site nearest the first located saved place', function () {
    $user = User::factory()->create();
    Location::factory()->for($user)->create(['lat' => null, 'lng' => null]);
    Location::factory()->for($user)->create(['lat' => 32.6, 'lng' => -97.3]);
    Location::factory()->for($user)->create(['lat' => 45.45, 'lng' => -98.41]);
    $this->actingAs($user);

    livewire(Map::class)
        ->assertSeeHtml('&quot;default_site&quot;:&quot;kfws&quot;')
        ->assertSeeHtml('&quot;layers&quot;:[&quot;composite&quot;,&quot;site_reflectivity&quot;,&quot;site_velocity&quot;]');
});

test('the radar has no default site for a viewer with no located place', function () {
    $user = User::factory()->create();
    Location::factory()->for($user)->create(['lat' => null, 'lng' => null]);
    $this->actingAs($user);

    livewire(Map::class)->assertSeeHtml('&quot;default_site&quot;:null');
});

test('the radar labels frames in the subscriber\'s profile timezone', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'America/Chicago']));

    livewire(Map::class)->assertSeeHtml('&quot;timezone&quot;:&quot;America\/Chicago&quot;');
});

test('the radar labels frames in the app timezone for a subscriber with no profile timezone', function () {
    config(['app.timezone' => 'America/New_York']);
    $this->actingAs(User::factory()->create(['timezone' => null]));

    livewire(Map::class)->assertSeeHtml('&quot;timezone&quot;:&quot;America\/New_York&quot;');
});

test('the page renders the radar controls', function () {
    $this->actingAs(User::factory()->create());

    livewire(Map::class)
        ->assertSeeHtmlInOrder(['data-live-weather-page', 'data-live-weather-map', 'data-radar-controls'])
        ->assertSeeHtml('data-radar-play')
        ->assertDontSeeHtml('aria-pressed')
        ->assertSeeHtml('data-radar-step="-1"')
        ->assertSeeHtml('data-radar-step="1"')
        ->assertSeeHtml('data-radar-scrubber')
        ->assertSeeHtml('data-radar-banner')
        ->assertSeeHtml('role="status"')
        ->assertSeeHtml('data-radar-legend')
        ->assertSeeHtmlInOrder(['data-radar-layer', '<option value="composite">Reflectivity (national)</option>', '<option value="site_reflectivity">Reflectivity (site)</option>', '<option value="site_velocity">Velocity (site)</option>'])
        ->assertSeeHtmlInOrder(['data-radar-site', 'Nearest to the map centre', '<option value="kabr">Aberdeen (KABR)</option>'])
        ->assertSeeHtmlInOrder(['data-radar-speed', 'value="slow"', 'value="normal" selected', 'value="fast"']);
});

test('the frame-count picker offers exactly 6, 12, 24 and 36 with 12 chosen', function () {
    $this->actingAs(User::factory()->create());

    $html = livewire(Map::class)->html();
    preg_match('/<select[^>]*data-radar-frames[^>]*>(.*?)<\/select>/s', $html, $select);
    preg_match_all('/<option value="(\d+)"( selected)?>/', $select[1] ?? '', $options, PREG_SET_ORDER);

    expect(array_map(fn (array $option): string => $option[1], $options))->toBe(['6', '12', '24', '36'])
        ->and(array_values(array_filter($options, fn (array $option): bool => ($option[2] ?? '') !== ''))[0][1])->toBe('12');
});

test('rendering the page asks NOAA for nothing', function () {
    Http::fake(['opengeo.ncep.noaa.gov/*' => Http::response(liveWeatherMapCapabilities('conus_bref_qcd'))]);
    $user = User::factory()->create();
    Location::factory()->for($user)->create(['lat' => 32.6, 'lng' => -97.3]);
    $this->actingAs($user);

    livewire(Map::class)->assertSeeHtml('data-radar-controls');

    Http::assertNothingSent();
});

function liveWeatherMapTornadoWarning(): ActiveWwa
{
    $wwa = ActiveWwa::factory()->create(['product_id' => null, 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W', 'status' => 'Active', 'is_test' => false]);
    ActiveWwaArea::factory()->for($wwa)->withPolygon('POLYGON((-105.5 39.5, -105.5 40.5, -104.5 40.5, -104.5 39.5, -105.5 39.5))')->create(['expires' => now()->addHour()]);

    return $wwa;
}

test('the live warnings method gives the warnings in the box', function () {
    $wwa = liveWeatherMapTornadoWarning();
    $this->actingAs(User::factory()->create());

    $result = livewire(Map::class)->instance()->liveWarnings(['south' => 38, 'west' => -107, 'north' => 42, 'east' => -101], LiveWarningKinds::SHORT_FUSE);

    expect($result['status'])->toBe('ok')
        ->and($result['truncated'])->toBeFalse()
        ->and(collect($result['events'])->pluck('id')->all())->toBe([$wwa->id]);
});

test('the live warnings method refuses an unknown kind or a malformed box without querying', function (array $bounds, string $kind) {
    liveWeatherMapTornadoWarning();
    $this->actingAs(User::factory()->create());
    $component = livewire(Map::class)->instance();
    DB::flushQueryLog();
    DB::enableQueryLog();

    $result = $component->liveWarnings($bounds, $kind);

    expect($result)->toBe(['status' => 'invalid', 'events' => [], 'truncated' => false])
        ->and(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'active_wwa'))->all())->toBe([]);
})->with([
    'unknown kind' => [['south' => 38, 'west' => -107, 'north' => 42, 'east' => -101], 'advisories'],
    'missing edge' => [['south' => 38, 'west' => -107, 'north' => 42], LiveWarningKinds::SHORT_FUSE],
    'text edge' => [['south' => 38, 'west' => 'x', 'north' => 42, 'east' => -101], LiveWarningKinds::SHORT_FUSE],
    'extra key' => [['south' => 38, 'west' => -107, 'north' => 42, 'east' => -101, 'zoom' => 5], LiveWarningKinds::SHORT_FUSE],
]);

test('the live warnings method is reachable from the browser without re-rendering the page', function () {
    expect((new ReflectionMethod(Map::class, 'liveWarnings'))->getAttributes(Renderless::class))->toHaveCount(1);
});

test('the warnings config carries the kind, its event names and the subscriber\'s timezone', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'America/Chicago']));

    expect(livewire(Map::class)->instance()->getWarningsConfig())->toBe([
        'kind' => 'short_fuse',
        'events' => ['Tornado Warning', 'Severe Thunderstorm Warning', 'Flash Flood Warning', 'Special Marine Warning', 'Extreme Wind Warning', 'Snow Squall Warning', 'Dust Storm Warning'],
        'timezone' => 'America/Chicago',
    ]);
});

test('the page renders the warnings panel beside the map and reads no warnings while rendering', function () {
    liveWeatherMapTornadoWarning();
    $this->actingAs(User::factory()->create());
    DB::flushQueryLog();
    DB::enableQueryLog();

    livewire(Map::class)
        ->assertSeeHtmlInOrder(['data-live-weather-map', 'data-warnings-panel', 'data-radar-controls'])
        ->assertSeeHtmlInOrder(['data-warnings-chip', 'value="Tornado Warning"', 'value="Dust Storm Warning"'])
        ->assertSeeHtml('data-warnings-list')
        ->assertSeeHtml('data-warnings-chips')
        ->assertSeeHtml('data-warnings-notice')
        ->assertSeeHtml('&quot;warnings&quot;:{&quot;kind&quot;:&quot;short_fuse&quot;');

    expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'active_wwa'))->all())->toBe([]);
});

function liveWeatherMapOutlookPolygon(): array
{
    return ['type' => 'MultiPolygon', 'coordinates' => [[[[-97.5, 35.5], [-96.5, 35.5], [-96.5, 36.5], [-97.5, 35.5]]]]];
}

/**
 * @param  list<string>  $labels
 */
function liveWeatherMapOutlookFile(array $labels): string
{
    return json_encode([
        'type' => 'FeatureCollection',
        'features' => array_map(fn (string $label): array => [
            'type' => 'Feature',
            'geometry' => liveWeatherMapOutlookPolygon(),
            'properties' => ['LABEL' => $label, 'ISSUE_ISO' => '2026-11-05T12:56:00Z'],
        ], $labels),
    ]);
}

test('an outlook day gives its categorical areas with their colours and issue time', function () {
    Cache::flush();
    Http::fake([
        'www.spc.noaa.gov/products/outlook/day2otlk_cat.nolyr.geojson' => Http::response(liveWeatherMapOutlookFile(['TSTM', 'SLGT', 'SIGN'])),
        'www.spc.noaa.gov/*' => Http::response('', 404),
    ]);
    $this->actingAs(User::factory()->create());

    expect(livewire(Map::class)->instance()->spcOutlook(2))->toBe([
        'available' => true,
        'issued_at' => '2026-11-05T12:56:00Z',
        'features' => [
            ['label' => 'TSTM', 'color' => '#c1e9c1', 'geometry' => liveWeatherMapOutlookPolygon()],
            ['label' => 'SLGT', 'color' => '#f6f67b', 'geometry' => liveWeatherMapOutlookPolygon()],
            ['label' => 'SIGN', 'color' => '#6b7280', 'geometry' => liveWeatherMapOutlookPolygon()],
        ],
    ]);
});

test('an outlook day with no areas is a quiet day, not unavailable', function () {
    Cache::flush();
    Http::fake([
        'www.spc.noaa.gov/products/outlook/day1otlk_cat.nolyr.geojson' => Http::response(liveWeatherMapOutlookFile([])),
        'www.spc.noaa.gov/*' => Http::response('', 404),
    ]);
    $this->actingAs(User::factory()->create());

    expect(livewire(Map::class)->instance()->spcOutlook(1))->toBe(['available' => true, 'issued_at' => null, 'features' => []]);
});

test('an outlook day whose categorical file failed is unavailable, never a quiet day', function (array $files) {
    Cache::flush();
    Http::fake([...array_map(fn (string $body) => Http::response($body), $files), 'www.spc.noaa.gov/*' => Http::response('', 404)]);
    $this->actingAs(User::factory()->create());

    expect(livewire(Map::class)->instance()->spcOutlook(1))->toBe(['available' => false, 'issued_at' => null, 'features' => []]);
})->with([
    'every file failed' => [[]],
    'only the tornado layer came' => [['www.spc.noaa.gov/products/outlook/day1otlk_torn.nolyr.geojson' => liveWeatherMapOutlookFile(['0.05'])]],
    'an HTML error page' => [['www.spc.noaa.gov/products/outlook/day1otlk_cat.nolyr.geojson' => '<html>busy</html>']],
]);

test('an outlook day outside 1 to 3, or not a whole number, is unavailable without asking SPC', function (mixed $day) {
    Http::fake(['www.spc.noaa.gov/*' => Http::response(liveWeatherMapOutlookFile(['TSTM']))]);
    $this->actingAs(User::factory()->create());

    expect(livewire(Map::class)->instance()->spcOutlook($day))->toBe(['available' => false, 'issued_at' => null, 'features' => []]);
    Http::assertNothingSent();
})->with([0, 4, -1, '1; drop', 'abc', 1.5, null, [[1]]]);

test('an outlook day is asked of SPC once per cache period, available or not, and stays unavailable from the cache', function () {
    Cache::flush();
    Http::fake([
        'www.spc.noaa.gov/products/outlook/day1otlk_cat.nolyr.geojson' => Http::response(liveWeatherMapOutlookFile(['SLGT'])),
        'www.spc.noaa.gov/*' => Http::response('', 404),
    ]);
    $this->actingAs(User::factory()->create());
    $page = livewire(Map::class)->instance();

    $dayOne = [$page->spcOutlook(1), $page->spcOutlook(1)];
    Http::assertSentCount(15);

    $dayTwo = [$page->spcOutlook(2), $page->spcOutlook(2)];
    Http::assertSentCount(30);

    expect(array_column($dayOne, 'available'))->toBe([true, true])
        ->and(array_column($dayOne[1]['features'], 'label'))->toBe(['SLGT'])
        ->and($dayTwo[1])->toBe(['available' => false, 'issued_at' => null, 'features' => []]);
});

test('an unavailable outlook day is asked again after a minute, an available one after two', function () {
    Cache::flush();
    Http::fake([
        'www.spc.noaa.gov/products/outlook/day1otlk_cat.nolyr.geojson' => Http::response(liveWeatherMapOutlookFile(['SLGT'])),
        'www.spc.noaa.gov/*' => Http::response('', 404),
    ]);
    $this->actingAs(User::factory()->create());
    $page = livewire(Map::class)->instance();
    $page->spcOutlook(1);
    $page->spcOutlook(2);

    $this->travel(61)->seconds();
    $page->spcOutlook(1);
    $page->spcOutlook(2);
    Http::assertSentCount(45);

    $this->travel(60)->seconds();
    $page->spcOutlook(1);
    Http::assertSentCount(60);
});

test('the mesoscale discussions method gives the current discussions', function () {
    $this->travelTo('2026-08-20 23:58:00');
    $md = IngestedProduct::factory()->createQuietly([
        'product_id' => Product::query()->where('code', 'SPC.MD')->value('id'),
        'text' => "MESOSCALE DISCUSSION 1572\n\nVALID 202355Z - 210200Z",
        'polygon' => [['lat' => 33.31, 'lon' => -114.29], ['lat' => 34.5, 'lon' => -112.0], ['lat' => 32.0, 'lon' => -111.5]],
        'received_at' => '2026-08-20 23:55:00',
    ]);
    $this->actingAs(User::factory()->create());

    $result = livewire(Map::class)->instance()->mesoscaleDiscussions();

    expect($result['status'])->toBe('ok')
        ->and(array_column($result['discussions'], 'id'))->toBe([$md->id]);
});

test('the SPC layer methods are reachable from the browser without re-rendering the page', function (string $method) {
    expect((new ReflectionMethod(Map::class, $method))->getAttributes(Renderless::class))->toHaveCount(1);
})->with(['spcOutlook', 'mesoscaleDiscussions']);

test('the layers config carries the watches kind, the outlook days and the subscriber\'s timezone', function () {
    $this->actingAs(User::factory()->create(['timezone' => 'America/Chicago']));

    expect(livewire(Map::class)->instance()->getLayersConfig())->toBe([
        'watches_kind' => 'watches',
        'outlook_days' => [1, 2, 3],
        'timezone' => 'America/Chicago',
    ]);
});

test('the page renders the SPC layer control, all off, and asks SPC for nothing while rendering', function () {
    Http::fake(['www.spc.noaa.gov/*' => Http::response(liveWeatherMapOutlookFile(['TSTM']))]);
    $this->actingAs(User::factory()->create());

    $html = livewire(Map::class)
        ->assertSeeHtmlInOrder(['data-live-weather-map', 'data-warnings-panel', 'data-spc-section', 'data-layers-control', 'data-radar-controls'])
        ->assertSeeHtmlInOrder(['data-layer-toggle="watches"', 'data-layer-toggle="outlook"', 'data-layer-toggle="discussions"'])
        ->assertSeeHtmlInOrder(['data-outlook-day', '<option value="1">Day 1</option>', '<option value="2">Day 2</option>', '<option value="3">Day 3</option>'])
        ->assertSeeHtmlInOrder(['data-outlook-legend', '#c1e9c1', 'General thunder', '#ff7cff', 'High'])
        ->assertSeeHtml('data-layers-notice')
        ->assertSeeHtml('&quot;layers&quot;:{&quot;watches_kind&quot;:&quot;watches&quot;')
        ->html();

    preg_match_all('/<input[^>]*data-layer-toggle[^>]*>/', $html, $toggles);

    expect($toggles[0])->toHaveCount(3)
        ->and(collect($toggles[0])->filter(fn (string $toggle): bool => str_contains($toggle, 'checked'))->all())->toBe([]);
    Http::assertNothingSent();
});

test('the page controls have accessible names, grouped layer toggles and status regions', function () {
    $this->actingAs(User::factory()->create());

    $html = livewire(Map::class)->html();

    expect($html)
        ->toContain('aria-label="Previous frame"')
        ->toContain('aria-label="Next frame"')
        ->toContain('type="range"')
        ->toContain('aria-label="Radar frame time"')
        ->toContain('aria-label="Current warnings"')
        ->toContain('aria-controls="live-map-warnings-body"')
        ->toContain('aria-expanded="false"')
        ->toContain('<legend class="sr-only">SPC layers</legend>')
        ->toContain('<legend class="sr-only">Show these warnings</legend>')
        ->and(substr_count($html, '<fieldset'))->toBe(2)
        ->and(substr_count($html, 'role="status"'))->toBeGreaterThanOrEqual(3)
        ->and($html)->not->toContain('aria-live');
});

function liveWeatherMapExhaust(User $user, int $calls = 120): void
{
    for ($i = 0; $i < $calls; $i++) {
        RateLimiter::hit('live-map-data:'.$user->id, 60);
    }
}

test('the data methods are refused without any work once a viewer has used 120 calls in a minute', function () {
    liveWeatherMapTornadoWarning();
    Http::fake();
    $user = User::factory()->create();
    $this->actingAs($user);
    $component = livewire(Map::class)->instance();
    liveWeatherMapExhaust($user);
    DB::flushQueryLog();
    DB::enableQueryLog();

    $warnings = $component->liveWarnings(['south' => 38, 'west' => -107, 'north' => 42, 'east' => -101], LiveWarningKinds::SHORT_FUSE);
    $frames = $component->radarFrames('composite');
    $site = $component->nearestRadarSite(32.57, -97.30);

    expect($warnings)->toBe(['status' => 'invalid', 'events' => [], 'truncated' => false])
        ->and($frames)->toBe(['layer' => 'composite', 'status' => 'unavailable', 'wms_url' => null, 'wms_layer' => null, 'times' => []])
        ->and($site)->toBeNull()
        ->and(DB::getQueryLog())->toBe([]);
    Http::assertNothingSent();
});

test('the limit is shared by the three methods, per user, and resets after a minute', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($user);
    $component = livewire(Map::class)->instance();

    foreach (range(1, 40) as $i) {
        $component->nearestRadarSite(32.57, -97.30);
        $component->liveWarnings(['south' => 38, 'west' => -107, 'north' => 42, 'east' => -101], LiveWarningKinds::SHORT_FUSE);
        $component->radarFrames('echo_tops');
    }

    expect($component->nearestRadarSite(32.57, -97.30))->toBeNull();

    $this->actingAs($other);
    expect(livewire(Map::class)->instance()->nearestRadarSite(32.57, -97.30))->toBe('kfws');

    $this->actingAs($user);
    $this->travel(61)->seconds();
    expect($component->nearestRadarSite(32.57, -97.30))->toBe('kfws');
});

test('normal-rate calls are not limited', function () {
    $this->actingAs(User::factory()->create());
    $component = livewire(Map::class)->instance();

    foreach (range(1, 120) as $i) {
        $last = $component->nearestRadarSite(32.57, -97.30);
    }

    expect($last)->toBe('kfws');
});

test('the page controls have the accessible names the viewer hears', function () {
    $this->actingAs(User::factory()->create());

    $html = livewire(Map::class)->html();
    $labelled = fn (string $marker, string $name): string => '#<label[^>]*>\s*<span[^>]*>'.$name.'</span>\s*<select '.$marker.'[ >]#';

    expect($html)
        ->toMatch($labelled('data-radar-layer', 'Radar'))
        ->toMatch($labelled('data-radar-site', 'Radar site'))
        ->toMatch($labelled('data-radar-frames', 'Frames'))
        ->toMatch($labelled('data-radar-speed', 'Speed'))
        ->toMatch($labelled('data-outlook-day', 'Outlook'))
        ->toMatch('#<label[^>]*>\s*<input type="checkbox" data-layer-toggle="outlook"[^>]*>\s*<span>Convective outlook</span>#')
        ->toMatch('#<button[^>]*data-radar-play[^>]*>Play</button>#')
        ->toMatch('#<button[^>]*data-warnings-toggle[^>]*aria-expanded="false"[^>]*>#');
});
