<?php

use App\Support\ProductStorage\DiskGauge;
use App\Support\ProductStorage\DiskReading;

function gaugeTempDir(): string
{
    $dir = sys_get_temp_dir().'/gauge-'.uniqid('', true);
    mkdir($dir, 0777, true);

    return $dir;
}

test('band thresholds are 80 and 90 percent', function (int $used, string $band) {
    expect((new DiskReading('Disk', '/x', $used, 1000))->band())->toBe($band);
})->with([
    'just under 80' => [799, 'normal'],
    'at 80' => [800, 'warning'],
    'just under 90' => [899, 'warning'],
    'at 90' => [900, 'critical'],
    'full' => [1000, 'critical'],
]);

test('percent rounds to one decimal and an unavailable reading has none', function () {
    expect((new DiskReading('Disk', '/x', 1, 3))->percent())->toBe(33.3);

    $unavailable = new DiskReading('Disk', '/x', null, null);
    expect($unavailable->isAvailable())->toBeFalse()
        ->and($unavailable->percent())->toBeNull()
        ->and($unavailable->band())->toBe('unavailable');
});

test('two roots on one filesystem give a single product disk reading', function () {
    $nwws = gaugeTempDir();
    $emwin = gaugeTempDir();
    config(['services.pipeline.products_path' => $nwws, 'services.pipeline.emwin_products_path' => $emwin]);

    $readings = (new DiskGauge)->readings();

    expect($readings)->toHaveCount(1)
        ->and($readings[0]->label)->toBe('Product disk')
        ->and($readings[0]->isAvailable())->toBeTrue()
        ->and($readings[0]->usedBytes)->toBeGreaterThan(0)
        ->and($readings[0]->totalBytes)->toBeGreaterThanOrEqual($readings[0]->usedBytes)
        ->and(stat($readings[0]->path)['dev'])->toBe(stat($nwws)['dev']);

    rmdir($nwws);
    rmdir($emwin);
});

test('a missing root gives an unavailable reading and is never merged', function () {
    $emwin = gaugeTempDir();
    config(['services.pipeline.products_path' => sys_get_temp_dir().'/gauge-missing-'.uniqid(), 'services.pipeline.emwin_products_path' => $emwin]);

    $readings = (new DiskGauge)->readings();

    expect($readings)->toHaveCount(2)
        ->and($readings[0]->label)->toBe('NWWS disk')
        ->and($readings[0]->isAvailable())->toBeFalse()
        ->and($readings[0]->percent())->toBeNull()
        ->and($readings[1]->label)->toBe('EMWIN disk')
        ->and($readings[1]->isAvailable())->toBeTrue();

    rmdir($emwin);
});

test('paths returns the two configured roots', function () {
    config(['services.pipeline.products_path' => '/a', 'services.pipeline.emwin_products_path' => '/b']);

    expect((new DiskGauge)->paths())->toBe(['nwws' => '/a', 'emwin' => '/b']);
});
