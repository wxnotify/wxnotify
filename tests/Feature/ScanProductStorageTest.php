<?php

use App\Actions\Storage\ScanProductStorage;
use App\Enums\ProductFamily;
use App\Enums\StorageDimension;
use App\Models\IngestedProduct;
use App\Models\Product;
use App\Models\ProductStorageBucket;
use App\Models\ProductStorageScan;
use App\Support\ProductStorage\CatalogGroupLabel;
use App\Support\ProductStorage\DiskGauge;
use App\Support\ProductStorage\DiskReading;
use App\Support\ProductStorage\ProductFileWalker;

function pspMakeRoot(): string
{
    $dir = sys_get_temp_dir().'/psp-'.uniqid('', true);
    mkdir($dir, 0777, true);

    return $dir;
}

function pspRemove(string $dir): void
{
    if (! is_dir($dir) || is_link($dir)) {
        @unlink($dir);

        return;
    }

    foreach (scandir($dir) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            pspRemove($dir.'/'.$entry);
        }
    }

    rmdir($dir);
}

function pspWrite(string $root, string $relative, int $bytes, ?int $modifiedAt = null): void
{
    $path = $root.'/'.$relative;

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }

    file_put_contents($path, str_repeat('x', $bytes));
    touch($path, $modifiedAt ?? time());
}

/**
 * @return array<string, int>
 */
function pspBuckets(ProductStorageScan $scan, StorageDimension $dimension): array
{
    return $scan->bucketsFor($dimension)->mapWithKeys(fn (ProductStorageBucket $bucket): array => [$bucket->label => $bucket->bytes])->all();
}

beforeEach(function () {
    $this->nwwsRoot = pspMakeRoot();
    $this->emwinRoot = pspMakeRoot();
    config([
        'services.pipeline.products_path' => $this->nwwsRoot,
        'services.pipeline.emwin_products_path' => $this->emwinRoot,
    ]);
});

afterEach(function () {
    pspRemove($this->nwwsRoot);
    pspRemove($this->emwinRoot);
});

test('catalog group labels come from the family, then the WMO letter pair', function (?ProductFamily $family, ?string $ttaaii, string $label) {
    expect(CatalogGroupLabel::forRow($family, $ttaaii))->toBe($label);
})->with([
    'family wins' => [ProductFamily::Warning, 'FZUS51', 'Warnings'],
    'forecast pair' => [null, 'FZUS51', 'Other: forecasts (FZ)'],
    'lowercase pair' => [null, 'saus70', 'Other: surface observations (SA)'],
    'unknown letter' => [null, 'XXUS01', 'WMO XX'],
    'one letter' => [null, 'F', 'Unclassified'],
    'not letters' => [null, '1AUS01', 'Unclassified'],
    'neither' => [null, null, 'Unclassified'],
]);

