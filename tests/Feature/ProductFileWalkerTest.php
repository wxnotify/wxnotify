<?php

use App\Enums\FileKind;
use App\Support\ProductStorage\ProductFile;
use App\Support\ProductStorage\ProductFileWalker;

function pfwMakeRoot(): string
{
    $dir = sys_get_temp_dir().'/pfw-'.uniqid();
    mkdir($dir, 0777, true);

    return $dir;
}

function pfwRemove(string $dir): void
{
    if (! is_dir($dir) || is_link($dir)) {
        @unlink($dir);

        return;
    }

    foreach (scandir($dir) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            pfwRemove($dir.'/'.$entry);
        }
    }

    rmdir($dir);
}

beforeEach(function () {
    $this->pfwDirs = [];
});

afterEach(function () {
    foreach ($this->pfwDirs as $dir) {
        pfwRemove($dir);
    }
});

it('classifies file names into kinds', function (string $name, FileKind $kind) {
    expect(FileKind::fromFileName($name))->toBe($kind);
})->with([
    ['a.TXT', FileKind::Text],
    ['a.txt.12', FileKind::Text],
    ['a.GIF', FileKind::Image],
    ['a.jpg', FileKind::Image],
    ['a.png', FileKind::Image],
    ['a.bin', FileKind::Other],
    ['noext', FileKind::Other],
]);

it('labels file kinds', function () {
    expect(FileKind::Text->label())->toBe('Text products')
        ->and(FileKind::Image->label())->toBe('Images')
        ->and(FileKind::Other->label())->toBe('Other');
});

it('yields files with the right source, key and stat values', function () {
    $nwws = pfwMakeRoot();
    $emwin = pfwMakeRoot();
    $this->pfwDirs = [$nwws, $emwin];
    mkdir($nwws.'/2026/01', 0777, true);
    mkdir($emwin.'/txt');
    mkdir($emwin.'/http');
    file_put_contents($nwws.'/2026/01/a.txt', 'hello');
    file_put_contents($emwin.'/txt/b.txt', 'abc');
    file_put_contents($emwin.'/http/c.png', 'abcdefg');
    touch($nwws.'/2026/01/a.txt', 1700000000);

    config(['services.pipeline.products_path' => $nwws, 'services.pipeline.emwin_products_path' => $emwin]);

    $walker = new ProductFileWalker;
    $files = collect(iterator_to_array($walker->walk(), false))->keyBy('relativePath');

    expect($files)->toHaveCount(3)
        ->and($files->first())->toBeInstanceOf(ProductFile::class);

    $a = $files['2026/01/a.txt'];
    expect($a->source)->toBe('nwws')
        ->and($a->filePath())->toBe('2026/01/a.txt')
        ->and($a->bytes)->toBe(5)
        ->and($a->modifiedAt)->toBe(1700000000);

    $b = $files['txt/b.txt'];
    expect($b->source)->toBe('emwin_byteblaster')
        ->and($b->filePath())->toBe('emwin/txt/b.txt')
        ->and($b->bytes)->toBe(3);

    $c = $files['http/c.png'];
    expect($c->source)->toBe('emwin_http')
        ->and($c->filePath())->toBe('emwin/http/c.png');

    expect($walker->unavailableRoots())->toBe([]);
});

it('does not follow symlinked files or directories', function () {
    $nwws = pfwMakeRoot();
    $emwin = pfwMakeRoot();
    $outside = pfwMakeRoot();
    $this->pfwDirs = [$nwws, $emwin, $outside];
    file_put_contents($outside.'/secret.txt', 'x');
    file_put_contents($nwws.'/real.txt', 'x');
    symlink($outside.'/secret.txt', $nwws.'/link.txt');
    symlink($outside, $nwws.'/linkdir');

    config(['services.pipeline.products_path' => $nwws, 'services.pipeline.emwin_products_path' => $emwin]);

    $paths = array_map(fn (ProductFile $f) => $f->relativePath, iterator_to_array((new ProductFileWalker)->walk(), false));

    expect($paths)->toBe(['real.txt']);
});

it('reports a missing root and still walks the other', function () {
    $emwin = pfwMakeRoot();
    $this->pfwDirs = [$emwin];
    file_put_contents($emwin.'/a.txt', 'x');

    config(['services.pipeline.products_path' => '/nonexistent-'.uniqid(), 'services.pipeline.emwin_products_path' => $emwin]);

    $walker = new ProductFileWalker;
    $files = iterator_to_array($walker->walk(), false);

    expect($files)->toHaveCount(1)
        ->and($files[0]->source)->toBe('emwin_byteblaster')
        ->and($walker->unavailableRoots())->toBe(['nwws']);
});
