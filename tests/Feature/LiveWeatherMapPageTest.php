<?php

use App\Filament\Dashboard\Pages\LiveWeather\Map;
use App\Models\ActiveWwa;
use App\Models\ActiveWwaArea;
use App\Models\Location;
use App\Models\User;
use App\Support\LiveWarningKinds;
use App\Support\NexradSites;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
