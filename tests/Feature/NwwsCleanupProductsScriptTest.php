<?php

use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function nwwsCleanupRun(string $dir, array $extraEnv = []): ProcessResult
{
    return Process::path(base_path())->env($extraEnv + [
        'PATH' => '/usr/bin:/bin',
        'NWWS_PRODUCTS_DIR' => $dir,
    ])->run(['sh', 'docker/nwws/cleanup-products.sh']);
}

function nwwsCleanupFile(string $path, int $daysOld): void
{
    File::ensureDirectoryExists(dirname($path));
    File::put($path, 'product');
    touch($path, time() - ($daysOld * 86400) - 60);
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nwws-cleanup-'.uniqid();
    $this->products = $this->root.'/products';
    File::makeDirectory($this->products, recursive: true);
});

afterEach(fn () => File::deleteDirectory($this->root));

test('the script is valid POSIX sh', function () {
    expect(Process::path(base_path())->run(['sh', '-n', 'docker/nwws/cleanup-products.sh'])->successful())->toBeTrue();
});

test('files older than the retention are deleted and newer ones kept', function () {
    nwwsCleanupFile($this->products.'/kbou/old.txt', 8);
    nwwsCleanupFile($this->products.'/kbou/inside.txt', 6);
    nwwsCleanupFile($this->products.'/kden/new.txt', 1);

    $result = nwwsCleanupRun($this->products);

    expect($result->exitCode())->toBe(0)
        ->and(File::exists($this->products.'/kbou/old.txt'))->toBeFalse()
        ->and(File::exists($this->products.'/kbou/inside.txt'))->toBeTrue()
        ->and(File::exists($this->products.'/kden/new.txt'))->toBeTrue();
});

test('directories remain, including one left empty', function () {
    nwwsCleanupFile($this->products.'/kbou/old.txt', 30);
    nwwsCleanupFile($this->products.'/kden/deep/old.txt', 30);

    nwwsCleanupRun($this->products);

    expect(is_dir($this->products.'/kbou'))->toBeTrue()
        ->and(is_dir($this->products.'/kden/deep'))->toBeTrue()
        ->and(File::exists($this->products.'/kbou/old.txt'))->toBeFalse();
});

test('a symlink is neither followed nor removed', function () {
    $outside = $this->root.'/outside';
    nwwsCleanupFile($outside.'/target.txt', 30);
    File::ensureDirectoryExists($this->products.'/kbou');
    symlink($outside.'/target.txt', $this->products.'/kbou/link.txt');
    symlink($outside, $this->products.'/kbou/linkdir');

    nwwsCleanupRun($this->products);

    expect(is_link($this->products.'/kbou/link.txt'))->toBeTrue()
        ->and(is_link($this->products.'/kbou/linkdir'))->toBeTrue()
        ->and(File::exists($outside.'/target.txt'))->toBeTrue();
});

test('an invalid retention falls back to 7 days and warns', function (string $value) {
    nwwsCleanupFile($this->products.'/kbou/old.txt', 10);
    nwwsCleanupFile($this->products.'/kbou/recent.txt', 3);

    $result = nwwsCleanupRun($this->products, ['NWWS_RETENTION_DAYS' => $value]);

    expect($result->exitCode())->toBe(0)
        ->and($result->errorOutput())->toContain('NWWS_RETENTION_DAYS')
        ->and($result->output())->toContain('older than 7 day(s)')
        ->and(File::exists($this->products.'/kbou/old.txt'))->toBeFalse()
        ->and(File::exists($this->products.'/kbou/recent.txt'))->toBeTrue();
})->with(['abc', '0', '-3', '2.5', '']);

test('a valid retention is honoured without a warning', function () {
    nwwsCleanupFile($this->products.'/kbou/four.txt', 4);
    nwwsCleanupFile($this->products.'/kbou/one.txt', 1);

    $result = nwwsCleanupRun($this->products, ['NWWS_RETENTION_DAYS' => '2']);

    expect($result->errorOutput())->toBe('')
        ->and($result->output())->toContain('older than 2 day(s)')
        ->and(File::exists($this->products.'/kbou/four.txt'))->toBeFalse()
        ->and(File::exists($this->products.'/kbou/one.txt'))->toBeTrue();
});

test('a missing directory exits 0 with a warning and deletes nothing', function () {
    nwwsCleanupFile($this->root.'/elsewhere/old.txt', 30);

    $result = nwwsCleanupRun($this->products.'/nope');

    expect($result->exitCode())->toBe(0)
        ->and($result->errorOutput())->not->toBe('')
        ->and($result->output())->not->toContain('deleted')
        ->and(File::exists($this->root.'/elsewhere/old.txt'))->toBeTrue();
});

test('a path that is a file is treated as not a directory', function () {
    nwwsCleanupFile($this->root.'/afile', 30);

    $result = nwwsCleanupRun($this->root.'/afile');

    expect($result->exitCode())->toBe(0)
        ->and($result->errorOutput())->not->toBe('')
        ->and(File::exists($this->root.'/afile'))->toBeTrue();
});

test('the summary line reports the number of deleted files', function () {
    nwwsCleanupFile($this->products.'/a/1.txt', 9);
    nwwsCleanupFile($this->products.'/a/2.txt', 9);
    nwwsCleanupFile($this->products.'/b/3.txt', 20);
    nwwsCleanupFile($this->products.'/b/4.txt', 1);

    $result = nwwsCleanupRun($this->products);

    expect(trim($result->output()))->toBe('nwws retention: deleted 3 file(s) older than 7 day(s)');
});
