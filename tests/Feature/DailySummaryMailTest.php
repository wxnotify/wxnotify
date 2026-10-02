<?php

use App\Mail\DailySummaryMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 15:00:00');
    config(['app.name' => 'WxNotify Test', 'app.url' => 'https://alerts.example.test']);
});

afterEach(function () {
    Carbon::setTestNow();
});

const MAIL_GIF = "GIF89a\x01\x00\x01\x00\x00\x00\x00;";

test('the subject is the title, falling back to the app name', function () {
    expect((new DailySummaryMail('Daily summary for Home', presenterReport(), 'brief'))->envelope()->subject)->toBe('Daily summary for Home')
        ->and((new DailySummaryMail(null, presenterReport(), 'brief'))->envelope()->subject)->toBe('WxNotify Test');
});

test('the html renders every section in order under the branded header', function () {
    $html = (new DailySummaryMail('Title', presenterReport(), 'brief', ['bytes' => MAIL_GIF, 'mime' => 'image/gif']))->render();

    expect($html)->toContain('src="https://alerts.example.test/images/logo.png"')
        ->and($html)->toContain('Home')
        ->and($html)->toContain('Reporting station: Erie Municipal Airport (KEIK) — 40.0117, -105.0503 — elev. 5,085 ft')
        ->and($html)->toContain('Sunrise')->toContain('6:57 AM')->toContain('Sunset')->toContain('6:40 PM')
        ->and($html)->toContain('Wind Advisory')
        ->and($html)->toContain('until Fri 3:00 PM')
        ->and($html)->toContain('Feels like')
        ->and($html)->toContain('NE 10 mph, gusting 20 mph')
        ->and($html)->toContain('30.27 inHg')
        ->and($html)->toContain('Last updated Fri, Oct 2 at 8:35 AM')
        ->and($html)->toContain('cid:radar-snapshot')
        ->and($html)->toContain('Radar near Home from KFTG')
        ->and($html)->toContain('Today')
        ->and($html)->toContain('High 79°F')
        ->and($html)->toContain('/images/weather/day/few.png');

    $positions = array_map(fn (string $needle): int => strpos($html, $needle), [
        'Reporting station', 'Wind Advisory', 'Feels like', 'cid:radar-snapshot', '9 AM', 'High 79',
    ]);
    expect($positions)->toBe(collect($positions)->sort()->values()->all());
});

test('an alert row reads as one phrase once the html is collapsed', function () {
    $html = (new DailySummaryMail('Title', presenterReport(), 'brief'))->render();
    $visible = str_replace("\u{00A0}", ' ', html_entity_decode(strip_tags($html)));

    expect($visible)->toContain('Wind Advisory · until Fri 3:00 PM');
});

test('the radar image declares a width for clients that ignore max-width', function () {
    $html = (new DailySummaryMail('Title', presenterReport(), 'brief', ['bytes' => MAIL_GIF, 'mime' => 'image/gif']))->render();

    expect($html)->toMatch('/<img src="cid:radar-snapshot"[^>]*width="570"/');
});

test('a failed alert lookup says alerts are unavailable instead of claiming there are none', function () {
    $mail = new DailySummaryMail('Title', presenterReport(['alerts' => null]), 'brief');
    $html = $mail->render();

    expect($html)->toContain('Alerts in Effect')
        ->and($html)->toContain('Alert information is unavailable right now.')
        ->and($html)->not->toContain('No active alerts.');

    $mail->assertSeeInText("ALERTS IN EFFECT\nAlert information is unavailable right now.")
        ->assertDontSeeInText('No active alerts.');
});

test('the plain text alternative does not html-escape the app name', function () {
    config(['app.name' => 'Wx & Co']);

    (new DailySummaryMail('Title', presenterReport(), 'brief'))
        ->assertSeeInText('Wx & Co')
        ->assertDontSeeInText('&amp;');
});

test('a report with twelve hourly rows renders twelve hourly rows', function () {
    $report = presenterReport();
    $report['hourly'] = array_map(fn (int $hour): array => [
        'time' => sprintf('2026-10-02T%02d:00:00-06:00', $hour),
        'icon' => ['period' => 'day', 'code' => 'sct'],
        'temperature' => 50 + $hour, 'unit' => 'F', 'precip_chance' => 10, 'wind' => '5 mph NW', 'summary' => 'Partly Sunny',
    ], range(9, 20));

    $html = (new DailySummaryMail('Title', $report, 'brief'))->render();

    expect(substr_count($html, '/images/weather/day/sct.png'))->toBe(12)
        ->and($html)->toContain('9 AM')
        ->and($html)->toContain('8 PM');
});

