<?php

use App\Support\WarningColors;

test('known events have their NWS colour and others fall back by family', function () {
    expect(WarningColors::for('Tornado Warning', 'warning'))->toBe('#ff0000')
        ->and(WarningColors::for('Severe Thunderstorm Warning', 'warning'))->toBe('#ffa500')
        ->and(WarningColors::for('Flash Flood Warning', 'warning'))->toBe('#8b0000')
        ->and(WarningColors::for('Tornado Watch', 'watch'))->toBe('#ffff00')
        ->and(WarningColors::for('Severe Thunderstorm Watch', 'watch'))->toBe('#db7093')
        ->and(WarningColors::for('Something Unlisted Warning', 'warning'))->toBe('#d32f2f')
        ->and(WarningColors::for('Something Unlisted Watch', 'watch'))->toBe('#f9a825')
        ->and(WarningColors::for('Whatever', null))->toBe('#6b7280');
});
