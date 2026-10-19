<?php

use App\Enums\ShapefileVersionStatus;
use App\Filament\Admin\Resources\ShapefileLayers\Pages\ListShapefileLayers;
use App\Filament\Admin\Resources\ShapefileLayers\Pages\ViewShapefileLayer;
use App\Filament\Admin\Resources\ShapefileLayers\ShapefileLayerResource;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['is_staff' => true]));
});

test('the list page shows every tracked layer and flags one with a pending review', function () {
    $upToDate = ShapefileLayer::factory()->create(['key' => 'us_states', 'name' => 'Up To Date Layer']);
    $pending = ShapefileLayer::factory()->create(['key' => 'counties', 'name' => 'Pending Layer']);
    ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $pending->id,
        'status' => ShapefileVersionStatus::Staged,
    ]);

    livewire(ListShapefileLayers::class)
        ->assertCanSeeTableRecords([$upToDate, $pending])
        ->assertSee('Up to date')
        ->assertSee('Pending review');
});

test('the list page shows the live table\'s actual record count, not a stale published count', function () {
    DB::insert(
        'INSERT INTO us_states (state, name, fips, lon, lat, boundary, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())',
        ['NM', 'New Mexico', '35', -106.0, 34.5, 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'],
    );
    DB::insert(
        'INSERT INTO us_states (state, name, fips, lon, lat, boundary, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())',
        ['AZ', 'Arizona', '04', -111.0, 34.0, 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'],
    );

    $layer = ShapefileLayer::factory()->create(['key' => 'us_states', 'name' => 'US States']);

    livewire(ListShapefileLayers::class)
        ->assertCanSeeTableRecords([$layer])
        ->assertSee('2');
});

test('the list page links through to the view page', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'fire_zones']);

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

test('the view page hides Activate for a staged version whose import did not finish and says how to finish it', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'us_states']);
    ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Staged,
        'diff_summary' => null,
    ]);

    livewire(ViewShapefileLayer::class, ['record' => $layer->getRouteKey()])
        ->assertActionHidden('activate')
        ->assertActionVisible('dismiss')
        ->assertSee('Its import did not finish');
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
