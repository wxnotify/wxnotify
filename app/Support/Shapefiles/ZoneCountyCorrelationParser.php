<?php

namespace App\Support\Shapefiles;

class ZoneCountyCorrelationParser
{
    private const FIELDS = [
        'STATE', 'ZONE', 'CWA', 'NAME', 'STATE_ZONE', 'COUNTY',
        'FIPS', 'TIME_ZONE', 'FE_AREA', 'LAT', 'LON',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $content): array
    {
        $definition = ShapefileLayerDefinitions::forKey('zone_county_correlation');
        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($content)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $record = array_combine(self::FIELDS, explode('|', $line));

            $row = [];
            foreach ($definition->attributeMap as $sourceField => $column) {
                $row[$column] = trim($record[$sourceField] ?? '');
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
