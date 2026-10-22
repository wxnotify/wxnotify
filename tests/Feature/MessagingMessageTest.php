<?php

use App\Support\Delivery\MessagingMessage;

const CHAN_MESSAGING_LINK = 'https://wx.example.com/view-product/kbou-torbou-271622';

test('the link is kept whole and the body is cut at a word to the type\'s limit', function (int $maxLength) {
    $body = trim(str_repeat('Take shelter now in an interior room. ', 200));

    $text = MessagingMessage::chat($body, 'Tornado Warning', ['url' => CHAN_MESSAGING_LINK], $maxLength);
    [$title, $summary, $link] = explode("\n\n", $text);

    expect(mb_strlen($text))->toBeLessThanOrEqual($maxLength)
        ->and(mb_strlen($text))->toBeGreaterThan($maxLength - 20)
        ->and($title)->toBe('Tornado Warning')
        ->and($link)->toBe(CHAN_MESSAGING_LINK)
        ->and($summary)->toEndWith('...')
        ->and(str_starts_with($body, substr($summary, 0, -3)))->toBeTrue()
        ->and($body[strlen($summary) - 3] ?? ' ')->toBe(' ');
})->with([1000, 4000]);

test('a short alert keeps its lines and is title, body and link', function () {
    expect(MessagingMessage::chat("Tornado Warning for\nBoulder County.\n", 'Alert', ['url' => CHAN_MESSAGING_LINK], 4000))
        ->toBe("Alert\n\nTornado Warning for\nBoulder County.\n\n".CHAN_MESSAGING_LINK)
        ->and(MessagingMessage::chat('Body only.', null, [], 4000))->toBe('Body only.')
        ->and(MessagingMessage::chat('Body.', 'Title', ['url' => 42], 4000))->toBe("Title\n\nBody.");
});

test('unicode survives', function () {
    $body = 'Températures glaciales ❄️ — “prenez garde” 日本語 '.str_repeat('é', 50);

    $text = MessagingMessage::chat($body, 'Avis ✓', [], 4000);
    $cut = MessagingMessage::chat(str_repeat('é ', 100), null, [], 21);

    expect($text)->toBe("Avis ✓\n\n".$body)
        ->and(mb_check_encoding($cut, 'UTF-8'))->toBeTrue()
        ->and($cut)->toBe('é é é é é é é é é...');
});

test('confirmation and test bodies pass through for chat', function () {
    $code = "Your WxNotify confirmation code is: 123456\n\nEnter this code in the dashboard to finish connecting this channel. It expires in 15 minutes.";
    $test = 'This is a test notification from WxNotify. If you received this, "Ops room" is working correctly.';

    expect(MessagingMessage::chat($code, 'Confirm your WxNotify channel', ['type' => 'confirmation', 'code' => '123456'], 1000))->toBe($code)
        ->and(MessagingMessage::chat($test, 'WxNotify test', ['type' => 'test'], 1000))->toBe($test);
});

test('whatsapp text is one line of at most 900 characters with no tab, newline or five-space run', function () {
    mt_srand(20261023);
    $pieces = ['Tornado', 'Warning', "\n", "\r\n", "\t", '     ', '  ', ' ', 'é', '❄️', "\u{00A0}", "\u{2028}", "\u{3000}", "\x0B", "\f", '*', '_', 'WHAT...', '日本語'];

    for ($i = 0; $i < 300; $i++) {
        $make = function (int $count) use ($pieces): string {
            $text = '';

            for ($n = 0; $n < $count; $n++) {
                $text .= $pieces[mt_rand(0, count($pieces) - 1)];
            }

            return $text;
        };

        $title = mt_rand(0, 3) === 0 ? null : $make(mt_rand(0, 40));
        $meta = mt_rand(0, 1) === 0 ? ['url' => CHAN_MESSAGING_LINK] : [];
        $text = MessagingMessage::whatsapp($make(mt_rand(0, 1500)), $title, $meta);

        expect(mb_strlen($text))->toBeLessThanOrEqual(MessagingMessage::WHATSAPP_MAX_LENGTH)
            ->and($text)->not->toMatch('/[\n\r\t\x0B\f]/')
            ->and($text)->not->toContain('     ')
            ->and(preg_match('/[\p{Z}\p{Cc}]{2,}/u', $text))->toBe(0)
            ->and($text)->toBe(trim($text));

        if ($meta !== []) {
            expect($text === CHAN_MESSAGING_LINK || str_ends_with($text, ' '.CHAN_MESSAGING_LINK))->toBeTrue();
        }
    }
});

test('whatsapp alerts read title, summary and link on one line', function () {
    $long = trim(str_repeat('Take shelter now. ', 100));
    $text = MessagingMessage::whatsapp($long, 'Tornado Warning', ['url' => CHAN_MESSAGING_LINK]);

    expect(MessagingMessage::whatsapp("Tornado Warning for\n\nBoulder County.", 'Alert', ['url' => CHAN_MESSAGING_LINK]))
        ->toBe('Alert: Tornado Warning for Boulder County. '.CHAN_MESSAGING_LINK)
        ->and(MessagingMessage::whatsapp('Boulder County.', null, []))->toBe('Boulder County.')
        ->and(mb_strlen($text))->toBeLessThanOrEqual(900)
        ->and($text)->toStartWith('Tornado Warning: Take shelter now.')
        ->and($text)->toEndWith('... '.CHAN_MESSAGING_LINK);
});

test('whatsapp confirmation and test texts are fixed', function () {
    $code = "Your WxNotify confirmation code is: 123456\n\nEnter this code in the dashboard.";

    expect(MessagingMessage::whatsapp($code, 'Confirm', ['type' => 'confirmation', 'code' => '123456']))
        ->toBe('Your WxNotify code is 123456. Enter it in your dashboard to confirm this number for weather alerts.')
        ->and(MessagingMessage::whatsapp("This is a test notification from WxNotify. If you received this, \"My\n\tphone\" is working correctly.", 'Test', ['type' => 'test']))
        ->toBe('WxNotify test: "My phone" is working.')
        ->and(MessagingMessage::whatsapp('No label here.', 'Test', ['type' => 'test']))
        ->toBe('WxNotify test: this channel is working.');
});
