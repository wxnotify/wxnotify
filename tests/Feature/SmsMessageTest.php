<?php

use App\Support\Delivery\SmsMessage;

const CHAN_SMS_LINK = 'https://wxnotify.test/view-product/kbou-torbou-271622';

/**
 * Whether every character of the text is in the GSM-7 basic set's ASCII range: printable ASCII
 * without the extension characters (`[ ] { } ~ ^ \ |`, which cost two septets each) and the
 * backtick (not in GSM-7 at all), plus the line feed.
 */
function chanIsGsm7Basic(string $text): bool
{
    return preg_match('/^[\n !"#$%&\'()*+,\-.\/0-9:;<=>?@A-Z_a-z]*$/', $text) === 1;
}

test('it folds to the GSM-7 basic set', function () {
    $message = SmsMessage::compose(
        '“Take cover” now — it’s [x] {urgent} 🌪️ at the café in Zürich, €5 ~ ^ \\ | `done`',
        '🌪️ Tornado Warning [Boulder]',
        ['type' => 'alert'],
    );

    expect($message)->toBe("Tornado Warning (Boulder)\n\"Take cover\" now - it's (x) urgent at the cafe in Zurich, EUR5 'done'")
        ->and(chanIsGsm7Basic($message))->toBeTrue();
});

test('it never exceeds two segments', function () {
    mt_srand(20261022);
    $pieces = ['Tornado', 'Warning', '“quoted”', '🌪️', '[x]', '{}', '€', 'café', 'Zürich', 'naïve', "\n", "\t", '—', '...', '中文', 'a', str_repeat('Supercalifragilistic', 4), 'https://example.com/x'];
    $randomText = function (int $words) use ($pieces): string {
        $text = '';

        for ($i = 0; $i < $words; $i++) {
            $text .= $pieces[mt_rand(0, count($pieces) - 1)].(mt_rand(0, 3) === 0 ? '' : ' ');
        }

        return $text;
    };

    for ($i = 0; $i < 400; $i++) {
        $url = mt_rand(0, 2) === 0 ? null : 'https://wxnotify.test/view-product/'.str_repeat('a', mt_rand(1, 110));
        $type = ['alert', 'alert', 'alert', 'test', 'confirmation'][mt_rand(0, 4)];
        $body = $type === 'test' ? 'This is a test notification from WxNotify. If you received this, "'.$randomText(mt_rand(0, 120)).'" is working correctly.' : $randomText(mt_rand(0, 400));
        $title = mt_rand(0, 4) === 0 ? null : $randomText(mt_rand(0, 60));

        $message = SmsMessage::compose($body, $title, ['type' => $type, 'url' => $url, 'code' => '123456'], mt_rand(0, 1) === 0 ? 'Reply STOP to opt out.' : null);

        expect(strlen($message))->toBeLessThanOrEqual(SmsMessage::MAX_LENGTH)
            ->and(chanIsGsm7Basic($message))->toBeTrue();
    }

    expect(SmsMessage::MAX_LENGTH)->toBe(306);
});

test('the link is kept whole and the summary is cut at a word with an ellipsis', function () {
    $body = str_repeat('Take shelter now in an interior room on the lowest floor. ', 20);

    $message = SmsMessage::compose($body, 'Tornado Warning', ['type' => 'alert', 'url' => CHAN_SMS_LINK]);
    [$title, $summary, $link] = explode("\n", $message);

    expect(strlen($message))->toBeLessThanOrEqual(SmsMessage::MAX_LENGTH)
        ->and(strlen($message))->toBeGreaterThan(SmsMessage::MAX_LENGTH - 20)
        ->and($title)->toBe('Tornado Warning')
        ->and($link)->toBe(CHAN_SMS_LINK)
        ->and($summary)->toEndWith('...');

    $kept = substr($summary, 0, -3);

    expect(trim($body))->toStartWith($kept)
        ->and(substr(trim($body), strlen($kept), 1))->toBe(' ');
});

test('the title is cut to 100 characters', function () {
    $message = SmsMessage::compose('Body.', str_repeat('Severe Thunderstorm Warning ', 10), ['type' => 'alert']);
    $title = explode("\n", $message)[0];

    expect(strlen($title))->toBeLessThanOrEqual(100)
        ->and($title)->toEndWith('...')
        ->and($message)->toEndWith("\nBody.");
});

test('a very long link is dropped rather than cut', function () {
    $longLink = 'https://wxnotify.test/view-product/'.str_repeat('a', 121 - strlen('https://wxnotify.test/view-product/'));
    $edgeLink = substr($longLink, 0, 120);

    expect(SmsMessage::compose('Take cover.', 'Tornado Warning', ['type' => 'alert', 'url' => $longLink]))
        ->toBe("Tornado Warning\nTake cover.")
        ->and(SmsMessage::compose('Take cover.', 'Tornado Warning', ['type' => 'alert', 'url' => $edgeLink]))
        ->toBe("Tornado Warning\nTake cover.\n".$edgeLink);
});

test('no link leaves more summary', function () {
    $body = str_repeat('Take shelter now in an interior room on the lowest floor. ', 20);

    $withLink = explode("\n", SmsMessage::compose($body, 'Tornado Warning', ['type' => 'alert', 'url' => CHAN_SMS_LINK]))[1];
    $withoutLink = SmsMessage::compose($body, 'Tornado Warning', ['type' => 'alert']);

    expect(substr_count($withoutLink, "\n"))->toBe(1)
        ->and(strlen(explode("\n", $withoutLink)[1]))->toBeGreaterThan(strlen($withLink) + strlen(CHAN_SMS_LINK) - 10)
        ->and(strlen($withoutLink))->toBeLessThanOrEqual(SmsMessage::MAX_LENGTH);
});

test('the confirmation text carries the code and the opt-out hint when given', function () {
    $body = "Your WxNotify confirmation code is: 482913\n\nEnter this code in the dashboard to finish connecting this channel. It expires in 15 minutes.";
    $meta = ['type' => 'confirmation', 'code' => '482913'];

    expect(SmsMessage::compose($body, 'Confirm your WxNotify channel', $meta, 'Reply STOP to opt out.'))
        ->toBe('WxNotify code: 482913. Enter it in your dashboard to confirm this number for weather alerts. Reply STOP to opt out.')
        ->and(SmsMessage::compose($body, 'Confirm your WxNotify channel', $meta))
        ->toBe('WxNotify code: 482913. Enter it in your dashboard to confirm this number for weather alerts.');
});

test('the test text names the channel from the body\'s quoted label', function () {
    $body = 'This is a test notification from WxNotify. If you received this, "Twilio SMS •••• 0123" is working correctly.';

    expect(SmsMessage::compose($body, 'WxNotify test notification', ['type' => 'test']))
        ->toBe('WxNotify test: "Twilio SMS 0123" is working.');
});

test('line breaks in the body are collapsed', function () {
    expect(SmsMessage::compose("Line one\n\nLine two\r\n   three\tfour", "Tornado\nWarning", ['type' => 'alert']))
        ->toBe("Tornado Warning\nLine one Line two three four");
});

test('a missing type is an alert and a blank title leaves only the summary', function () {
    expect(SmsMessage::compose('Take cover.', null, ['url' => CHAN_SMS_LINK]))
        ->toBe("Take cover.\n".CHAN_SMS_LINK)
        ->and(SmsMessage::compose('Take cover.', '🌪️', []))
        ->toBe('Take cover.');
});
