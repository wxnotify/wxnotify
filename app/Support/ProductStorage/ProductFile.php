<?php

namespace App\Support\ProductStorage;

final readonly class ProductFile
{
    /**
     * @param  'nwws'|'emwin_byteblaster'|'emwin_http'  $source
     */
    public function __construct(
        public string $source,
        public string $relativePath,
        public int $bytes,
        public int $modifiedAt,
    ) {}

    /** The ingested_products.file_path key for this file. */
    public function filePath(): string
    {
        return $this->source === 'nwws' ? $this->relativePath : 'emwin/'.$this->relativePath;
    }
}
