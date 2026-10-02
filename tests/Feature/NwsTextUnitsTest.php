<?php

use App\Support\NwsTextUnits;

test('winds and temperatures in a typical NWS forecast convert', function () {
    expect(NwsTextUnits::toMetric('Sunny, with a high near 79. East southeast wind 2 to 6 mph.'))
        ->toBe('Sunny, with a high near 26°C. East southeast wind 3 to 10 km/h.')
        ->and(NwsTextUnits::toMetric('Mostly clear, with a low around 50. South southwest wind around 7 mph.'))
        ->toBe('Mostly clear, with a low around 10°C. South southwest wind around 11 km/h.')
        ->and(NwsTextUnits::toMetric('Sunny. High near 84, with temperatures falling to around 81 in the afternoon. South southeast wind 2 to 9 mph, with gusts as high as 20 mph.'))
        ->toBe('Sunny. High near 29°C, with temperatures falling to around 27°C in the afternoon. South southeast wind 3 to 14 km/h, with gusts as high as 32 km/h.');
});

test('heat index, wind chill and negative temperatures convert', function () {
    expect(NwsTextUnits::toMetric('Heat index values as high as 105.'))->toBe('Heat index values as high as 41°C.')
        ->and(NwsTextUnits::toMetric('Wind chill values as low as -10.'))->toBe('Wind chill values as low as -23°C.')
        ->and(NwsTextUnits::toMetric('Cloudy, with a low around 5.'))->toBe('Cloudy, with a low around -15°C.');
});

test('snow depths convert to centimetres', function () {
    expect(NwsTextUnits::toMetric('New snow accumulation of 1 to 3 inches possible.'))->toBe('New snow accumulation of 2.5 to 7.6 cm possible.')
        ->and(NwsTextUnits::toMetric('New snow accumulation of around 1 inch possible.'))->toBe('New snow accumulation of around 2.5 cm possible.');
});

test('a "between A and B inches" range converts both numbers', function () {
    expect(NwsTextUnits::toMetric('New rainfall amounts between 1 and 2 inches possible.'))
        ->toBe('New rainfall amounts between 2.5 and 5.1 cm possible.')
        ->and(NwsTextUnits::toMetric('New rainfall amounts between a tenth and quarter of an inch possible.'))
        ->toBe('New rainfall amounts between a tenth and quarter of an inch possible.');
});

test('a range the converter does not recognise is never half-converted', function () {
    foreach ([
        'Wind between 1 and 2 mph.',
        'Wind 5 and 10 mph.',
        'Snow of 1 and 2 inches possible.',
    ] as $text) {
        expect(NwsTextUnits::toMetric($text))->toBe($text);
    }

    expect(NwsTextUnits::toMetric('Gusting to 30 mph.'))->toBe('Gusting to 48 km/h.');
});

test('zero is a temperature', function () {
    expect(NwsTextUnits::toMetric('Cloudy, with a low around zero.'))->toBe('Cloudy, with a low around -18°C.')
        ->and(NwsTextUnits::toMetric('Temperatures near zero.'))->toBe('Temperatures near -18°C.')
        ->and(NwsTextUnits::toMetric('Wind chill values as low as zero.'))->toBe('Wind chill values as low as -18°C.')
        ->and(NwsTextUnits::toMetric('Clear, with a low around Zero.'))->toBe('Clear, with a low around -18°C.');
});

test('clock times and percentages are never touched', function () {
    $text = 'Showers and thunderstorms likely before 5pm. A chance of snow after 11am. Chance of precipitation is 40%.';

    expect(NwsTextUnits::toMetric($text))->toBe($text);
});

test('a sentence that cannot be fully converted stays exactly as NWS wrote it', function () {
    $mixed = 'Wind 10 mph, with rain amounts of a tenth of an inch.';

    expect(NwsTextUnits::toMetric($mixed))->toBe($mixed)
        ->and(NwsTextUnits::toMetric('Winds could gust as high as 25 mph. Rain amounts of a tenth of an inch possible.'))
        ->toBe('Winds could gust as high as 40 km/h. Rain amounts of a tenth of an inch possible.');
});

