<?php

use App\Support\DailySummaryPresenter;
use Illuminate\Support\Carbon;

test('the header lines carry the station with coordinates and elevation, and the sun times', function () {
    $view = DailySummaryPresenter::present(presenterReport());

    expect($view['location'])->toBe('Home')
        ->and($view['station_line'])->toBe('Reporting station: Erie Municipal Airport (KEIK) — 40.0117, -105.0503 — elev. 5,085 ft')
        ->and($view['sun_line'])->toBe('Sunrise 6:57 AM · Sunset 6:40 PM');
});

test('current conditions rows are formatted for display', function () {
    config(['app.url' => 'https://alerts.example.test']);
    $current = DailySummaryPresenter::present(presenterReport())['current'];

    expect($current['description'])->toBe('Clear')
        ->and($current['icon_url'])->toBe('https://alerts.example.test/images/weather/day/skc.png')
        ->and($current['rows'])->toBe([
            ['Temperature', '54°F'],
            ['Feels like', '50°F'],
            ['Humidity', '77%'],
            ['Wind', 'NE 10 mph, gusting 20 mph'],
            ['Barometer', '30.27 inHg'],
            ['Dewpoint', '46°F'],
            ['Visibility', '10 mi'],
        ])
        ->and($current['updated'])->toBe('Last updated Fri, Oct 2 at 8:35 AM');
});

test('wind shows calm, omits direction and gust when absent, and missing readings are an em dash', function () {
    $report = presenterReport();
    $report['current']['wind'] = ['direction' => null, 'speed_mph' => 0.2, 'gust_mph' => null];
    $report['current']['humidity_pct'] = null;
    $report['current']['barometer_inhg'] = null;
    $report['current']['dewpoint_f'] = null;
    $report['current']['visibility_mi'] = null;

    $rows = collect(DailySummaryPresenter::present($report)['current']['rows'])->mapWithKeys(fn (array $row): array => [$row[0] => $row[1]]);

    expect($rows['Wind'])->toBe('Calm')
        ->and($rows['Humidity'])->toBe('—')
        ->and($rows['Barometer'])->toBe('—')
        ->and($rows['Dewpoint'])->toBe('—')
        ->and($rows['Visibility'])->toBe('—');

    $report['current']['wind'] = ['direction' => null, 'speed_mph' => 7.4, 'gust_mph' => null];
    $windRow = collect(DailySummaryPresenter::present($report)['current']['rows'])->firstWhere(0, 'Wind');
    expect($windRow[1])->toBe('7 mph');
});

test('the current section and station line are absent without current conditions, and the sun line without sun times', function () {
    $view = DailySummaryPresenter::present(presenterReport(['current' => null, 'sun' => null]));

    expect($view['current'])->toBeNull()
        ->and($view['station_line'])->toBeNull()
        ->and($view['sun_line'])->toBeNull();
});

test('alerts show the event, headline and a local expiry, and an open-ended alert has no until', function () {
    $view = DailySummaryPresenter::present(presenterReport([
        'alerts' => [
            ['event' => 'Wind Advisory', 'headline' => 'Wind Advisory issued', 'expires' => '2026-10-02T15:00:00-06:00', 'family' => 'advisory'],
            ['event' => 'Statement', 'headline' => null, 'expires' => null, 'family' => 'statement'],
        ],
    ]));

    expect($view['alerts'])->toBe([
        ['event' => 'Wind Advisory', 'headline' => 'Wind Advisory issued', 'until' => 'until Fri 3:00 PM'],
        ['event' => 'Statement', 'headline' => null, 'until' => null],
    ]);
});

test('hourly and 7-day rows are formatted, with a missing precip chance shown as an em dash', function () {
    config(['app.url' => 'https://alerts.example.test']);
    $report = presenterReport();
    $report['hourly'][] = ['time' => '2026-10-02T10:00:00-06:00', 'icon' => ['period' => 'day', 'code' => 'few'], 'temperature' => 55, 'unit' => 'F', 'precip_chance' => null, 'wind' => '', 'summary' => 'Sunny'];

    $view = DailySummaryPresenter::present($report);

    expect($view['hourly'][0])->toBe(['time' => '9 AM', 'icon_url' => 'https://alerts.example.test/images/weather/day/few.png', 'temp' => '54°F', 'precip' => '10%', 'wind' => '5 mph NW', 'summary' => 'Sunny'])
        ->and($view['hourly'][1]['precip'])->toBe('—')
        ->and($view['hourly'][1]['wind'])->toBe('—')
        ->and($view['periods'][0])->toBe(['icon_url' => 'https://alerts.example.test/images/weather/day/few.png', 'name' => 'Today', 'date' => 'Fri, Oct 2', 'summary' => 'Sunny, with a high near 79. East southeast wind 2 to 6 mph.', 'alt' => 'Sunny', 'temp' => 'High 79°F']);
});

test('radar alt and caption exist only when the report has a radar site', function () {
    Carbon::setTestNow('2026-10-02 15:00:00');

    $with = DailySummaryPresenter::present(presenterReport());
    $without = DailySummaryPresenter::present(presenterReport(['radar_site' => null]));

    expect($with['radar_alt'])->toBe('Radar near Home from KFTG')
        ->and($with['radar_caption'])->toBe('Radar as of Fri, Oct 2 at 9:00 AM')
        ->and($without['radar_alt'])->toBeNull()
        ->and($without['radar_caption'])->toBeNull();

    Carbon::setTestNow();
});

