<?php

use App\Actions\Weather\GetRadarFrames;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const RADAR_FRAMES_TIMES = [
    '2026-11-06T00:04:06.000Z',
    '2026-11-06T00:06:06.000Z',
    '2026-11-06T00:08:06.000Z',
    '2026-11-06T00:10:06.000Z',
    '2026-11-06T00:12:06.000Z',
];

function radarFramesCapabilities(array $layers, array $times = RADAR_FRAMES_TIMES): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?><WMS_Capabilities version="1.3.0" xmlns="http://www.opengis.net/wms"><Capability><Layer><Title>root</Title>';

    foreach ($layers as $name) {
        $xml .= "<Layer><Name>{$name}</Name><Style><Name>style_{$name}</Name></Style>"
            .'<Dimension name="time" default="x" units="ISO8601">'.implode(',', $times).'</Dimension></Layer>';
    }

    return $xml.'</Layer></Capability></WMS_Capabilities>';
}

beforeEach(fn () => Cache::flush());

test('the composite layer gives its times oldest first with the configured url', function () {
    Http::fake(['opengeo.ncep.noaa.gov/*' => Http::response(radarFramesCapabilities(['other', 'conus_bref_qcd'], array_reverse(RADAR_FRAMES_TIMES)))]);

    $result = (new GetRadarFrames)('composite');

    expect($result)->toBe([
        'layer' => 'composite',
        'status' => 'ok',
        'wms_url' => config('wxnotify.map.radar.wms_url'),
        'wms_layer' => 'conus_bref_qcd',
        'times' => RADAR_FRAMES_TIMES,
    ]);
});

test('a site reflectivity layer reads its own dimension and not the first in the file', function () {
    Http::fake(['opengeo.ncep.noaa.gov/*' => Http::response(str_replace(
        '</Layer></Capability>',
        '<Layer><Name>kfws_sr_bref</Name><Dimension name="time">'.implode(',', RADAR_FRAMES_TIMES).'</Dimension></Layer></Layer></Capability>',
        radarFramesCapabilities(['kfws_bdhc'], ['2020-01-01T00:00:00.000Z'])
    ))]);

    $result = (new GetRadarFrames)('site_reflectivity', 'KFWS');

    expect($result['status'])->toBe('ok')
        ->and($result['wms_url'])->toBe('https://opengeo.ncep.noaa.gov/geoserver/kfws/ows')
        ->and($result['wms_layer'])->toBe('kfws_sr_bref')
        ->and($result['times'])->toBe(RADAR_FRAMES_TIMES);
});

test('a site velocity layer uses the bvel layer name', function () {
    Http::fake(['opengeo.ncep.noaa.gov/*' => Http::response(radarFramesCapabilities(['kfws_sr_bref', 'kfws_sr_bvel']))]);

    $result = (new GetRadarFrames)('site_velocity', 'kfws');

    expect($result['wms_layer'])->toBe('kfws_sr_bvel')
        ->and($result['wms_url'])->toBe('https://opengeo.ncep.noaa.gov/geoserver/kfws/ows')
        ->and($result['times'])->toBe(RADAR_FRAMES_TIMES);
});

test('the request is a WMS 1.3.0 GetCapabilities to the fixed host', function () {
    Http::fake(['opengeo.ncep.noaa.gov/*' => Http::response(radarFramesCapabilities(['kfws_sr_bref']))]);

    (new GetRadarFrames)('site_reflectivity', 'kfws');

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://opengeo.ncep.noaa.gov/geoserver/kfws/ows?')
        && str_contains($request->url(), 'request=GetCapabilities')
        && str_contains($request->url(), 'version=1.3.0'));
});

test('a second call within the cache window makes no request', function () {
    Http::fake(['opengeo.ncep.noaa.gov/*' => Http::response(radarFramesCapabilities(['conus_bref_qcd']))]);

    (new GetRadarFrames)('composite');
    (new GetRadarFrames)('composite');

    Http::assertSentCount(1);
});

