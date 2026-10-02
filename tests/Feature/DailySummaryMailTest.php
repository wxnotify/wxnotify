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
        ->and($html)->toContain('Sunrise 6:57 AM · Sunset 6:40 PM')
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
        ->assertSeeInText('Today (Fri, Oct 2): Sunny — High 79°F')
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
