<?php

use App\Support\Delivery\VoiceScript;

/**
 * The text of every `<Say>` in a script, in order, decoded back from XML.
 *
 * @return list<string>
 */
function chanVoiceSays(string $twiml): array
{
    $xml = simplexml_load_string($twiml);

    expect($xml)->not->toBeFalse();

    $says = [];

    foreach ($xml->children() as $child) {
        if ($child->getName() === 'Say') {
            $says[] = (string) $child;
        }
    }

    return $says;
}

test('an alert is said twice with a pause', function () {
    $twiml = VoiceScript::compose(
        'A tornado was reported near Boulder. Take cover now in an interior room.',
        '[WxNotify] Tornado Warning for Boulder County',
        ['type' => 'alert'],
    );

    expect($twiml)->toStartWith('<Response><Say language="en-US">')
        ->toContain('</Say><Pause length="1"/><Say language="en-US">Again. ')
        ->toEndWith('</Say></Response>');

    $says = chanVoiceSays($twiml);
    $text = 'WxNotify weather alert. [WxNotify] Tornado Warning for Boulder County. A tornado was reported near Boulder.';

    expect($says)->toBe([$text, 'Again. '.$text]);
});

test('only the first sentence of the summary is said, cut to 200 characters at a word', function () {
    $says = chanVoiceSays(VoiceScript::compose(
        '...TORNADO WARNING IN EFFECT UNTIL 5 PM... '.str_repeat('severe ', 60).'end. Second sentence.',
        'Tornado Warning',
        [],
    ));

    $summary = substr($says[0], strlen('WxNotify weather alert. Tornado Warning. '));

    expect($summary)->toStartWith('TORNADO WARNING IN EFFECT UNTIL 5 PM')
        ->not->toContain('Second sentence')
        ->not->toContain('..')
        ->and(strlen($summary))->toBeLessThanOrEqual(201)
        ->and($summary)->toMatch('/(severe|PM)\.$/');
});

test('the confirmation code is read digit by digit twice', function () {
    $says = chanVoiceSays(VoiceScript::compose('Your code is 123456', 'Confirm', ['type' => 'confirmation', 'code' => '123456']));

    $text = 'Your WxNotify confirmation code is 1. 2. 3. 4. 5. 6.';

    expect($says)->toBe([$text, 'Again. '.$text]);
});

test('a test call says a fixed sentence', function () {
    $says = chanVoiceSays(VoiceScript::compose('This is a test. "My phone" is working correctly.', 'WxNotify test notification', ['type' => 'test']));

    expect($says[0])->toBe('This is a WxNotify test call. Your voice channel is working.');
});

test('XML special characters are escaped', function () {
    $twiml = VoiceScript::compose(
        'Rain & "wind" <b>gusts</b> it\'s bad.',
        'Warning</Say><Redirect>https://evil.example/x</Redirect><Say>',
        [],
    );

    expect($twiml)->not->toContain('<Redirect>')
        ->not->toContain('<b>')
        ->toContain('&lt;/Say&gt;&lt;Redirect&gt;')
        ->toContain('Rain &amp; &quot;wind&quot; &lt;b&gt;gusts&lt;/b&gt; it&apos;s bad.');

    $xml = simplexml_load_string($twiml);

    expect(array_map(fn (SimpleXMLElement $child): string => $child->getName(), iterator_to_array($xml->children(), false)))
        ->toBe(['Say', 'Pause', 'Say']);
});

test('characters illegal in XML and non-ASCII text are folded away', function () {
    $twiml = VoiceScript::compose("Snow\x00\x01\x1F at the café — “heavy” 🌨️ tonight.", "Winter\x0B Storm", []);

    expect(simplexml_load_string($twiml))->not->toBeFalse()
        ->and($twiml)->toMatch('/^[\x20-\x7E]*$/')
        ->and(chanVoiceSays($twiml)[0])->toBe('WxNotify weather alert. Winter Storm. Snow at the cafe - "heavy" tonight.');
});

test('the script stays under the twilio limit for a 1500-char body', function (string $body, string $title) {
    $twiml = VoiceScript::compose($body, $title, []);

    expect(strlen($twiml))->toBeLessThan(VoiceScript::MAX_LENGTH)
        ->and(simplexml_load_string($twiml))->not->toBeFalse();
})->with([
    'plain words' => [str_repeat('Heavy rain expected tonight ', 54), str_repeat('Flood Watch ', 30)],
    'every character escaped' => [str_repeat('"&\'<>', 300), str_repeat('"&\'<>', 300)],
    'apostrophes, the longest escape, past the limit unless cut' => [str_repeat("'", 1500), str_repeat("'", 1500)],
    'one endless word' => [str_repeat('x', 1500), str_repeat('y', 1500)],
]);

test('the cut is deterministic and at a word boundary', function () {
    $body = str_repeat('"quoted" ', 200);

    $first = VoiceScript::compose($body, str_repeat('"title" ', 200), []);

    expect(VoiceScript::compose($body, str_repeat('"title" ', 200), []))->toBe($first);

    foreach (chanVoiceSays($first) as $say) {
        expect($say)->toMatch('/"(title|quoted)"\.$/');
    }
});

test('no url is spoken', function () {
    $twiml = VoiceScript::compose(
        'See https://wxnotify.test/view-product/abc and www.weather.gov for details.',
        'Flood Warning http://evil.example/x',
        ['url' => 'https://wxnotify.test/view-product/abc'],
    );

    expect($twiml)->not->toContain('http')
        ->not->toContain('www.')
        ->not->toContain('wxnotify.test')
        ->not->toContain('evil.example');
});
