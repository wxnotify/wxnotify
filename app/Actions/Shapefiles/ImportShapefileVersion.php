<?php

namespace App\Actions\Shapefiles;

use App\Models\ShapefileLayerVersion;
use App\Models\User;
use App\Notifications\ShapefileLayerUpdateAvailableNotification;
use App\Support\Shapefiles\ShapefileLayerDefinition;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use App\Support\Shapefiles\ShapefilePolygonAssembler;
use App\Support\Shapefiles\ZoneCountyCorrelationParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PhpMyAdmin\ShapeFile\ShapeFile;
use PhpMyAdmin\ShapeFile\ShapeRecord;
use PhpMyAdmin\ShapeFile\ShapeType;
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

            $shp = new ShapeFile(ShapeType::Polygon);
            $shp->loadFromFile($basePath.'.*');

            DB::transaction(function () use ($shp, $definition): void {
                DB::table($definition->stagingTable)->delete();

                foreach ($this->groupRecordsByNaturalKey($shp->records, $definition) as $group) {
                    $this->insertShapefileRecordGroup($group, $definition);
                }
            });
        } finally {
            File::deleteDirectory($tempDir);
        }
    }

    /**
     * NOAA sometimes splits one logical zone/entity across multiple physical shapefile
     * records that share the same natural key and attributes but hold different,
     * disjoint geometry (verified against a real download: 77 of Public Zones' 4,157
     * records are such splits, e.g. Calvert County, MD's zone is two separate records
     * for its two water-separated land areas). Group them here so each natural key
     * becomes exactly one row with a combined MultiPolygon, matching this table's
     * unique constraint on the natural key — inserting them separately would violate
     * that constraint (or, without it, silently produce duplicate rows for one zone).
     *
     * @param  array<int, ShapeRecord>  $records
     * @return array<string, array<int, ShapeRecord>>
     */
    private function groupRecordsByNaturalKey(array $records, ShapefileLayerDefinition $definition): array
    {
        $groups = [];

        foreach ($records as $record) {
            $key = $definition->serializeNaturalKey($this->mapAttributes($record, $definition));
            $groups[$key][] = $record;
        }

        return $groups;
    }

    /**
     * @param  array<int, ShapeRecord>  $group
     */
    private function insertShapefileRecordGroup(array $group, ShapefileLayerDefinition $definition): void
    {
        $attributes = $this->mapAttributes($group[0], $definition);

        if ($definition->zoneType !== null) {
            $attributes['zone_type'] = $definition->zoneType;
        }

        $allParts = [];

        foreach ($group as $record) {
            array_push($allParts, ...($record->shpData['parts'] ?? []));
        }

        $wkt = ShapefilePolygonAssembler::toMultiPolygonWkt($allParts);

        $columns = array_keys($attributes);
        $sql = sprintf(
            'INSERT INTO %s (%s, boundary, created_at, updated_at) VALUES (%s, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())',
            $definition->stagingTable,
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        );

        DB::insert($sql, [...array_values($attributes), $wkt]);
    }

    /**
     * @return array<string, string>
     */
    private function mapAttributes(ShapeRecord $record, ShapefileLayerDefinition $definition): array
    {
        $attributes = [];

        foreach ($definition->attributeMap as $sourceField => $column) {
            $attributes[$column] = trim((string) ($record->dbfData[$sourceField] ?? ''));
        }

        return $attributes;
    }

    private function importCorrelation(ShapefileLayerVersion $version, ShapefileLayerDefinition $definition): void
    {
        $response = Http::withHeaders(['User-Agent' => $this->userAgent()])->get($version->source_url);
        $rows = (new ZoneCountyCorrelationParser)->parse($response->body());

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

    private function userAgent(): string
    {
        return sprintf('(%s, %s)', config('app.name'), config('app.url'));
    }
}
