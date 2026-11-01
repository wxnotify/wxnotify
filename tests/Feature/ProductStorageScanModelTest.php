<?php

use App\Enums\StorageDimension;
use App\Models\ProductStorageBucket;
use App\Models\ProductStorageScan;

test('latestComplete returns the newest complete scan and ignores newer failed or running ones', function () {
    ProductStorageScan::factory()->complete()->create(['finished_at' => now()->subHours(3)]);
    $newest = ProductStorageScan::factory()->complete()->create(['finished_at' => now()->subHours(2)]);
    ProductStorageScan::factory()->failed()->create(['finished_at' => now()->subHour()]);
    ProductStorageScan::factory()->running()->create();

    expect(ProductStorageScan::latestComplete()->is($newest))->toBeTrue();
});

test('latestComplete is null when no scan has completed', function () {
    ProductStorageScan::factory()->failed()->create();

    expect(ProductStorageScan::latestComplete())->toBeNull();
});

test('latest returns the newest scan of any status', function () {
    ProductStorageScan::factory()->complete()->create();
    $running = ProductStorageScan::factory()->running()->create();

    expect(ProductStorageScan::latest()->is($running))->toBeTrue();
});

test('totalBytes sums the three source columns', function () {
    $scan = ProductStorageScan::factory()->complete()->create([
        'nwws_bytes' => 100,
        'emwin_byteblaster_bytes' => 20,
        'emwin_http_bytes' => 3,
    ]);

    expect($scan->totalBytes())->toBe(123);
});

test('deleting a scan deletes its buckets', function () {
    $scan = ProductStorageScan::factory()->complete()->create();
    ProductStorageBucket::factory()->count(2)->create(['product_storage_scan_id' => $scan->id]);

    $scan->delete();

    expect(ProductStorageBucket::count())->toBe(0);
});

test('bucketsFor returns only that dimension, largest first', function () {
    $scan = ProductStorageScan::factory()->complete()->create();
    foreach ([['nwws', 10], ['emwin_http', 30], ['emwin_byteblaster', 20]] as [$label, $bytes]) {
        ProductStorageBucket::factory()->create([
            'product_storage_scan_id' => $scan->id,
            'dimension' => StorageDimension::Source,
            'label' => $label,
            'bytes' => $bytes,
        ]);
    }
    ProductStorageBucket::factory()->create([
        'product_storage_scan_id' => $scan->id,
        'dimension' => StorageDimension::FileKind,
        'bytes' => 999,
    ]);

    $buckets = $scan->bucketsFor(StorageDimension::Source);

    expect($buckets->pluck('label')->all())->toBe(['emwin_http', 'emwin_byteblaster', 'nwws'])
        ->and($buckets->first()->dimension)->toBe(StorageDimension::Source);
});

test('the snapshot retention config defaults to 90 days', function () {
    expect(config('wxnotify.storage.snapshot_retention_days'))->toBe(90);
});