test('the radar section is absent when no image was fetched', function () {
    $html = (new DailySummaryMail('Title', presenterReport(), 'brief'))->render();

    expect($html)->not->toContain('cid:radar-snapshot')
        ->and($html)->not->toContain('Radar near');
});

test('the alerts section shows an empty state, and current conditions disappear when absent', function () {
    $html = (new DailySummaryMail('Title', presenterReport(['alerts' => [], 'current' => null, 'hourly' => null]), 'brief'))->render();

    expect($html)->toContain('No active alerts.')
        ->and($html)->not->toContain('Feels like')
        ->and($html)->not->toContain('Reporting station')
        ->and($html)->not->toContain('Next 12 Hours');
});

test('values from the external api are escaped', function () {
    $report = presenterReport(['alerts' => [['event' => '<script>alert(1)</script>', 'headline' => 'a & b', 'expires' => null, 'family' => 'warning']]]);
    $report['current']['station']['name'] = '<b>Evil</b> Airport';

    $html = (new DailySummaryMail('Title', $report, 'brief'))->render();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->not->toContain('<b>Evil</b>')
        ->and($html)->toContain('&lt;b&gt;Evil&lt;/b&gt; Airport');
});

test('the plain text alternative carries the content without html and without the radar', function () {
    $mail = new DailySummaryMail('Title', presenterReport(), 'brief', ['bytes' => MAIL_GIF, 'mime' => 'image/gif']);

    $mail->assertSeeInText('WxNotify Test')
        ->assertSeeInText('ALERTS IN EFFECT')
        ->assertSeeInText('Today (Fri, Oct 2): Sunny, with a high near 79. East southeast wind 2 to 6 mph. — High 79°F')
        ->assertDontSeeInText('<table');
});

test('a sent message embeds the radar image inline', function () {
    Mail::to('subscriber@example.com')->send(new DailySummaryMail('Title', presenterReport(), 'brief', ['bytes' => MAIL_GIF, 'mime' => 'image/gif']));

    $email = Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();

    expect($email->getAttachments())->toHaveCount(1)
        ->and($email->getAttachments()[0]->getMediaType())->toBe('image')
        ->and($email->getHtmlBody())->toContain('cid:radar-snapshot');
});

test('no radar means no embedded attachment', function () {
    Mail::to('subscriber@example.com')->send(new DailySummaryMail('Title', presenterReport(), 'brief'));

    $email = Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();

    expect($email->getAttachments())->toHaveCount(0);
});

test('the sun times are a prominent header line with sunrise and sunset icons, above the station line', function () {
    $html = (new DailySummaryMail('Title', presenterReport(), 'brief'))->render();

    expect($html)->toContain('🌅')
        ->and($html)->toContain('🌇')
        ->and(strpos($html, '🌅'))->toBeLessThan(strpos($html, 'Reporting station'))
        ->and(strpos($html, 'Home'))->toBeLessThan(strpos($html, '🌅'));

    $visible = html_entity_decode(strip_tags($html));
    expect(str_replace("\u{a0}", ' ', $visible))->toContain('Sunrise 6:57 AM')->toContain('Sunset 6:40 PM');
});

test('there is no sun line or sun icon without sun data', function () {
    $html = (new DailySummaryMail('Title', presenterReport(['sun' => null]), 'brief'))->render();

    expect($html)->not->toContain('🌅')->not->toContain('Sunrise');
});

test('the 7-day rows show the detailed forecast text, while the icon alt text stays short', function () {
    $html = (new DailySummaryMail('Title', presenterReport(), 'brief'))->render();

    // alt="Sunny" appears twice: the one hourly row and the one 7-day row; the long text is never used as alt.
    expect($html)->toContain('East southeast wind 2 to 6 mph.')
        ->and(substr_count($html, 'alt="Sunny"'))->toBe(2)
        ->and($html)->not->toContain('alt="Sunny, with a high');
});

test('the 7-day icon cell and image have fixed widths so long forecast text cannot squeeze them', function () {
    $html = (new DailySummaryMail('Title', presenterReport(), 'brief'))->render();

    expect($html)->toMatch('/<td[^>]*width="50"[^>]*min-width: 50px[^>]*><img[^>]*width: 40px[^>]*min-width: 40px[^>]*height: 40px/');
});

test('a metric subscriber\'s email shows metric units and no imperial ones', function () {
    $html = (new DailySummaryMail('Title', presenterReport(['units' => 'metric']), 'brief'))->render();

    expect($html)->toContain('12°C')
        ->and($html)->toContain('1025 hPa')
        ->and($html)->toContain('16.1 km')
        ->and($html)->not->toContain('°F')
        ->and($html)->not->toContain('inHg')
        ->and($html)->not->toContain(' mph');
});
