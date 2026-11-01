<?php

namespace App\Actions\Storage;

use App\Enums\FileKind;
use App\Enums\ProductFamily;
use App\Enums\StorageDimension;
use App\Models\IngestedProduct;
use App\Models\Product;
use App\Models\ProductStorageScan;
use App\Support\ProductStorage\CatalogGroupLabel;
use App\Support\ProductStorage\DiskGauge;
use App\Support\ProductStorage\DiskReading;
use App\Support\ProductStorage\ProductFileWalker;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Walks both product roots and stores one snapshot: per-source totals, buckets by source, file kind and catalog
 * group (each dimension sums to the same product bytes), and how much of each disk the product files take. Memory
 * stays flat: files stream from the walker, rows are looked up 2,000 paths at a time without the `text` column, and
 * only small label-keyed totals are kept. A failure is recorded on the scan row, never re-thrown (the job does not
 * retry), so the page keeps showing the last complete scan.
 */
class ScanProductStorage
{
    private const BATCH_SIZE = 2000;

    private const RUNNING_WINDOW_MINUTES = 20;

    private const CATALOG_GROUP_LIMIT = 20;

    /** @var array<string, string> */
    private const SOURCE_LABELS = [
        'nwws' => 'NWWS',
        'emwin_byteblaster' => 'EMWIN Byteblaster',
        'emwin_http' => 'EMWIN HTTP',
    ];

    /** @var array<string, array{bytes: int, files: int}> */
    private array $sources = [];

    /** @var array<string, array{bytes: int, files: int}> */
    private array $fileKinds = [];

    /** @var array<string, array{bytes: int, files: int}> */
    private array $catalogGroups = [];

    /** @var array<string, int> file_path => bytes, waiting for the next row lookup */
    private array $pending = [];

    /** @var array<int, ProductFamily|null> */
    private array $families = [];

    public function __construct(
        private ProductFileWalker $walker,
        private DiskGauge $gauge,
    ) {}

