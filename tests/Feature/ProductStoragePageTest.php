<?php

use App\Filament\Admin\Pages\ProductStorage;
use App\Filament\Admin\Widgets\ProductStorageStats;
use App\Jobs\ScanProductStorageJob;
use App\Models\ProductStorageScan;
use App\Models\User;
use App\Support\ProductStorage\Bytes;
use App\Support\ProductStorage\DiskGauge;
use App\Support\ProductStorage\DiskReading;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

function storagePageTempDir(): string
{
    $dir = sys_get_temp_dir().'/storage-page-'.uniqid('', true);
    mkdir($dir, 0777, true);

    return $dir;
}

function bindGaugeReadings(DiskReading ...$readings): void
{
    app()->instance(DiskGauge::class, new class($readings) extends DiskGauge
    {
        /**
         * @param  list<DiskReading>  $fixed
         */
        public function __construct(private array $fixed) {}

        public function readings(): array
        {
            return $this->fixed;
        }
    });
}

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
    Queue::fake();
    config([
        'services.pipeline.products_path' => storagePageTempDir(),
        'services.pipeline.emwin_products_path' => storagePageTempDir(),
    ]);
});

test('a non-staff user cannot open the page', function () {
    $this->actingAs(User::factory()->create(['is_staff' => false]));

    $this->get(ProductStorage::getUrl())->assertForbidden();
});

test('with no scan the page says so', function () {
    livewire(ProductStorage::class)->assertSee('No scan has run yet. Use Scan now.');
});

test('a complete scan shows as-of and the formatted total in the stats', function () {
    $scan = ProductStorageScan::factory()->create([
        'nwws_bytes' => 1_048_576, 'emwin_byteblaster_bytes' => 1_048_576, 'emwin_http_bytes' => 0,
        'file_count' => 1234, 'oldest_file_at' => now()->subDays(3),
    ]);

    livewire(ProductStorage::class)->assertSee('As of')->assertDontSee('stale');
    livewire(ProductStorageStats::class)
        ->assertSee('2.0 MB')
        ->assertSee('1,234');

    expect($scan->totalBytes())->toBe(2_097_152);
});

test('the stats show dashes with no complete scan', function () {
    livewire(ProductStorageStats::class)->assertSee('—');
});

test('a failed latest scan shows its error and the last good time', function () {
    ProductStorageScan::factory()->create(['finished_at' => now()->subHours(2)]);
    ProductStorageScan::factory()->failed()->create(['error' => 'Disk went away']);

    livewire(ProductStorage::class)
        ->assertSee('Disk went away')
        ->assertSee('Showing the last successful scan from');
});

test('a scan older than three hours is stale', function () {
    ProductStorageScan::factory()->create(['finished_at' => now()->subHours(4)]);

    livewire(ProductStorage::class)->assertSee('Snapshot is stale: last scan');
});

test('a running scan shows progress, an old running scan shows interrupted', function () {
    ProductStorageScan::factory()->create();
    $running = ProductStorageScan::factory()->running()->create(['started_at' => now()->subMinutes(5)]);

    livewire(ProductStorage::class)->assertSee('Scan in progress');

    $running->update(['started_at' => now()->subMinutes(30)]);

    livewire(ProductStorage::class)->assertSee('Last scan did not finish')->assertDontSee('Scan in progress');
});

test('an unreadable product root shows unavailable and no percentage', function () {
    config(['services.pipeline.products_path' => '/nonexistent/nwws-'.uniqid(), 'services.pipeline.emwin_products_path' => '/nonexistent/emwin-'.uniqid()]);

    livewire(ProductStorage::class)
        ->assertSee('Unavailable')
        ->assertSee('could not be read')
        ->assertDontSee('% full');
});

test('the product and everything-else split uses the scan disk figures', function () {
    bindGaugeReadings(new DiskReading('Product disk', '/x', 10 * 1024 ** 3, 100 * 1024 ** 3));
    ProductStorageScan::factory()->create([
        'disks' => [['label' => 'Product disk', 'product_bytes' => 4 * 1024 ** 3, 'used_bytes' => 10 * 1024 ** 3, 'total_bytes' => 100 * 1024 ** 3]],
    ]);

    livewire(ProductStorage::class)
        ->assertSee('10.0 GB used of 100.0 GB (10% full)')
        ->assertSee('Product files: 4.0 GB · Everything else: 6.0 GB');
});

test('the gauge colour follows the band', function (int $used, string $colour) {
    bindGaugeReadings(new DiskReading('Product disk', '/x', $used, 100));

    livewire(ProductStorage::class)->assertSeeHtml("width:{$used}%;background:{$colour}");
})->with([
    'normal' => [50, '#16a34a'],
    'warning' => [80, '#d97706'],
    'critical' => [90, '#dc2626'],
]);

test('scan now queues the scan job', function () {
    livewire(ProductStorage::class)
        ->callAction('scanNow')
        ->assertNotified('Scan queued');

    Queue::assertPushed(ScanProductStorageJob::class);
});

test('scan now is disabled while a fresh scan is running', function () {
    ProductStorageScan::factory()->running()->create(['started_at' => now()->subMinutes(2)]);

    livewire(ProductStorage::class)->assertActionDisabled('scanNow');
});

test('scan now refuses to queue when a scan started after the page rendered', function () {
    $page = livewire(ProductStorage::class);

    ProductStorageScan::factory()->running()->create(['started_at' => now()->subMinute()]);

    $page->callAction('scanNow');

    Queue::assertNothingPushed();
});

test('scan now is enabled when the running scan is stale', function () {
    ProductStorageScan::factory()->running()->create(['started_at' => now()->subMinutes(30)]);

    livewire(ProductStorage::class)->assertActionEnabled('scanNow');
});

test('bytes are formatted in binary units with one decimal', function (int $bytes, string $expected) {
    expect(Bytes::format($bytes))->toBe($expected);
})->with([
    'zero' => [0, '0 B'],
    'bytes' => [512, '512 B'],
    'kilobyte' => [1024, '1.0 KB'],
    'megabytes' => [1_572_864, '1.5 MB'],
    'gigabytes' => [5 * 1024 ** 3, '5.0 GB'],
    'terabytes' => [3 * 1024 ** 4, '3.0 TB'],
]);
