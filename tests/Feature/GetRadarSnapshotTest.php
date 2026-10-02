<?php

use App\Actions\Weather\GetRadarSnapshot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

const RADAR_GIF = "GIF89a\x01\x00\x01\x00\x00\x00\x00;";
const RADAR_PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x00IEND\xaeB`\x82";

test('a valid gif is returned with its bytes and mime type', function () {
    Http::fake(['radar.weather.gov/ridge/standard/KFTG_0.gif' => Http::response(RADAR_GIF, 200, ['Content-Type' => 'image/gif'])]);

    expect((new GetRadarSnapshot)('KFTG'))->toBe(['bytes' => RADAR_GIF, 'mime' => 'image/gif']);
});

test('a valid png is accepted too', function () {
    Http::fake(['radar.weather.gov/*' => Http::response(RADAR_PNG, 200, ['Content-Type' => 'image/png'])]);

    expect((new GetRadarSnapshot)('KFTG')['mime'])->toBe('image/png');
});

test('anything that is not a 200 valid image of an allowed type, within the size cap, is rejected', function (int $status, string $body, string $contentType) {
    Http::fake(['radar.weather.gov/*' => Http::response($body, $status, ['Content-Type' => $contentType])]);

    expect((new GetRadarSnapshot)('KFTG'))->toBeNull();
})->with([
    'not found' => [404, '<html>nope</html>', 'text/html'],
    'a redirect' => [302, '', 'text/html'],
    'html with a 200' => [200, '<html>hi</html>', 'text/html'],
    'a gif content type but html bytes' => [200, '<html>hi</html>', 'image/gif'],
    'a png content type but gif bytes' => [200, RADAR_GIF, 'image/png'],
    'an unsupported image type' => [200, "\xff\xd8\xff\xe0JFIF", 'image/jpeg'],
    'an empty body' => [200, '', 'image/gif'],
]);

test('a response over one megabyte is rejected', function () {
    Http::fake(['radar.weather.gov/*' => Http::response('GIF89a'.str_repeat("\0", 1_048_577), 200, ['Content-Type' => 'image/gif'])]);

    expect((new GetRadarSnapshot)('KFTG'))->toBeNull();
});

test('a site code that is not three or four uppercase letters/digits never makes a request', function (string $site) {
    Http::fake();

    expect((new GetRadarSnapshot)($site))->toBeNull();
    Http::assertNothingSent();
})->with(['../etc', 'kftg', 'KFTGX', 'KF', 'KF/TG', '', "KFTG\n"]);

test('the request goes to the fixed radar host and does not follow redirects', function () {
    Http::fake(['radar.weather.gov/*' => Http::response(RADAR_GIF, 200, ['Content-Type' => 'image/gif'])]);

    (new GetRadarSnapshot)('KFTG');

    Http::assertSent(fn ($request) => $request->url() === 'https://radar.weather.gov/ridge/standard/KFTG_0.gif');
});

test('a connection failure returns null', function () {
    Http::fake(['radar.weather.gov/*' => fn () => throw new ConnectionException('timeout')]);

    expect((new GetRadarSnapshot)('KFTG'))->toBeNull();
});

test('the image is cached per site so several emails share one fetch', function () {
    Http::fake(['radar.weather.gov/*' => Http::response(RADAR_GIF, 200, ['Content-Type' => 'image/gif'])]);

    (new GetRadarSnapshot)('KFTG');
    (new GetRadarSnapshot)('KFTG');

    Http::assertSentCount(1);
});