    public function __invoke(): ProductStorageScan
    {
        $running = ProductStorageScan::query()
            ->where('status', ProductStorageScan::STATUS_RUNNING)
            ->where('started_at', '>=', now()->subMinutes(self::RUNNING_WINDOW_MINUTES))
            ->orderByDesc('id')
            ->first();

        if ($running !== null) {
            return $running;
        }

        $scan = ProductStorageScan::create([
            'status' => ProductStorageScan::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            $this->scan($scan);
        } catch (Throwable $exception) {
            report($exception);

            $scan->update([
                'status' => ProductStorageScan::STATUS_FAILED,
                'finished_at' => now(),
                'error' => $exception::class.': '.$exception->getMessage(),
            ]);

            return $scan;
        }

        if ($scan->status === ProductStorageScan::STATUS_COMPLETE) {
            $this->prune($scan);
        }

        return $scan;
    }

    private function scan(ProductStorageScan $scan): void
    {
        $this->sources = $this->fileKinds = $this->catalogGroups = $this->pending = [];
        $this->families = Product::query()->get(['id', 'family'])
            ->mapWithKeys(fn (Product $product): array => [$product->id => $product->family])
            ->all();

        $fileCount = 0;
        $oldest = null;

        foreach ($this->walker->walk() as $file) {
            $fileCount++;
            $oldest = $oldest === null ? $file->modifiedAt : min($oldest, $file->modifiedAt);

            $this->add($this->sources, $file->source, $file->bytes);
            $this->add($this->fileKinds, FileKind::fromFileName(basename($file->relativePath))->label(), $file->bytes);
            $this->pending[$file->filePath()] = $file->bytes;

            if (count($this->pending) >= self::BATCH_SIZE) {
                $this->classifyPending();
            }
        }

        $this->classifyPending();

        $unavailable = $this->walker->unavailableRoots();

        if ($unavailable !== []) {
            $scan->update([
                'status' => ProductStorageScan::STATUS_FAILED,
                'finished_at' => now(),
                'error' => 'Product volume unavailable: '.implode(', ', $unavailable),
            ]);

            return;
        }

        $sourceBytes = [
            'nwws' => $this->sources['nwws']['bytes'] ?? 0,
            'emwin_byteblaster' => $this->sources['emwin_byteblaster']['bytes'] ?? 0,
            'emwin_http' => $this->sources['emwin_http']['bytes'] ?? 0,
        ];

        DB::transaction(function () use ($scan, $sourceBytes, $fileCount, $oldest): void {
            $this->writeBuckets($scan);

            $scan->update([
                'status' => ProductStorageScan::STATUS_COMPLETE,
                'finished_at' => now(),
                'nwws_bytes' => $sourceBytes['nwws'],
                'emwin_byteblaster_bytes' => $sourceBytes['emwin_byteblaster'],
                'emwin_http_bytes' => $sourceBytes['emwin_http'],
                'file_count' => $fileCount,
                'oldest_file_at' => $oldest === null ? null : now()->setTimestamp($oldest),
                'disks' => $this->disks($sourceBytes),
            ]);
        });
    }

    /**
     * Look up the waiting paths' rows in one query and add each file to its catalog group.
     */
    private function classifyPending(): void
    {
        if ($this->pending === []) {
            return;
        }

        $rows = IngestedProduct::query()
            ->whereIn('file_path', array_keys($this->pending))
            ->toBase()
            ->get(['file_path', 'product_id', 'ttaaii']);

        foreach ($rows as $row) {
            $bytes = $this->pending[$row->file_path] ?? null;

            if ($bytes === null) {
                continue;
            }

            $family = $row->product_id === null ? null : ($this->families[$row->product_id] ?? null);
            $this->add($this->catalogGroups, CatalogGroupLabel::forRow($family, $row->ttaaii), $bytes);
            unset($this->pending[$row->file_path]);
        }

        foreach ($this->pending as $bytes) {
            $this->add($this->catalogGroups, CatalogGroupLabel::UNMATCHED, $bytes);
        }

        $this->pending = [];
    }

    /**
     * @param  array<string, array{bytes: int, files: int}>  $totals
     */
    private function add(array &$totals, string $label, int $bytes): void
    {
        $totals[$label] ??= ['bytes' => 0, 'files' => 0];
        $totals[$label]['bytes'] += $bytes;
        $totals[$label]['files']++;
    }

    private function writeBuckets(ProductStorageScan $scan): void
    {
        $sources = [];

        foreach ($this->sources as $source => $totals) {
            $sources[self::SOURCE_LABELS[$source]] = $totals;
        }

        $dimensions = [
            StorageDimension::Source->value => $sources,
            StorageDimension::FileKind->value => $this->fileKinds,
            StorageDimension::CatalogGroup->value => $this->foldSmallerGroups($this->catalogGroups),
        ];

        $buckets = [];

        foreach ($dimensions as $dimension => $totals) {
            foreach ($totals as $label => $total) {
                $buckets[] = ['dimension' => $dimension, 'label' => $label, 'bytes' => $total['bytes'], 'file_count' => $total['files']];
            }
        }

        $scan->buckets()->createMany($buckets);
    }

    /**
     * Keep the largest groups and fold the rest into one remainder bucket, so the dimension still sums to the total.
     *
     * @param  array<string, array{bytes: int, files: int}>  $groups
     * @return array<string, array{bytes: int, files: int}>
     */
    private function foldSmallerGroups(array $groups): array
    {
        if (count($groups) <= self::CATALOG_GROUP_LIMIT) {
            return $groups;
        }

        uasort($groups, fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        $kept = array_slice($groups, 0, self::CATALOG_GROUP_LIMIT, true);
        $smaller = ['bytes' => 0, 'files' => 0];

        foreach (array_slice($groups, self::CATALOG_GROUP_LIMIT, null, true) as $total) {
            $smaller['bytes'] += $total['bytes'];
            $smaller['files'] += $total['files'];
        }

        $kept[CatalogGroupLabel::SMALLER] = $smaller;

        return $kept;
    }

    /**
     * @param  array{nwws: int, emwin_byteblaster: int, emwin_http: int}  $sourceBytes
     * @return list<array{label: string, used_bytes: ?int, total_bytes: ?int, product_bytes: int}>
     */
    private function disks(array $sourceBytes): array
    {
        $readings = $this->gauge->readings();
        $paths = $this->gauge->paths();
        $rootBytes = [
            'nwws' => $sourceBytes['nwws'],
            'emwin' => $sourceBytes['emwin_byteblaster'] + $sourceBytes['emwin_http'],
        ];
        $rootDevices = [
            'nwws' => $this->gauge->deviceOf($paths['nwws']),
            'emwin' => $this->gauge->deviceOf($paths['emwin']),
        ];

        return array_map(fn (DiskReading $reading): array => [
            'label' => $reading->label,
            'used_bytes' => $reading->usedBytes,
            'total_bytes' => $reading->totalBytes,
            'product_bytes' => count($readings) === 1
                ? array_sum($rootBytes)
                : $this->productBytesOn($reading, $rootBytes, $rootDevices),
        ], $readings);
    }

    /**
     * The bytes of the roots on the reading's filesystem. A device id that cannot be read on either side attributes
     * nothing, so a disk's "everything else" is never computed from a guess.
     *
     * @param  array{nwws: int, emwin: int}  $rootBytes
     * @param  array{nwws: ?int, emwin: ?int}  $rootDevices
     */
    private function productBytesOn(DiskReading $reading, array $rootBytes, array $rootDevices): int
    {
        $device = $this->gauge->deviceOf($reading->path);

        if ($device === null) {
            return 0;
        }

        $bytes = 0;

        foreach ($rootDevices as $root => $rootDevice) {
            if ($rootDevice === $device) {
                $bytes += $rootBytes[$root];
            }
        }

        return $bytes;
    }

    private function prune(ProductStorageScan $scan): void
    {
        try {
            ProductStorageScan::query()
                ->whereKeyNot($scan->id)
                ->where('started_at', '<', now()->subDays((int) config('wxnotify.storage.snapshot_retention_days')))
                ->delete();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
