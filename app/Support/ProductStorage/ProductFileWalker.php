<?php

namespace App\Support\ProductStorage;

use FilesystemIterator;
use Generator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use UnexpectedValueException;

class ProductFileWalker
{
    /** @var list<string> */
    private array $unavailable = [];

    /**
     * Stream every regular file under both product roots, never following symlinks.
     *
     * @return Generator<int, ProductFile>
     */
    public function walk(): Generator
    {
        $this->unavailable = [];
        $paths = app(DiskGauge::class)->paths();

        foreach (['nwws', 'emwin'] as $root) {
            $path = rtrim($paths[$root], '/');

            if ($path === '' || ! is_dir($path)) {
                $this->unavailable[] = $root;

                continue;
            }

            yield from $this->walkRoot($root, $path);
        }
    }

    /**
     * @return list<string>
     */
    public function unavailableRoots(): array
    {
        return $this->unavailable;
    }

    /**
     * @return Generator<int, ProductFile>
     */
    private function walkRoot(string $root, string $path): Generator
    {
        try {
            $directory = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
            $filtered = new RecursiveCallbackFilterIterator(
                $directory,
                fn (SplFileInfo $entry): bool => ! $entry->isLink(),
            );
            $iterator = new RecursiveIteratorIterator($filtered, RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
        } catch (UnexpectedValueException) {
            $this->unavailable[] = $root;

            return;
        }

        $prefixLength = strlen($path) + 1;

        foreach ($iterator as $entry) {
            try {
                if (! $entry->isFile()) {
                    continue;
                }

                $bytes = $entry->getSize();
                $modifiedAt = $entry->getMTime();
            } catch (RuntimeException) {
                continue;
            }

            $relative = substr($entry->getPathname(), $prefixLength);
            $source = $root === 'nwws'
                ? 'nwws'
                : (str_starts_with($relative, 'http/') ? 'emwin_http' : 'emwin_byteblaster');

            yield new ProductFile($source, $relative, $bytes, $modifiedAt);
        }
    }
}
