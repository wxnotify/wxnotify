<?php

use App\Actions\Weather\GetSpcOutlookGraphic;
use App\Jobs\DeliverScheduledAlert;
use App\Mail\ChannelMessageMail;
use App\Mail\OutlookReportMail;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\NotificationChannel;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\Transports\MailTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

const ORM_PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\x00IEND\xaeB`\x82";
const ORM_GIF = "GIF89a\x01\x00\x01\x00\x00\x00\x00;";
const ORM_SUBJECT = 'Severe Wx Report - 🟡 D1 | 🟢 D2 | 🟤 D3 | ⚪ D4 | 🟡 D5 | ⚪ D6 | ⚪ D7 | ⚪ D8';
const ORM_BRIEF = 'D1 🟡  D2 🟢  D3 🟤  D4-8 🟡';

beforeEach(function () {
    config(['app.name' => 'WxNotify Test', 'app.url' => 'https://alerts.example.test']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ormPayload(array $overrides = []): array
{
    $quietDay = fn (): array => ['available' => true, 'probability' => null, 'category' => 'none', 'highlight' => false, 'locations' => []];

    return array_replace([
        'days' => [
            1 => [
                'available' => true, 'issued_at' => '2026-10-31T13:00:00+00:00', 'category' => 'slight',
                'hail' => '15%', 'wind' => '30%', 'tornado' => '5%', 'any_severe' => null,
                'locations' => [
                    ['label' => 'Home', 'category' => 'slight', 'hail' => '15%', 'wind' => '30%', 'tornado' => '2%'],
                    ['label' => 'Honolulu Condo', 'category' => 'none', 'hail' => null, 'wind' => null, 'tornado' => null],
                ],
            ],
            2 => [
                'available' => true, 'issued_at' => '2026-10-31T06:00:00+00:00', 'category' => 'marginal',
                'hail' => '5%', 'wind' => '5%', 'tornado' => null, 'any_severe' => null,
                'locations' => [
                    ['label' => 'Home', 'category' => 'marginal', 'hail' => '5%', 'wind' => null, 'tornado' => null],
                    ['label' => 'Honolulu Condo', 'category' => 'none', 'hail' => null, 'wind' => null, 'tornado' => null],
                ],
            ],
            3 => [
                'available' => true, 'issued_at' => '2026-10-31T07:30:00+00:00', 'category' => 'thunderstorm',
                'hail' => null, 'wind' => null, 'tornado' => null, 'any_severe' => '5%',
                'locations' => [
                    ['label' => 'Home', 'category' => 'thunderstorm', 'hail' => null, 'wind' => null, 'tornado' => null],
                    ['label' => 'Honolulu Condo', 'category' => 'none', 'hail' => null, 'wind' => null, 'tornado' => null],
                ],
            ],
        ],
        'extended' => [
            4 => $quietDay(),
            5 => ['available' => true, 'probability' => '15%', 'category' => 'slight', 'highlight' => true, 'locations' => ['Home']],
            6 => ['available' => false, 'probability' => null, 'category' => null, 'highlight' => false, 'locations' => []],
            7 => $quietDay(),
            8 => $quietDay(),
        ],
        'pwo' => null,
        'something_forecast' => true,
        'day1_missing' => false,
    ], $overrides);
}

/**
 * @return array<string, array{bytes: string, mime: string}>
 */
function ormImages(): array
{
    return [
        'day1' => ['bytes' => ORM_PNG, 'mime' => 'image/png'],
        'day2' => ['bytes' => ORM_PNG, 'mime' => 'image/png'],
        'day3' => ['bytes' => ORM_PNG, 'mime' => 'image/png'],
        'day48' => ['bytes' => ORM_GIF, 'mime' => 'image/gif'],
    ];
}

/**
 * The visible text of rendered html, every tag and run of whitespace read as one space.
 */
function ormVisible(string $html): string
{
    $text = str_replace("\u{00A0}", ' ', html_entity_decode(preg_replace('/<[^>]+>/', ' ', $html) ?? $html));

    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

/**
 * Fakes the four SPC graphics as valid images.
 */
function ormFakeGraphics(): void
{
    Http::fake([
        'www.spc.noaa.gov/products/outlook/*' => Http::response(ORM_PNG, 200, ['Content-Type' => 'image/png']),
        'www.spc.noaa.gov/products/exper/*' => Http::response(ORM_GIF, 200, ['Content-Type' => 'image/gif']),
    ]);
}

test('the subject is the passed-in title', function () {
    expect((new OutlookReportMail(ORM_SUBJECT, ormPayload(), ORM_BRIEF, []))->envelope()->subject)->toBe(ORM_SUBJECT);
});

test('each of days 1 to 3 shows its graphic, its category with its circle and its levels, in order', function () {
    $html = (new OutlookReportMail(ORM_SUBJECT, ormPayload(), ORM_BRIEF, ormImages()))->render();
    $visible = ormVisible($html);

    expect($html)->toContain('src="https://alerts.example.test/images/logo.png"')
        ->and($html)->toContain('<img src="cid:spc-day1"')
        ->and($html)->toContain('<img src="cid:spc-day2"')
        ->and($html)->toContain('<img src="cid:spc-day3"')
        ->and($visible)->toContain('Day 1 🟡 Slight')
        ->and($visible)->toContain('Hail 15% · Wind 30% · Tornado 5%')
        ->and($visible)->toContain('Day 2 🟢 Marginal')
        ->and($visible)->toContain('Hail 5% · Wind 5% · Tornado none')
        ->and($visible)->toContain('Day 3 🟤 General thunderstorms')
        ->and($visible)->toContain('Any severe 5%')
        ->and($visible)->toContain('Issued Sat, Oct 31 13:00 UTC');

    $positions = array_map(fn (string $needle): int => strpos($html, $needle), ['cid:spc-day1', 'cid:spc-day2', 'cid:spc-day3', 'cid:spc-day48']);
    expect($positions)->toBe(collect($positions)->sort()->values()->all());
});

test('every chosen location gets one compact row per day, and one outside every area shows none', function () {
    $visible = ormVisible((new OutlookReportMail(ORM_SUBJECT, ormPayload(), ORM_BRIEF, ormImages()))->render());

    expect($visible)->toContain('Home 🟡 Slight Hail 15% · Wind 30% · Tornado 2%')
        ->and($visible)->toContain('Honolulu Condo ⚪ None')
        ->and($visible)->toContain('Home 🟢 Marginal Hail 5% · Wind none · Tornado none')
        ->and($visible)->toContain('Home 🟤 General thunderstorms')
        ->and(substr_count($visible, 'Honolulu Condo'))->toBe(3);
});

test('days 4 to 8 list each day, highlight a 15% day with the locations inside, and show an unavailable day as unavailable', function () {
    $html = (new OutlookReportMail(ORM_SUBJECT, ormPayload(), ORM_BRIEF, ormImages()))->render();
    $visible = ormVisible($html);

    expect($html)->toContain('<img src="cid:spc-day48"')
        ->and($visible)->toContain('Days 4-8')
        ->and($visible)->toContain('Day 4 ⚪ Below 15%')
        ->and($visible)->toContain('Day 5 🟡 15% Inside: Home')
        ->and($visible)->toContain('Day 6 ❔ Not available')
        ->and($visible)->toContain('Day 8 ⚪ Below 15%')
        ->and($html)->toMatch('/<tr[^>]*background-color: #fef9c3[^>]*>(?:(?!<tr).)*Day 5/s');
});

test('a highlighted day with no chosen location inside says so', function () {
    $payload = ormPayload();
    $payload['extended'][5]['locations'] = [];

    expect(ormVisible((new OutlookReportMail(ORM_SUBJECT, $payload, ORM_BRIEF, []))->render()))
        ->toContain('Day 5 🟡 15% None of your locations inside');
});

test('the PWO section appears only when the payload has one, with its text escaped and preformatted', function () {
    $without = (new OutlookReportMail(ORM_SUBJECT, ormPayload(), ORM_BRIEF, []))->render();
    $with = (new OutlookReportMail(ORM_SUBJECT, ormPayload(['pwo' => [
        'title' => 'Public Severe Weather Outlook',
        'text' => "...TORNADO OUTBREAK <b>LIKELY</b> & DAMAGING WINDS...\n\nLINE TWO",
        'issued_at' => '2026-10-31T12:51:00+00:00',
    ]]), ORM_BRIEF, []))->render();

    expect($without)->not->toContain('Public Severe Weather Outlook')
        ->and($with)->toContain('Public Severe Weather Outlook')
        ->and($with)->toMatch('/<pre[^>]*>\.\.\.TORNADO OUTBREAK &lt;b&gt;LIKELY&lt;\/b&gt; &amp; DAMAGING WINDS\.\.\.\n\nLINE TWO<\/pre>/')
        ->and($with)->not->toContain('<p>LINE TWO')
        ->and($with)->not->toContain('<b>LIKELY</b>');
});

test('a missing Day 1 says it is not yet available instead of showing no storms', function () {
    $payload = ormPayload(['day1_missing' => true]);
    $payload['days'][1] = ['available' => false, 'issued_at' => null, 'category' => null, 'hail' => null, 'wind' => null, 'tornado' => null, 'any_severe' => null, 'locations' => []];

    $mail = new OutlookReportMail(ORM_SUBJECT, $payload, ORM_BRIEF, ormImages());
    $visible = ormVisible($mail->render());

    expect($visible)->toContain('Day 1 outlook not yet available')
        ->and($visible)->not->toContain('Day 1 ⚪')
        ->and($mail->render())->not->toContain('cid:spc-day1');
    $mail->assertSeeInText('Day 1 outlook not yet available');
});

test('an unavailable day 2 shows as unavailable with no graphic', function () {
    $payload = ormPayload();
    $payload['days'][2] = ['available' => false, 'issued_at' => null, 'category' => null, 'hail' => null, 'wind' => null, 'tornado' => null, 'any_severe' => null, 'locations' => []];

    $html = (new OutlookReportMail(ORM_SUBJECT, $payload, ORM_BRIEF, ormImages()))->render();

    expect(ormVisible($html))->toContain('Day 2 ❔ Day 2 outlook not available')
        ->and($html)->not->toContain('cid:spc-day2')
        ->and(ormVisible($html))->not->toContain('Day 2 ⚪');
});

test('the plain text alternative carries the same facts without html', function () {
    $mail = new OutlookReportMail(ORM_SUBJECT, ormPayload(['pwo' => ['title' => 'Public Severe Weather Outlook', 'text' => 'PWO <TEXT> & more', 'issued_at' => '2026-10-31T12:51:00+00:00']]), ORM_BRIEF, ormImages());

    $mail->assertSeeInText('WxNotify Test')
        ->assertSeeInText("DAY 1: 🟡 Slight\nHail 15% · Wind 30% · Tornado 5%")
        ->assertSeeInText('Home: 🟡 Slight — Hail 15% · Wind 30% · Tornado 2%')
        ->assertSeeInText('Honolulu Condo: ⚪ None')
        ->assertSeeInText("DAY 3: 🟤 General thunderstorms\nAny severe 5%")
        ->assertSeeInText('Day 5: 🟡 15% — Inside: Home')
        ->assertSeeInText('Day 6: ❔ Not available')
        ->assertSeeInText("PUBLIC SEVERE WEATHER OUTLOOK\nIssued Sat, Oct 31 12:51 UTC\nPWO <TEXT> & more")
        ->assertDontSeeInText('<table')
        ->assertDontSeeInText('&amp;');
});

test('values from the payload are escaped', function () {
    $payload = ormPayload();
    $payload['days'][1]['locations'][0]['label'] = '<script>alert(1)</script>';

    $html = (new OutlookReportMail(ORM_SUBJECT, $payload, ORM_BRIEF, []))->render();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
});

test('a sent message embeds each fetched graphic inline, and a missing one is neither embedded nor referenced', function () {
    $images = ormImages();
    $images['day2'] = null;

    Mail::to('subscriber@example.com')->send(new OutlookReportMail(ORM_SUBJECT, ormPayload(), ORM_BRIEF, $images));

    $email = Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();

    expect($email->getAttachments())->toHaveCount(3)
        ->and($email->getHtmlBody())->toContain('cid:spc-day1')
        ->and($email->getHtmlBody())->not->toContain('cid:spc-day2')
        ->and(ormVisible($email->getHtmlBody()))->toContain('Day 2 🟢 Marginal');
});

test('the graphic fetcher returns each fixed graphic with its bytes and mime type', function (string $key, string $url, string $bytes, string $mime) {
    Http::fake([$url => Http::response($bytes, 200, ['Content-Type' => $mime])]);

    expect((new GetSpcOutlookGraphic)($key))->toBe(['bytes' => $bytes, 'mime' => $mime]);
    Http::assertSent(fn ($request) => $request->url() === $url);
})->with([
    'day 1' => ['day1', 'https://www.spc.noaa.gov/products/outlook/day1otlk_sm.png', ORM_PNG, 'image/png'],
    'day 2' => ['day2', 'https://www.spc.noaa.gov/products/outlook/day2otlk_sm.png', ORM_PNG, 'image/png'],
    'day 3' => ['day3', 'https://www.spc.noaa.gov/products/outlook/day3otlk_sm.png', ORM_PNG, 'image/png'],
    'days 4-8' => ['day48', 'https://www.spc.noaa.gov/products/exper/day4-8/day48prob_small.gif', ORM_GIF, 'image/gif'],
]);

test('an unknown graphic key never makes a request', function (string $key) {
    Http::fake();

    expect((new GetSpcOutlookGraphic)($key))->toBeNull();
    Http::assertNothingSent();
})->with(['day4', 'DAY1', '../day1', 'day1 ', '']);

test('anything but a 200 valid image of an allowed type within one megabyte is rejected', function (int $status, string $body, string $contentType) {
    Http::fake(['www.spc.noaa.gov/*' => Http::response($body, $status, ['Content-Type' => $contentType, 'Location' => 'http://169.254.169.254/'])]);

    expect((new GetSpcOutlookGraphic)('day1'))->toBeNull();
})->with([
    'a redirect' => [302, '', 'text/html'],
    'not found' => [404, '<html>nope</html>', 'text/html'],
    'html with a 200' => [200, '<html>hi</html>', 'text/html'],
    'a png content type but html bytes' => [200, '<html>hi</html>', 'image/png'],
    'an empty body' => [200, '', 'image/png'],
    'an oversized body' => [200, ORM_PNG.str_repeat("\0", 1_048_577), 'image/png'],
]);

test('a connection failure returns null and a fetched graphic is cached', function () {
    Http::fake(['www.spc.noaa.gov/products/outlook/day1otlk_sm.png' => fn () => throw new ConnectionException('timeout')]);
    expect((new GetSpcOutlookGraphic)('day1'))->toBeNull();

    Http::fake(['www.spc.noaa.gov/products/outlook/day2otlk_sm.png' => Http::response(ORM_PNG, 200, ['Content-Type' => 'image/png'])]);
    (new GetSpcOutlookGraphic)('day2');
    expect((new GetSpcOutlookGraphic)('day2'))->toBe(['bytes' => ORM_PNG, 'mime' => 'image/png']);

    Http::assertSentCount(1);
});

test('an outlook_report payload in the meta sends the rich outlook mail with the graphics', function () {
    Mail::fake();
    $channel = NotificationChannel::factory()->email('subscriber@example.com')->create();

    $result = app(MailTransport::class)->send($channel, ORM_BRIEF, ORM_SUBJECT, [
        'outlook_report' => ormPayload(),
        'outlook_report_images' => ormImages(),
    ]);

    expect($result->success)->toBeTrue();
    Mail::assertSent(OutlookReportMail::class, fn (OutlookReportMail $mail) => $mail->hasTo('subscriber@example.com')
        && $mail->messageTitle === ORM_SUBJECT
        && $mail->briefText === ORM_BRIEF
        && $mail->images === ormImages());
    Mail::assertNotSent(ChannelMessageMail::class);
});

test('an outlook report that cannot be rendered falls back to the brief message and logs no exception message', function () {
    Mail::fake();
    Log::spy();
    $channel = NotificationChannel::factory()->email('subscriber@example.com')->create();
    $malformed = ormPayload();
    $malformed['days'][1] = 'secret-report-text';

    $result = app(MailTransport::class)->send($channel, ORM_BRIEF, ORM_SUBJECT, ['outlook_report' => $malformed, 'outlook_report_images' => []]);

    expect($result->success)->toBeTrue();
    Mail::assertSent(ChannelMessageMail::class, fn (ChannelMessageMail $mail) => $mail->messageTitle === ORM_SUBJECT && $mail->messageBody === ORM_BRIEF);
    Mail::assertNotSent(OutlookReportMail::class);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'Outlook report email could not be rendered, sending the brief instead'
        && $context === ['notification_channel_id' => $channel->id, 'exception' => TypeError::class])->once();
});

test('end to end, an email channel gets the rich mail with the four graphics fetched at send time', function () {
    Mail::fake();
    ormFakeGraphics();
    $rule = AlertRule::factory()->outlookReport()->create();
    $channel = NotificationChannel::factory()->for($rule->user)->email('subscriber@example.com')->create();

    (new DeliverScheduledAlert($rule->id, $channel->id, ORM_SUBJECT, ORM_BRIEF, ormPayload(), 'batch-1'))->handle(app(ChannelDispatcher::class));

    Mail::assertSent(OutlookReportMail::class, fn (OutlookReportMail $mail) => $mail->hasTo('subscriber@example.com')
        && $mail->messageTitle === ORM_SUBJECT
        && array_keys($mail->images) === ['day1', 'day2', 'day3', 'day48']
        && $mail->images['day1']['mime'] === 'image/png'
        && $mail->images['day48']['mime'] === 'image/gif');
    Http::assertSentCount(4);
    expect(DeliveryLog::sole()->status)->toBe('success');
});

test('end to end, a failed graphic fetch drops that graphic but the rich mail still sends', function () {
    Mail::fake();
    Http::fake([
        'www.spc.noaa.gov/products/outlook/day1otlk_sm.png' => Http::response('', 500),
        'www.spc.noaa.gov/products/outlook/*' => Http::response(ORM_PNG, 200, ['Content-Type' => 'image/png']),
        'www.spc.noaa.gov/products/exper/*' => Http::response(ORM_GIF, 200, ['Content-Type' => 'image/gif']),
    ]);
    $rule = AlertRule::factory()->outlookReport()->create();
    $channel = NotificationChannel::factory()->for($rule->user)->email('subscriber@example.com')->create();

    (new DeliverScheduledAlert($rule->id, $channel->id, ORM_SUBJECT, ORM_BRIEF, ormPayload()))->handle(app(ChannelDispatcher::class));

    Mail::assertSent(OutlookReportMail::class, function (OutlookReportMail $mail) {
        $html = $mail->render();

        return $mail->images['day1'] === null
            && ! str_contains($html, 'cid:spc-day1')
            && str_contains($html, 'cid:spc-day2')
            && str_contains(ormVisible($html), 'Day 1 🟡 Slight');
    });
});

test('end to end, a push-like channel receives only the brief text and no graphic is fetched', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $rule = AlertRule::factory()->outlookReport()->create();
    $channel = NotificationChannel::factory()->for($rule->user)->create(['apprise_url' => 'discord://token/id']);

    (new DeliverScheduledAlert($rule->id, $channel->id, ORM_SUBJECT, ORM_BRIEF, ormPayload()))->handle(app(ChannelDispatcher::class));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/notify/') && $request['body'] === ORM_BRIEF);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'spc.noaa.gov'));
    expect(DeliveryLog::sole()->status)->toBe('success');
});

test('a PWO bulletin with AWIPS line endings keeps single line breaks in the html and the text part', function () {
    $mail = new OutlookReportMail(ORM_SUBJECT, ormPayload(['pwo' => [
        'title' => 'Public Severe Weather Outlook',
        'text' => "LINE ONE\r\r\nLINE TWO\r\r\n\r\r\nLINE FOUR\r\nLINE FIVE\rLINE SIX",
        'issued_at' => '2026-10-31T12:51:00+00:00',
    ]]), ORM_BRIEF, []);

    expect($mail->render())->toMatch('/<pre[^>]*>LINE ONE\nLINE TWO\n\nLINE FOUR\nLINE FIVE\nLINE SIX<\/pre>/');
    $mail->assertSeeInText("LINE ONE\nLINE TWO\n\nLINE FOUR\nLINE FIVE\nLINE SIX")
        ->assertDontSeeInText("\r");
});

test('any other error while fetching a graphic returns null and logs only its class', function () {
    Log::spy();
    Http::fake(['www.spc.noaa.gov/*' => fn () => throw new RuntimeException('secret detail')]);

    expect((new GetSpcOutlookGraphic)('day1'))->toBeNull();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'SPC outlook graphic fetch failed with an error'
        && $context === ['exception' => RuntimeException::class])->once();
});

test('the Days 4-8 overview graphic is left out when every day 4 to 8 is unavailable', function () {
    $unavailable = ['available' => false, 'probability' => null, 'category' => null, 'highlight' => false, 'locations' => []];
    $html = (new OutlookReportMail(ORM_SUBJECT, ormPayload(['extended' => array_fill(4, 5, $unavailable)]), ORM_BRIEF, ormImages()))->render();

    expect($html)->not->toContain('cid:spc-day48')
        ->and(ormVisible($html))->toContain('Day 4 ❔ Not available')
        ->and($html)->toContain('cid:spc-day1');
});