test('a scan classifies every file and each dimension sums to the total', function () {
    $warning = Product::factory()->create(['family' => ProductFamily::Warning]);
    $oldest = now()->subDays(10)->startOfSecond();

    pspWrite($this->nwwsRoot, 'kokx/warning.txt', 100, $oldest->getTimestamp());
    IngestedProduct::factory()->create(['file_path' => 'kokx/warning.txt', 'product_id' => $warning->id]);

    pspWrite($this->nwwsRoot, 'kokx/plain.txt', 30);
    IngestedProduct::factory()->create(['file_path' => 'kokx/plain.txt', 'product_id' => null, 'ttaaii' => null]);

    pspWrite($this->emwinRoot, 'FZUS51KOKX.TXT', 200);
    IngestedProduct::factory()->create(['file_path' => 'emwin/FZUS51KOKX.TXT', 'product_id' => null, 'ttaaii' => 'FZUS51']);

    pspWrite($this->emwinRoot, 'RADAR.GIF', 50);

    pspWrite($this->emwinRoot, 'http/AFDOKX.txt', 70);

    $scan = app(ScanProductStorage::class)();

    expect($scan->status)->toBe(ProductStorageScan::STATUS_COMPLETE)
        ->and($scan->finished_at)->not->toBeNull()
        ->and($scan->nwws_bytes)->toBe(130)
        ->and($scan->emwin_byteblaster_bytes)->toBe(250)
        ->and($scan->emwin_http_bytes)->toBe(70)
        ->and($scan->file_count)->toBe(5)
        ->and($scan->oldest_file_at->equalTo($oldest))->toBeTrue()
        ->and(pspBuckets($scan, StorageDimension::Source))->toEqualCanonicalizing([
            'NWWS' => 130,
            'EMWIN Byteblaster' => 250,
            'EMWIN HTTP' => 70,
        ])
        ->and(pspBuckets($scan, StorageDimension::FileKind))->toEqualCanonicalizing([
            'Text products' => 400,
            'Images' => 50,
        ])
        ->and(pspBuckets($scan, StorageDimension::CatalogGroup))->toEqualCanonicalizing([
            'Warnings' => 100,
            'Unclassified' => 30,
            'Other: forecasts (FZ)' => 200,
            'Unmatched' => 120,
        ]);

    foreach (StorageDimension::cases() as $dimension) {
        expect(array_sum(pspBuckets($scan, $dimension)))->toBe($scan->totalBytes());
    }

    expect($scan->bucketsFor(StorageDimension::CatalogGroup)->firstWhere('label', 'Unmatched')->file_count)->toBe(2)
        ->and($scan->disks)->toHaveCount(1)
        ->and($scan->disks[0]['label'])->toBe('Product disk')
        ->and($scan->disks[0]['product_bytes'])->toBe(450)
        ->and($scan->disks[0]['used_bytes'])->toBeInt()
        ->and($scan->disks[0]['total_bytes'])->toBeInt();
});

test('more than twenty catalog groups fold the smallest into Smaller groups', function () {
    $letters = ['B', 'D', 'E', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'P', 'Q'];

    foreach (range(1, 24) as $index) {
        $pair = $letters[intdiv($index - 1, 2)].($index % 2 === 0 ? 'A' : 'B');
        $name = "f{$index}.txt";
        pspWrite($this->nwwsRoot, $name, $index * 10);
        IngestedProduct::factory()->create(['file_path' => $name, 'product_id' => null, 'ttaaii' => $pair.'US01']);
    }

    $scan = app(ScanProductStorage::class)();
    $catalog = pspBuckets($scan, StorageDimension::CatalogGroup);

    expect($catalog)->toHaveCount(21)
        ->and($catalog[CatalogGroupLabel::SMALLER])->toBe(10 + 20 + 30 + 40)
        ->and($scan->bucketsFor(StorageDimension::CatalogGroup)->firstWhere('label', CatalogGroupLabel::SMALLER)->file_count)->toBe(4)
        ->and(array_sum($catalog))->toBe($scan->totalBytes());
});

test('a missing EMWIN root fails the scan and keeps the last complete one', function () {
    $earlier = ProductStorageScan::factory()->complete()->create(['finished_at' => now()->subHour()]);
    $ancient = ProductStorageScan::factory()->complete()->create(['started_at' => now()->subDays(200), 'finished_at' => now()->subDays(200)]);
    pspWrite($this->nwwsRoot, 'a.txt', 10);
    config(['services.pipeline.emwin_products_path' => $this->emwinRoot.'/missing']);

    $scan = app(ScanProductStorage::class)();

    expect($scan->status)->toBe(ProductStorageScan::STATUS_FAILED)
        ->and($scan->error)->toBe('Product volume unavailable: emwin')
        ->and($scan->buckets()->count())->toBe(0)
        ->and(ProductStorageScan::latestComplete()->is($earlier))->toBeTrue()
        ->and($ancient->fresh())->not->toBeNull();
});

