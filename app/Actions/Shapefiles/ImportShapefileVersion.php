<?php

namespace App\Actions\Shapefiles;

use App\Models\ShapefileLayerVersion;
use App\Models\User;
use App\Notifications\ShapefileLayerUpdateAvailableNotification;
use App\Support\Shapefiles\ShapefileLayerDefinition;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use App\Support\Shapefiles\ShapefilePolygonAssembler;
use App\Support\Shapefiles\ShapefilePolygonReader;
use App\Support\Shapefiles\ZoneCountyCorrelationParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

class ImportShapefileVersion
{
    public function __invoke(ShapefileLayerVersion $version): void
    {
        $layer = $version->shapefileLayer;
        $definition = ShapefileLayerDefinitions::forKey($layer->key);

        if ($definition->isShapefile()) {
            $this->importShapefile($version, $definition);
        } else {
            $this->importCorrelation($version, $definition);
        }

        $diff = (new ComputeShapefileLayerDiff)($layer, $definition);
        $version->update(['diff_summary' => $diff]);

        User::where('is_staff', true)->get()->each(
            fn (User $staff) => $staff->notify(new ShapefileLayerUpdateAvailableNotification($version)),
        );
    }

    private function importShapefile(ShapefileLayerVersion $version, ShapefileLayerDefinition $definition): void
    {
        $tempDir = storage_path('app/shapefile-imports/'.$version->id);
        File::ensureDirectoryExists($tempDir);

        try {
            $zipPath = $tempDir.'/download.zip';
            Http::withHeaders(['User-Agent' => $this->userAgent()])->sink($zipPath)->get($version->source_url);

            $zip = new ZipArchive;
            $zip->open($zipPath);
            $zip->extractTo($tempDir);
            $zip->close();

            $shpFiles = File::glob($tempDir.'/*.shp') ?: File::glob($tempDir.'/*.SHP');
            $basePath = substr($shpFiles[0], 0, -4);
            $dbfPath = (File::glob($basePath.'.dbf') ?: File::glob($basePath.'.DBF'))[0] ?? throw new RuntimeException('The shapefile has no .dbf attribute table.');

            ['keys' => $keys, 'counts' => $counts, 'attributes' => $attributes] = $this->readAttributeKeys($dbfPath, $definition);

            DB::transaction(function () use ($basePath, $definition, $keys, $counts, $attributes): void {
                DB::table($definition->stagingTable)->delete();

                $splitRings = [];
                $reader = ShapefilePolygonReader::open($basePath.'.shp');

                try {
                    foreach ($reader->records() as $recordNumber => $rings) {
                        $key = $keys[$recordNumber] ?? throw new RuntimeException("Shapefile record {$recordNumber} has no row in the attribute table.");

                        if ($counts[$key] === 1) {
                            $this->insertShapefileRow($attributes[$key], $rings, $definition);

                            continue;
                        }

                        foreach ($rings as $ring) {
                            $splitRings[$key][] = $ring;
                        }
                    }
                } finally {
                    $reader->close();
                }

                foreach ($splitRings as $key => $rings) {
                    $this->insertShapefileRow($attributes[$key], $rings, $definition);
                }
            });
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    /**
     * Reads the attribute table alone (no geometry) to learn each record's natural key. NOAA sometimes
     * splits one logical zone/entity across multiple physical shapefile records that share the same
     * natural key and attributes but hold different, disjoint geometry (verified against a real
     * download: 77 of Public Zones' 4,157 records are such splits, e.g. Calvert County, MD's zone is
     * two separate records for its two water-separated land areas). Each natural key must become
     * exactly one row with a combined MultiPolygon, matching this table's unique constraint on the
     * natural key, so the import needs to know up front which keys are split: it inserts every other
     * record the moment it is read and keeps the rings of only the split ones until the end. A split
     * key takes the attributes of its first record.
     *
     * @return array{keys: array<int, string>, counts: array<string, int>, attributes: array<string, array<string, string>>}
     */
    private function readAttributeKeys(string $dbfPath, ShapefileLayerDefinition $definition): array
    {
        if (! extension_loaded('dbase')) {
            throw new RuntimeException('The dbase PHP extension is required to read shapefile attributes.');
        }

        $db = dbase_open($dbfPath, 0);

        if ($db === false) {
            throw new RuntimeException('Cannot open the shapefile attribute table.');
        }

        $keys = [];
        $counts = [];
        $attributes = [];

        try {
            for ($recordNumber = 1, $total = dbase_numrecords($db); $recordNumber <= $total; $recordNumber++) {
                $row = @dbase_get_record_with_names($db, $recordNumber);
                unset($row['deleted']);

                $mapped = $this->mapAttributes($row, $definition);
                $key = $definition->serializeNaturalKey($mapped);

                $keys[$recordNumber] = $key;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                $attributes[$key] ??= $mapped;
            }
        } finally {
            dbase_close($db);
        }

        return ['keys' => $keys, 'counts' => $counts, 'attributes' => $attributes];
    }

    /**
     * @param  array<string, string>  $attributes
     * @param  array<int, array{wkt: string, clockwise: bool}>  $rings
     */
    private function insertShapefileRow(array $attributes, array $rings, ShapefileLayerDefinition $definition): void
    {
        if ($definition->zoneType !== null) {
            $attributes['zone_type'] = $definition->zoneType;
        }

        $columns = array_keys($attributes);
        $sql = sprintf(
            'INSERT INTO %s (%s, boundary, created_at, updated_at) VALUES (%s, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())',
            $definition->stagingTable,
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        );

        DB::insert($sql, [...array_values($attributes), ShapefilePolygonAssembler::toMultiPolygonWktFromRings($rings)]);
    }

    /**
     * @param  array<string, mixed>  $dbfData
     * @return array<string, string>
     */
    private function mapAttributes(array $dbfData, ShapefileLayerDefinition $definition): array
    {
        $attributes = [];

        foreach ($definition->attributeMap as $sourceField => $column) {
            $attributes[$column] = trim((string) ($dbfData[$sourceField] ?? ''));
        }

        return $attributes;
    }

    private function importCorrelation(ShapefileLayerVersion $version, ShapefileLayerDefinition $definition): void
    {
        $response = Http::withHeaders(['User-Agent' => $this->userAgent()])->get($version->source_url);
        $rows = (new ZoneCountyCorrelationParser)->parse($response->body());
        $rows = $this->deduplicateByNaturalKey($rows, $definition);

        DB::transaction(function () use ($rows, $definition): void {
            DB::table($definition->stagingTable)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($definition->stagingTable)->insert(array_map(
                    fn (array $row): array => [...$row, 'created_at' => now(), 'updated_at' => now()],
                    $chunk,
                ));
            }
        });
    }

    /**
     * NOAA's real correlation file has a handful of duplicate (state_zone, fips)
     * pairs (verified against a real download: 27 of 4,875 lines) -- the same
     * zone-county overlap split into two disjoint areas by a geographic feature,
     * with identical attributes but a different centroid. Unlike the shapefile
     * branch there's no geometry to combine here (this table carries no boundary
     * column), so the fix is simply to keep the first occurrence per natural key.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateByNaturalKey(array $rows, ShapefileLayerDefinition $definition): array
    {
        $seen = [];
        $deduplicated = [];

        foreach ($rows as $row) {
            $key = $definition->serializeNaturalKey($row);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduplicated[] = $row;
        }

        return $deduplicated;
    }

    private function userAgent(): string
    {
        return sprintf('(%s, %s)', config('app.name'), config('app.url'));
    }
}