test('the plain text rendering carries every section and omits the radar line when there is no image', function () {
    Carbon::setTestNow('2026-10-02 15:00:00');
    $view = DailySummaryPresenter::present(presenterReport());

    $text = DailySummaryPresenter::text($view, hasRadar: false);

    expect($text)->toContain('Home')
        ->and($text)->toContain('Reporting station: Erie Municipal Airport (KEIK)')
        ->and($text)->toContain('Sunrise 6:57 AM · Sunset 6:40 PM')
        ->and($text)->toContain('ALERTS IN EFFECT')
        ->and($text)->toContain('Wind Advisory — Wind Advisory issued (until Fri 3:00 PM)')
        ->and($text)->toContain('CURRENT CONDITIONS')
        ->and($text)->toContain('Temperature: 54°F')
        ->and($text)->toContain('NEXT 12 HOURS')
        ->and($text)->toContain('9 AM — 54°F — 10% — 5 mph NW — Sunny')
        ->and($text)->toContain('7-DAY FORECAST')
        ->and($text)->toContain('Today (Fri, Oct 2): Sunny, with a high near 79. East southeast wind 2 to 6 mph. — High 79°F')
        ->and($text)->not->toContain('Radar');

    Carbon::setTestNow();
});

test('the plain text says there are no active alerts when the list is empty', function () {
    $text = DailySummaryPresenter::text(DailySummaryPresenter::present(presenterReport(['alerts' => []])), hasRadar: false);

    expect($text)->toContain("ALERTS IN EFFECT\nNo active alerts.");
});

test('a failed alert lookup passes through as null and the text says alerts are unavailable', function () {
    $view = DailySummaryPresenter::present(presenterReport(['alerts' => null]));
    $text = DailySummaryPresenter::text($view, hasRadar: false);

    expect($view['alerts'])->toBeNull()
        ->and($text)->toContain("ALERTS IN EFFECT\nAlert information is unavailable right now.")
        ->and($text)->not->toContain('No active alerts.');
});

test('a station with no name is shown by its id alone', function () {
    $report = presenterReport();
    $report['current']['station']['name'] = null;

    expect(DailySummaryPresenter::present($report)['station_line'])->toBe('Reporting station: KEIK — 40.0117, -105.0503 — elev. 5,085 ft');

    $report['current']['station']['name'] = '  ';
    expect(DailySummaryPresenter::present($report)['station_line'])->toStartWith('Reporting station: KEIK — ');
});

test('a forecast period with no date is named alone, with no empty parentheses or date span', function () {
    $report = presenterReport();
    $report['periods'][0]['date'] = null;

    $view = DailySummaryPresenter::present($report);
    $text = DailySummaryPresenter::text($view, hasRadar: false);
    $html = view('mail.partials.daily-summary', ['s' => $view, 'hasRadar' => false])->render();

    expect($view['periods'][0]['date'])->toBeNull()
        ->and($text)->toContain('Today: Sunny, with a high near 79. East southeast wind 2 to 6 mph. — High 79°F')
        ->and($text)->not->toContain('()')
        ->and($html)->not->toContain('<span style="color: #6b7280; font-size: 12px;"></span>')
        ->and($html)->toContain('<strong>Today</strong>');
});

test('a blank current conditions description leaves no empty heading, no blank text line and a sensible alt', function () {
    $report = presenterReport();
    $report['current']['description'] = '';

    $view = DailySummaryPresenter::present($report);
    $text = DailySummaryPresenter::text($view, hasRadar: false);
    $html = view('mail.partials.daily-summary', ['s' => $view, 'hasRadar' => false])->render();

    expect($view['current']['description'])->toBeNull()
        ->and($text)->toContain("CURRENT CONDITIONS\nTemperature: 54°F")
        ->and($html)->not->toContain('font-weight: 600;"></div>')
        ->and($html)->toContain('alt="Current conditions"');
});

test('a report queued before the short forecast key existed still renders, using the summary as the icon alt text', function () {
    $report = presenterReport();
    unset($report['periods'][0]['short']);

    $period = DailySummaryPresenter::present($report)['periods'][0];

    expect($period['alt'])->toBe($period['summary']);
});

test('the sun times are exposed separately for the header, and absent without sun data', function () {
    $with = DailySummaryPresenter::present(presenterReport());
    $without = DailySummaryPresenter::present(presenterReport(['sun' => null]));

    expect($with['sun'])->toBe(['sunrise' => '6:57 AM', 'sunset' => '6:40 PM'])
        ->and($without['sun'])->toBeNull();
});

test('the plain text puts the sun line right after the location, before the station line', function () {
    $text = DailySummaryPresenter::text(DailySummaryPresenter::present(presenterReport()), hasRadar: false);

    expect(strpos($text, 'Sunrise 6:57 AM'))->toBeLessThan(strpos($text, 'Reporting station'))
        ->and(strpos($text, 'Home'))->toBeLessThan(strpos($text, 'Sunrise'));
});