test('hourly wind strings convert', function () {
    expect(NwsTextUnits::toMetric('5 to 10 mph NW'))->toBe('8 to 16 km/h NW')
        ->and(NwsTextUnits::toMetric('5 mph NW'))->toBe('8 km/h NW');
});

test('text with nothing to convert, and empty text, come back unchanged', function () {
    expect(NwsTextUnits::toMetric('Cloudy.'))->toBe('Cloudy.')
        ->and(NwsTextUnits::toMetric(''))->toBe('');
});

test('conversion is idempotent', function () {
    foreach ([
        'Sunny, with a high near 79. East southeast wind 2 to 6 mph.',
        'Wind chill values as low as -10.',
        'New snow accumulation of 1 to 3 inches possible.',
        '5 to 10 mph NW',
        'New rainfall amounts between 1 and 2 inches possible.',
        'Cloudy, with a low around zero.',
        'Wind between 1 and 2 mph.',
    ] as $text) {
        $once = NwsTextUnits::toMetric($text);

        expect(NwsTextUnits::toMetric($once))->toBe($once);
    }
});

test('a corpus of real NWS forecasts converts cleanly', function () {
    $corpus = json_decode(file_get_contents(base_path('tests/Fixtures/nws_detailed_forecasts.json')), true);
    $changed = 0;
    $counts = ['mph' => 0, 'kmh' => 0, 'temps' => 0, 'celsius' => 0];

    expect($corpus)->not->toBeEmpty();

    foreach ($corpus as $original) {
        $converted = NwsTextUnits::toMetric($original);

        if ($converted !== $original) {
            $changed++;
        }

        // Every wind phrase and unit symbol is gone...
        expect($converted)->not->toMatch('/\bmph\b|°F/');
        // ...no bare temperature is left after a known lead-in (a number not followed by °)...
        expect($converted)->not->toMatch('/\b(high|low) (near|around) -?\d+(?![\d°])/i')
            ->and($converted)->not->toMatch('/\b(falling|rising) to (near|around) -?\d+(?![\d°])/i')
            ->and($converted)->not->toMatch('/values as (high|low) as -?\d+(?![\d°])/i');
        // ...while clock times and percentages are exactly as published...
        preg_match_all('/\d+\s?[ap]m\b/i', $original, $timesBefore);
        preg_match_all('/\d+\s?[ap]m\b/i', $converted, $timesAfter);
        preg_match_all('/\d+%/', $original, $percentBefore);
        preg_match_all('/\d+%/', $converted, $percentAfter);
        expect($timesAfter)->toBe($timesBefore)->and($percentAfter)->toBe($percentBefore);
        // ...and every original wind speed and lead-in temperature shows up converted.
        $counts['mph'] += preg_match_all('/\bmph\b/', $original);
        $counts['kmh'] += preg_match_all('/\bkm\/h\b/', $converted);
        $counts['temps'] += preg_match_all('/\b(?:high|low) (?:near|around) -?\d+/i', $original);
        $counts['celsius'] += preg_match_all('/\b(?:high|low) (?:near|around) -?\d+°C/i', $converted);
        // ...and converting twice changes nothing.
        expect(NwsTextUnits::toMetric($converted))->toBe($converted);
    }

    expect($changed)->toBeGreaterThan(50)
        // No sentence in the corpus is reverted for a wind speed (no mph survives above), so nothing is excluded.
        ->and($counts['mph'])->toBeGreaterThan(50)
        ->and($counts['kmh'])->toBe($counts['mph'])
        ->and($counts['temps'])->toBeGreaterThan(50)
        ->and($counts['celsius'])->toBe($counts['temps']);
});
