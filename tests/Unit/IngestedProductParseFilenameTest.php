<?php

use App\Models\IngestedProduct;

test('parses a well-formed filename', function () {
    $result = IngestedProduct::parseFilename('kkci_ftus21-tafkord.221432_12345.txt');

    expect($result)->toBe([
        'cccc' => 'kkci',
        'ttaaii' => 'ftus21',
        'awipsid' => 'tafkord',
        'wmo_ddhhmm' => '221432',
        'nwws_id' => '12345',
    ]);
});

test('returns all-null fields for an unparseable filename', function () {
    $result = IngestedProduct::parseFilename('not-a-valid-filename.txt');

    expect($result)->toBe([
        'cccc' => null,
        'ttaaii' => null,
        'awipsid' => null,
        'wmo_ddhhmm' => null,
        'nwws_id' => null,
    ]);
});

test('matches case-insensitively', function () {
    $result = IngestedProduct::parseFilename('KKCI_FTUS21-TAFKORD.221432_12345.TXT');

    expect($result)->toBe([
        'cccc' => 'kkci',
        'ttaaii' => 'ftus21',
        'awipsid' => 'tafkord',
        'wmo_ddhhmm' => '221432',
        'nwws_id' => '12345',
    ]);
});

test('handles an nwws_id containing dots and hyphens, since only the delimiters before it are ambiguous, not the trailing id itself', function () {
    $result = IngestedProduct::parseFilename('kkci_ftus21-tafkord.221432_nwws.processor-0000.txt');

    expect($result['nwws_id'])->toBe('nwws.processor-0000');
});