test('a failure is cached for 30 seconds, then retried', function () {
    Http::fake(['opengeo.ncep.noaa.gov/*' => Http::response('', 500)]);

    (new GetRadarFrames)('composite');
    (new GetRadarFrames)('composite');
    Http::assertSentCount(1);

    $this->travel(31)->seconds();
    (new GetRadarFrames)('composite');
    Http::assertSentCount(2);
});

test('a success is cached for 60 seconds, then refreshed', function () {
    Http::fake(['opengeo.ncep.noaa.gov/*' => Http::response(radarFramesCapabilities(['conus_bref_qcd']))]);

    (new GetRadarFrames)('composite');
    $this->travel(45)->seconds();
    (new GetRadarFrames)('composite');
    Http::assertSentCount(1);

    $this->travel(20)->seconds();
    (new GetRadarFrames)('composite');
    Http::assertSentCount(2);
});

test('no more than 120 times are kept, the newest ones', function () {
    $many = [];
    for ($i = 0; $i < 150; $i++) {
        $many[] = gmdate('Y-m-d\TH:i:s.000\Z', 1_780_000_000 + $i * 120);
    }
    Http::fake(['opengeo.ncep.noaa.gov/*' => Http::response(radarFramesCapabilities(['conus_bref_qcd'], $many))]);

    $result = (new GetRadarFrames)('composite');

    expect($result['times'])->toHaveCount(120)->and($result['times'][119])->toBe($many[149]);
});

test('every failure mode is unavailable with no url and never throws', function (string $label, Closure $fake) {
    Http::fake(['opengeo.ncep.noaa.gov/*' => $fake]);

    expect((new GetRadarFrames)('composite'))->toBe([
        'layer' => 'composite',
        'status' => 'unavailable',
        'wms_url' => null,
        'wms_layer' => null,
        'times' => [],
    ]);
})->with([
    'server error' => ['500', fn () => Http::response('boom', 500)],
    'timeout' => ['timeout', fn () => throw new ConnectionException('timed out')],
    'html body' => ['html', fn () => Http::response('<html><body>Maintenance</body></html>')],
    'no time dimension' => ['no dimension', fn () => Http::response('<WMS_Capabilities xmlns="http://www.opengis.net/wms"><Capability><Layer><Name>conus_bref_qcd</Name></Layer></Capability></WMS_Capabilities>')],
    'empty time list' => ['empty', fn () => Http::response('<WMS_Capabilities xmlns="http://www.opengis.net/wms"><Capability><Layer><Name>conus_bref_qcd</Name><Dimension name="time"> </Dimension></Layer></Capability></WMS_Capabilities>')],
    'layer missing' => ['layer missing', fn () => Http::response(radarFramesCapabilities(['something_else']))],
    'redirect' => ['redirect', fn () => Http::response('', 302, ['Location' => 'https://evil.example/'])],
    'entity bomb' => ['entity', fn () => Http::response('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a SYSTEM "file:///etc/passwd">]><WMS_Capabilities xmlns="http://www.opengis.net/wms"><Capability><Layer><Name>conus_bref_qcd</Name><Dimension name="time">&a;</Dimension></Layer></Capability></WMS_Capabilities>')],
]);

test('an unknown layer, an unknown site or a missing site is refused before any request', function (string $layer, ?string $site) {
    Http::fake();

    $result = (new GetRadarFrames)($layer, $site);

    expect($result['status'])->toBe('unavailable')
        ->and($result['wms_url'])->toBeNull()
        ->and($result['times'])->toBe([]);
    Http::assertNothingSent();
})->with([
    'unknown layer' => ['satellite', null],
    'unknown site' => ['site_reflectivity', 'zzzz'],
    'injected site' => ['site_velocity', '../conus'],
    'site layer without a site' => ['site_reflectivity', null],
]);
