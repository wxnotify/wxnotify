<?php

namespace App\Support\Shapefiles;

final class ShapefileLayerDefinition
{
    /**
     * @param  array<string, string>  $attributeMap  source DBF/pipe field name => db column name
     * @param  array<int, string>  $naturalKeyColumns  db column name(s) that uniquely identify a record
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $sourceUrl,
        public readonly string $format,
        public readonly string $liveTable,
        public readonly string $stagingTable,
        public readonly array $naturalKeyColumns,
        public readonly array $attributeMap,
        public readonly ?string $zoneType = null,
        public readonly ?string $groupMatchText = null,
    ) {}

    public function isShapefile(): bool
    {
        return $this->format === 'shapefile';
    }

    /**
     * @param  array<string, mixed>  $record  a row keyed by db column name
     */
    public function serializeNaturalKey(array $record): string
    {
        return collect($this->naturalKeyColumns)
            ->map(fn (string $column): string => (string) ($record[$column] ?? ''))
            ->implode('|');
    }
}
