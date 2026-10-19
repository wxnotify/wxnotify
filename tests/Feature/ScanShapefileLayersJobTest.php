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
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
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

    $notifications = new Notifications;
    $notifications->mount();
    $sent = $notifications->notifications->first(fn (Notification $notification) => $notification->getTitle() === 'Scan started');

    expect($sent)->not->toBeNull()
        ->and($sent->getBody())->toContain('queue worker');
});
