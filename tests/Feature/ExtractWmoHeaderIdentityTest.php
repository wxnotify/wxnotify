<?php

use App\Actions\Pipeline\ExtractWmoHeaderIdentity;

test('it reads the WMO heading and AWIPS line from a warning', function () {
    $text = "WFUS53 KBOU 191622\nTORBOU\nCOZ039-191722-\n/O.NEW.KBOU.TO.W.0009.260919T1622Z-260919T1722Z/\n\nBULLETIN";

    expect((new ExtractWmoHeaderIdentity)($text))->toBe([
        'cccc' => 'kbou', 'ttaaii' => 'wfus53', 'wmo_ddhhmm' => '191622', 'bbb' => null, 'awipsid' => 'torbou',
        'key' => 'wfus53 kbou 191622 torbou',
    ]);
});

test('control characters and carriage returns from the wire are ignored', function () {
    $text = "\x01\r\r\n123 \r\r\nWFUS53 KBOU 191622\r\r\nTORBOU\r\r\nBody";

    expect((new ExtractWmoHeaderIdentity)($text)['key'])->toBe('wfus53 kbou 191622 torbou');
});

test('a BBB amendment indicator is part of the key so corrections stay distinct', function () {
    $original = (new ExtractWmoHeaderIdentity)("WFUS53 KBOU 191622\nTORBOU\nx");
    $correction = (new ExtractWmoHeaderIdentity)("WFUS53 KBOU 191622 CCA\nTORBOU\nx");

    expect($correction['bbb'])->toBe('cca')
        ->and($correction['key'])->toBe('wfus53 kbou 191622 cca torbou')
        ->and($correction['key'])->not->toBe($original['key']);
});

test('an AWIPS line is optional', function () {
    $identity = (new ExtractWmoHeaderIdentity)("SAUS70 KWBC 191600\nMETAR KBOS 191554Z 00000KT\n");

    expect($identity['awipsid'])->toBeNull()->and($identity['key'])->toBe('saus70 kwbc 191600');
});

test('text without a WMO heading has no identity', function () {
    expect((new ExtractWmoHeaderIdentity)('TAF KORD 221432Z sample text'))->toBeNull()
        ->and((new ExtractWmoHeaderIdentity)(''))->toBeNull();
});

test('only the top of the product is searched', function () {
    $body = str_repeat("filler line\n", 30)."WFUS53 KBOU 191622\nTORBOU\n";

    expect((new ExtractWmoHeaderIdentity)($body))->toBeNull();
});
