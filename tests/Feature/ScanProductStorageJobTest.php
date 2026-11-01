<?php

use App\Actions\Storage\ScanProductStorage;
use App\Jobs\ScanProductStorageJob;
use App\Models\ProductStorageScan;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

test('the command queues exactly one scan job', function () {
    Queue::fake();

    $this->artisan('storage:scan-products')
        ->expectsOutput('Queued a product storage scan.')
        ->assertSuccessful();

    Queue::assertPushed(ScanProductStorageJob::class, 1);
});

test('on the database queue the job goes to the long connection and queue', function () {
    config(['queue.default' => 'database']);

    $job = new ScanProductStorageJob;

    expect($job->connection)->toBe('database_long')
        ->and($job->queue)->toBe('long');
});

test('on other queue connections the job keeps the default routing', function () {
    config(['queue.default' => 'sync']);

    $job = new ScanProductStorageJob;

    expect($job->connection)->toBeNull()
        ->and($job->queue)->toBeNull();
});

test('the job is unique so two scans never overlap', function () {
    $job = new ScanProductStorageJob;

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('product-storage-scan')
        ->and($job->uniqueFor)->toBe(1200)
        ->and($job->timeout)->toBe(900)
        ->and($job->tries)->toBe(1);
});

test('the scan runs hourly without overlapping itself', function () {
    Artisan::call('schedule:list');

    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains($event->command, 'storage:scan-products'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(60);
});

test('handling the job stores a complete scan', function () {
    $root = sys_get_temp_dir().'/psj-'.uniqid('', true);
    mkdir($root.'/nwws', 0777, true);
    mkdir($root.'/emwin', 0777, true);
    file_put_contents($root.'/nwws/a.txt', str_repeat('x', 40));
    config([
        'services.pipeline.products_path' => $root.'/nwws',
        'services.pipeline.emwin_products_path' => $root.'/emwin',
    ]);

    (new ScanProductStorageJob)->handle(app(ScanProductStorage::class));

    $scan = ProductStorageScan::query()->latest('id')->first();

    expect($scan->status)->toBe(ProductStorageScan::STATUS_COMPLETE)
        ->and($scan->nwws_bytes)->toBe(40);

    unlink($root.'/nwws/a.txt');
    rmdir($root.'/nwws');
    rmdir($root.'/emwin');
    rmdir($root);
});
