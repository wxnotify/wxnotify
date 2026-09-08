<?php

use App\Enums\ShapefileVersionStatus;
use App\Filament\Admin\Resources\ShapefileLayers\Pages\ListShapefileLayers;
use App\Filament\Admin\Resources\ShapefileLayers\Pages\ViewShapefileLayer;
use App\Filament\Admin\Resources\ShapefileLayers\ShapefileLayerResource;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['is_staff' => true]));
});

test('the list page shows every tracked layer and flags one with a pending review', function () {
    $upToDate = ShapefileLayer::factory()->create(['name' => 'Up To Date Layer']);
    $pending = ShapefileLayer::factory()->create(['name' => 'Pending Layer']);
    ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $pending->id,
        'status' => ShapefileVersionStatus::Staged,
    ]);

    livewire(ListShapefileLayers::class)
        ->assertCanSeeTableRecords([$upToDate, $pending])
        ->assertSee('Up to date')
        ->assertSee('Pending review');
});

test('the list page links through to the view page', function () {
    $layer = ShapefileLayer::factory()->create();

    livewire(ListShapefileLayers::class)
        ->assertCanSeeTableRecords([$layer]);

    $this->get(ShapefileLayerResource::getUrl('view', ['record' => $layer]))
        ->assertSuccessful();
});

test('the view page shows the pending diff and an Activate action that activates it', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'us_states']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Staged,
        'diff_summary' => ['added' => 3, 'removed' => 1, 'changed' => 2, 'override_conflicts' => []],
    ]);

    livewire(ViewShapefileLayer::class, ['record' => $layer->getRouteKey()])
        ->assertSee('3')
        ->assertSee('1')
        ->assertSee('2')
        ->callAction('activate')
        ->assertNotified();

    expect($version->fresh()->status)->toBe(ShapefileVersionStatus::Active)
        ->and($layer->fresh()->active_version_id)->toBe($version->id);
});

test('the Dismiss action rejects a staged version without activating it', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'counties']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Staged,
    ]);

    livewire(ViewShapefileLayer::class, ['record' => $layer->getRouteKey()])
        ->callAction('dismiss')
        ->assertNotified();

    expect($version->fresh()->status)->toBe(ShapefileVersionStatus::Rejected)
        ->and($layer->fresh()->active_version_id)->toBeNull();
});

test('the Rollback action is only visible when a superseded version exists', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'fire_zones']);

    livewire(ViewShapefileLayer::class, ['record' => $layer->getRouteKey()])
        ->assertActionHidden('rollback');

    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Superseded,
    ]);

    livewire(ViewShapefileLayer::class, ['record' => $layer->fresh()->getRouteKey()])
        ->assertActionVisible('rollback');
});
