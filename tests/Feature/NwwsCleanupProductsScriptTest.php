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

function nwwsCleanupFile(string $path, float $daysOld): void
{
    File::ensureDirectoryExists(dirname($path));
    File::put($path, 'product');
    touch($path, time() - (int) ($daysOld * 86400) - 60);
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
})->with(['abc', '0', '00', '-3', '2.5', '', '99999999999999999999']);

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

test('the cutoff is exact hours, not whole days', function () {
    nwwsCleanupFile($this->products.'/a/six-and-a-half.txt', 6.5);
    nwwsCleanupFile($this->products.'/a/seven-and-a-half.txt', 7.5);

    nwwsCleanupRun($this->products);

    expect(File::exists($this->products.'/a/six-and-a-half.txt'))->toBeTrue()
        ->and(File::exists($this->products.'/a/seven-and-a-half.txt'))->toBeFalse();
});

test('a one day retention keeps 20 hours and deletes 28 hours', function () {
    nwwsCleanupFile($this->products.'/a/twenty.txt', 20 / 24);
    nwwsCleanupFile($this->products.'/a/twenty-eight.txt', 28 / 24);

    nwwsCleanupRun($this->products, ['NWWS_RETENTION_DAYS' => '1']);

    expect(File::exists($this->products.'/a/twenty.txt'))->toBeTrue()
        ->and(File::exists($this->products.'/a/twenty-eight.txt'))->toBeFalse();
});

test('a zero padded retention is read as decimal', function () {
    nwwsCleanupFile($this->products.'/a/ten.txt', 10);
    nwwsCleanupFile($this->products.'/a/five.txt', 5);

    $result = nwwsCleanupRun($this->products, ['NWWS_RETENTION_DAYS' => '007']);

    expect($result->errorOutput())->toBe('')
        ->and($result->output())->toContain('older than 7 day(s)')
        ->and(File::exists($this->products.'/a/ten.txt'))->toBeFalse()
        ->and(File::exists($this->products.'/a/five.txt'))->toBeTrue();
});

test('a newline in a file name cannot redirect a deletion', function () {
    nwwsCleanupFile($this->products."/x\n".$this->products.'/kden/new.txt', 30);
    nwwsCleanupFile($this->products.'/kden/new.txt', 1);

    $result = nwwsCleanupRun($this->products);

    expect(File::exists($this->products.'/kden/new.txt'))->toBeTrue()
        ->and($result->output())->toContain('deleted 1 file(s)');
});

test('an empty or root directory is refused without running find', function (string $dir) {
    $bin = $this->root.'/bin';
    File::ensureDirectoryExists($bin);
    File::put($bin.'/find', "#!/bin/sh\ntouch \"".$this->root."/find-ran\"\n");
    chmod($bin.'/find', 0755);

    $result = nwwsCleanupRun($dir, ['PATH' => $bin.':/usr/bin:/bin']);

    expect($result->exitCode())->toBe(0)
        ->and($result->errorOutput())->toContain('refusing')
        ->and(File::exists($this->root.'/find-ran'))->toBeFalse();
})->with(['/', '//', '']);