test('a fresh running scan is returned untouched and a stale one does not block', function () {
    $running = ProductStorageScan::factory()->running()->create(['started_at' => now()->subMinutes(19)]);

    $result = app(ScanProductStorage::class)();

    expect($result->is($running))->toBeTrue()
        ->and($result->status)->toBe(ProductStorageScan::STATUS_RUNNING)
        ->and(ProductStorageScan::count())->toBe(1);

    $running->update(['started_at' => now()->subMinutes(21)]);

    $next = app(ScanProductStorage::class)();

    expect($next->is($running))->toBeFalse()
        ->and($next->status)->toBe(ProductStorageScan::STATUS_COMPLETE);
});

test('a complete scan prunes scans older than the retention window with their buckets', function () {
    config(['wxnotify.storage.snapshot_retention_days' => 90]);
    $old = ProductStorageScan::factory()->complete()->create(['started_at' => now()->subDays(91), 'finished_at' => now()->subDays(91)]);
    $oldBucket = ProductStorageBucket::factory()->create(['product_storage_scan_id' => $old->id]);
    $recent = ProductStorageScan::factory()->complete()->create(['started_at' => now()->subDays(89), 'finished_at' => now()->subDays(89)]);

    $scan = app(ScanProductStorage::class)();

    expect($scan->status)->toBe(ProductStorageScan::STATUS_COMPLETE)
        ->and($old->fresh())->toBeNull()
        ->and($oldBucket->fresh())->toBeNull()
        ->and($recent->fresh())->not->toBeNull();
});

test('an exception marks the scan failed with its class and message without re-throwing', function () {
    app()->instance(ProductFileWalker::class, new class extends ProductFileWalker
    {
        public function walk(): Generator
        {
            throw new RuntimeException('disk exploded');
            yield;
        }
    });

    $scan = app(ScanProductStorage::class)();

    expect($scan->status)->toBe(ProductStorageScan::STATUS_FAILED)
        ->and($scan->error)->toBe('RuntimeException: disk exploded')
        ->and($scan->finished_at)->not->toBeNull();
});

test('product bytes are attributed to the disk each source root sits on', function (array $devices, array $expected) {
    pspWrite($this->nwwsRoot, 'a.txt', 100);
    pspWrite($this->emwinRoot, 'B.TXT', 20);
    pspWrite($this->emwinRoot, 'http/c.txt', 3);
    $diskPaths = ['nwws' => '/disk/one', 'emwin' => '/disk/two'];

    app()->instance(DiskGauge::class, new class($devices, $diskPaths) extends DiskGauge
    {
        /**
         * @param  array<string, ?int>  $devices
         * @param  array{nwws: string, emwin: string}  $diskPaths
         */
        public function __construct(private array $devices, private array $diskPaths) {}

        public function readings(): array
        {
            return [
                new DiskReading('NWWS disk', $this->diskPaths['nwws'], 500, 1000),
                new DiskReading('EMWIN disk', $this->diskPaths['emwin'], 600, 2000),
            ];
        }

        public function deviceOf(string $path): ?int
        {
            $key = match ($path) {
                $this->paths()['nwws'] => 'nwws_root',
                $this->paths()['emwin'] => 'emwin_root',
                $this->diskPaths['nwws'] => 'disk_one',
                default => 'disk_two',
            };

            return $this->devices[$key];
        }
    });

    $scan = app(ScanProductStorage::class)();

    expect($scan->disks)->toBe([
        ['label' => 'NWWS disk', 'used_bytes' => 500, 'total_bytes' => 1000, 'product_bytes' => $expected[0]],
        ['label' => 'EMWIN disk', 'used_bytes' => 600, 'total_bytes' => 2000, 'product_bytes' => $expected[1]],
    ]);
})->with([
    'each root on its own disk' => [['nwws_root' => 1, 'emwin_root' => 2, 'disk_one' => 1, 'disk_two' => 2], [100, 23]],
    'unknown root device' => [['nwws_root' => null, 'emwin_root' => 2, 'disk_one' => 1, 'disk_two' => 2], [0, 23]],
    'unknown disk device' => [['nwws_root' => 1, 'emwin_root' => 2, 'disk_one' => null, 'disk_two' => 2], [0, 23]],
]);
