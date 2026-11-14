<?php

use App\Actions\Shapefiles\ImportShapefileVersion;
use App\Actions\Shapefiles\ScanAllShapefileLayers;
use App\Actions\Shapefiles\ScanShapefileLayer;
use App\Filament\Admin\Resources\ShapefileLayers\Pages\ListShapefileLayers;
use App\Jobs\ScanShapefileLayersJob;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

/**
 * Makes every layer's scan stage a version, and lets $import decide what importing it does.
 */
function stubShapefileScanToStageEveryLayer(Closure $import): void
{
    test()->mock(ScanShapefileLayer::class, function ($mock): void {
        $mock->shouldReceive('__invoke')->andReturnUsing(
            fn (ShapefileLayer $layer) => ShapefileLayerVersion::factory()->for($layer, 'shapefileLayer')->create(),
        );
    });

    test()->mock(ImportShapefileVersion::class, function ($mock) use ($import): void {
        $mock->shouldReceive('__invoke')->andReturnUsing($import);
    });
}

test('the scan job runs every layer and notifies the user how many staged', function () {
    ShapefileLayer::factory()->count(2)->create();
    stubShapefileScanToStageEveryLayer(fn (ShapefileLayerVersion $version) => null);
    $user = User::factory()->create(['is_staff' => true]);

    (new ScanShapefileLayersJob($user->id))->handle(app(ScanAllShapefileLayers::class));

    expect(ShapefileLayerVersion::count())->toBe(2);

    $data = $user->notifications()->sole()->data;

    expect($data['format'])->toBe('filament')
        ->and($data['title'])->toBe('Shapefile scan finished')
        ->and($data['body'])->toBe('2 layer(s) staged a new version. Review and activate them under Shapefile Layers.');
});

test('an import failure for one layer does not stop the others', function () {
    $layers = ShapefileLayer::factory()->count(2)->create();
    $firstId = $layers->min('id');
    $imported = [];

    stubShapefileScanToStageEveryLayer(function (ShapefileLayerVersion $version) use ($firstId, &$imported): void {
        $imported[] = $version->shapefile_layer_id;

        if ($version->shapefile_layer_id === $firstId) {
            throw new RuntimeException('bad zip');
        }
    });

    $lines = [];
    $staged = app(ScanAllShapefileLayers::class)(function (string $message, string $level) use (&$lines): void {
        $lines[] = [$level, $message];
    });

    expect($staged)->toBe(2)
        ->and($imported)->toHaveCount(2)
        ->and($lines)->toContain(['error', "New version staged for {$layers->firstWhere('id', $firstId)->name}, but import failed: bad zip"]);
});

test('the header action queues the job for the current user and says a queue worker is needed', function () {
    Queue::fake();
    Filament::setCurrentPanel('admin');
    $user = User::factory()->create(['is_staff' => true]);
    $this->actingAs($user);

    livewire(ListShapefileLayers::class)->callAction('scanForUpdates');

    Queue::assertPushed(ScanShapefileLayersJob::class, fn (ScanShapefileLayersJob $job) => $job->userId === $user->id);

    $sent = collect(sentNotifications())->first(fn (array $notification): bool => ($notification['title'] ?? null) === 'Scan started');

    expect($sent)->not->toBeNull()
        ->and($sent['body'])->toContain('queue worker')
        ->and($sent['body'])->toContain('its own queue worker (queue-long)')
        ->and($sent['body'])->toContain('Alerts keep being delivered while it runs');
});

test('a scan job that fails tells the user the scan did not finish, and has room to run a full scan once', function () {
    $user = User::factory()->create(['is_staff' => true]);
    $job = new ScanShapefileLayersJob($user->id);

    $job->failed(new RuntimeException('connection refused'));

    $data = $user->notifications()->sole()->data;

    expect($data['format'])->toBe('filament')
        ->and($data['title'])->toBe('Shapefile scan failed')
        ->and($data['body'])->toContain('did not finish')
        ->and($data['body'])->toContain('logs')
        ->and($data['body'])->toContain('imports again any layer whose import was cut short')
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBeGreaterThanOrEqual(900);
});

/**
 * Stages a version on the first scan of each layer and finds no change after that, like NOAA's page
 * between releases; $import decides what each import of it does.
 */
function stubShapefileScanToStageOnce(Closure $import): void
{
    test()->mock(ScanShapefileLayer::class, function ($mock): void {
        $mock->shouldReceive('__invoke')->andReturnUsing(
            fn (ShapefileLayer $layer) => $layer->versions()->exists()
                ? null
                : ShapefileLayerVersion::factory()->for($layer, 'shapefileLayer')->create(),
        );
    });

    test()->mock(ImportShapefileVersion::class, function ($mock) use ($import): void {
        $mock->shouldReceive('__invoke')->andReturnUsing($import);
    });
}

test('a version whose import did not finish is imported again by the next scan, and only until it finishes', function () {
    $layer = ShapefileLayer::factory()->create();
    $imports = [];
    stubShapefileScanToStageOnce(function (ShapefileLayerVersion $version) use (&$imports): void {
        $imports[] = $version->id;

        if (count($imports) === 1) {
            throw new RuntimeException('worker killed');
        }

        $version->update(['diff_summary' => ['added' => 1, 'removed' => 0, 'changed' => 0, 'override_conflicts' => []]]);
    });
    $lines = [];

    app(ScanAllShapefileLayers::class)();
    $retried = app(ScanAllShapefileLayers::class)(function (string $message, string $level) use (&$lines): void {
        $lines[] = [$level, $message];
    });
    $afterwards = app(ScanAllShapefileLayers::class)();

    $version = ShapefileLayerVersion::query()->sole();

    expect($imports)->toBe([$version->id, $version->id])
        ->and($version->diff_summary)->not->toBeNull()
        ->and($retried)->toBe(1)
        ->and($lines)->toContain(['info', "Unfinished import completed for {$layer->name}"])
        ->and($afterwards)->toBe(0);
});

test('a retried import that fails again is reported as an error', function () {
    $layer = ShapefileLayer::factory()->create();
    stubShapefileScanToStageOnce(fn () => throw new RuntimeException('bad zip'));
    $lines = [];

    app(ScanAllShapefileLayers::class)();
    $retried = app(ScanAllShapefileLayers::class)(function (string $message, string $level) use (&$lines): void {
        $lines[] = [$level, $message];
    });

    expect($retried)->toBe(0)
        ->and($lines)->toContain(['error', "The unfinished import for {$layer->name} failed again: bad zip"]);
});
