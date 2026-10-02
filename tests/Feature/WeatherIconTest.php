<?php

use App\Support\WeatherIcon;

test('it parses the period and code out of an NWS icon url', function () {
    expect(WeatherIcon::fromUrl('https://api.weather.gov/icons/land/day/skc?size=medium'))
        ->toBe(['period' => 'day', 'code' => 'skc'])
        ->and(WeatherIcon::fromUrl('https://api.weather.gov/icons/land/night/few?size=small'))
        ->toBe(['period' => 'night', 'code' => 'few']);
});

test('a probability suffix and a dual-condition url keep only the first code', function () {
    expect(WeatherIcon::fromUrl('https://api.weather.gov/icons/land/night/tsra_hi,40/rain,20?size=small'))
        ->toBe(['period' => 'night', 'code' => 'tsra_hi'])
        ->and(WeatherIcon::fromUrl('https://api.weather.gov/icons/land/day/rain_showers,30?size=medium'))
        ->toBe(['period' => 'day', 'code' => 'rain_showers']);
});

test('an unparseable or missing url falls back to the generic icon', function () {
    expect(WeatherIcon::fromUrl(null))->toBe(['period' => 'day', 'code' => 'na'])
        ->and(WeatherIcon::fromUrl('not a url'))->toBe(['period' => 'day', 'code' => 'na'])
        ->and(WeatherIcon::fromUrl('https://api.weather.gov/icons/marine/day/skc'))->toBe(['period' => 'day', 'code' => 'na']);
});

test('url() returns the absolute local file url when the icon exists', function () {
    config(['app.url' => 'https://alerts.example.test']);

    expect(WeatherIcon::url('day', 'skc'))->toBe('https://alerts.example.test/images/weather/day/skc.png')
        ->and(WeatherIcon::url('night', 'few'))->toBe('https://alerts.example.test/images/weather/night/few.png');
});

test('url() falls back to the generic icon for a missing file or an unsafe code', function () {
    config(['app.url' => 'https://alerts.example.test']);
    $fallback = 'https://alerts.example.test/images/weather/na.png';

    expect(WeatherIcon::url('day', 'definitely_not_a_code'))->toBe($fallback)
        ->and(WeatherIcon::url('day', '../logo'))->toBe($fallback)
        ->and(WeatherIcon::url('sideways', 'skc'))->toBe('https://alerts.example.test/images/weather/day/skc.png');
});

test('the shipped icon set covers the common sky conditions for both day and night', function () {
    foreach (['skc', 'few', 'sct', 'bkn', 'ovc', 'rain', 'snow', 'fog'] as $code) {
        expect(public_path("images/weather/day/{$code}.png"))->toBeFile()
            ->and(public_path("images/weather/night/{$code}.png"))->toBeFile();
    }
});
