<?php

use App\Enums\StorageDimension;
use App\Filament\Admin\Pages\ProductStorage;
use App\Filament\Admin\Widgets\StorageByCatalogGroupChart;
use App\Filament\Admin\Widgets\StorageByFileKindChart;
use App\Filament\Admin\Widgets\StorageBySourceChart;
use App\Filament\Admin\Widgets\StorageGrowthChart;
use App\Models\ProductStorageBucket;
use App\Models\ProductStorageScan;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['is_staff' => true]));
});

function storageChartData(string $widget): array
{
    $instance = livewire($widget)->instance();

    return (new ReflectionMethod($instance, 'getData'))->invoke($instance);
}

dataset('bucket charts', [
    'source' => [StorageBySourceChart::class, StorageDimension::Source],
    'catalog group' => [StorageByCatalogGroupChart::class, StorageDimension::CatalogGroup],
    'file kind' => [StorageByFileKindChart::class, StorageDimension::FileKind],
]);

test('a bucket chart shows the latest complete scan buckets largest first in GB', function (string $widget, StorageDimension $dimension) {
    $old = ProductStorageScan::factory()->create(['finished_at' => now()->subDay()]);
    ProductStorageBucket::factory()->create(['product_storage_scan_id' => $old->id, 'dimension' => $dimension, 'label' => 'Stale', 'bytes' => 999]);

    $scan = ProductStorageScan::factory()->create();
    ProductStorageBucket::factory()->create(['product_storage_scan_id' => $scan->id, 'dimension' => $dimension, 'label' => 'Small', 'bytes' => 1_073_741_824]);
    ProductStorageBucket::factory()->create(['product_storage_scan_id' => $scan->id, 'dimension' => $dimension, 'label' => 'Big', 'bytes' => 3_221_225_472]);
    ProductStorageBucket::factory()->create(['product_storage_scan_id' => $scan->id, 'dimension' => $dimension === StorageDimension::Source ? StorageDimension::FileKind : StorageDimension::Source, 'label' => 'Other dimension', 'bytes' => 5]);

    $data = storageChartData($widget);

    expect($data['labels'])->toBe(['Big', 'Small'])
        ->and($data['datasets'][0]['data'])->toBe([3.0, 1.0]);
})->with('bucket charts');

test('a bucket chart is empty without a complete scan', function (string $widget) {
    ProductStorageScan::factory()->failed()->create();

    $data = storageChartData($widget);

    expect($data['labels'])->toBe([])->and($data['datasets'][0]['data'])->toBe([]);
})->with([StorageBySourceChart::class, StorageByCatalogGroupChart::class, StorageByFileKindChart::class]);

test('the growth chart plots complete scans oldest first and ignores failed ones', function () {
    $gb = 1_073_741_824;
    ProductStorageScan::factory()->create([
        'finished_at' => now()->subDays(2), 'nwws_bytes' => $gb, 'emwin_byteblaster_bytes' => 2 * $gb, 'emwin_http_bytes' => 0,
        'disks' => [['label' => 'Products', 'used_bytes' => 25, 'total_bytes' => 100, 'product_bytes' => 1]],
    ]);
    ProductStorageScan::factory()->create([
        'finished_at' => now()->subDay(), 'nwws_bytes' => 3 * $gb, 'emwin_byteblaster_bytes' => 0, 'emwin_http_bytes' => $gb,
        'disks' => null,
    ]);
    ProductStorageScan::factory()->failed()->create(['finished_at' => now()->subHours(3)]);
    ProductStorageScan::factory()->create(['finished_at' => now()->subDays(40)]);

    $data = storageChartData(StorageGrowthChart::class);

    expect($data['labels'])->toHaveCount(2)
        ->and($data['datasets'][0]['data'])->toBe([1.0, 3.0])
        ->and($data['datasets'][1]['data'])->toBe([2.0, 0.0])
        ->and($data['datasets'][2]['data'])->toBe([0.0, 1.0])
        ->and($data['datasets'][3]['data'])->toBe([25.0, null]);
});

test('the growth chart is empty without scans', function () {
    $data = storageChartData(StorageGrowthChart::class);

    expect($data['labels'])->toBe([])->and($data['datasets'][0]['data'])->toBe([]);
});

test('the page renders all four charts', function () {
    livewire(ProductStorage::class)
        ->assertSeeLivewire(StorageBySourceChart::class)
        ->assertSeeLivewire(StorageByCatalogGroupChart::class)
        ->assertSeeLivewire(StorageByFileKindChart::class)
        ->assertSeeLivewire(StorageGrowthChart::class);
});

test('no chart polls on its own', function (string $widget) {
    $instance = livewire($widget)->instance();

    expect((new ReflectionMethod($instance, 'getPollingInterval'))->invoke($instance))->toBeNull();
})->with([StorageBySourceChart::class, StorageByCatalogGroupChart::class, StorageByFileKindChart::class, StorageGrowthChart::class]);

test('a bucket under 5 MB keeps its raw bytes and the tooltip callback is present', function () {
    $scan = ProductStorageScan::factory()->create();
    ProductStorageBucket::factory()->create(['product_storage_scan_id' => $scan->id, 'dimension' => StorageDimension::FileKind, 'label' => 'Tiny', 'bytes' => 2_200_000]);

    $data = storageChartData(StorageByFileKindChart::class);
    $instance = livewire(StorageByFileKindChart::class)->instance();
    $options = (new ReflectionMethod($instance, 'getOptions'))->invoke($instance);

    expect($data['datasets'][0]['bytes'])->toBe([2_200_000])
        ->and($data['datasets'][0]['data'])->toBe([0.0])
        ->and((string) $options)->toContain('callbacks')->toContain('dataset.bytes');
});

test('the growth chart carries raw bytes for its size datasets', function () {
    ProductStorageScan::factory()->create(['nwws_bytes' => 2_200_000, 'emwin_byteblaster_bytes' => 5, 'emwin_http_bytes' => 0]);

    $data = storageChartData(StorageGrowthChart::class);

    expect($data['datasets'][0]['bytes'])->toBe([2_200_000])->and($data['datasets'][1]['bytes'])->toBe([5]);
});
