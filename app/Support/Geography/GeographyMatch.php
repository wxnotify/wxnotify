<?php

namespace App\Support\Geography;

use App\Models\Location;

final class GeographyMatch
{
    private function __construct(
        public readonly bool $matched,
        public readonly ?Location $location = null,
    ) {}

    public static function matched(?Location $location = null): self
    {
        return new self(true, $location);
    }

    public static function notMatched(): self
    {
        return new self(false, null);
    }
}
