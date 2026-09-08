<?php

use App\Filament\Admin\Resources\ShapefileLayers\Pages\ShapefileLayerRecords;
use App\Models\ShapefileLayer;
use App\Models\ShapefileRecordOverride;
use App\Models\Shapefiles\PublicZone;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['is_staff' => true]));
});

test('it lists the layer\'s current live records', function () {
    DB::insert(
        'INSERT INTO public_zones (state, cwa, zone, name, state_zone, lon, lat, boundary, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())',
        ['NM', 'ABQ', '201', 'Northwest Plateau', 'NM201', -108.5, 36.4, 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'],
    );

    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);

    livewire(ShapefileLayerRecords::class, ['record' => $layer->getRouteKey()])
        ->assertSee('Northwest Plateau');
});

test('applying an override updates the live table and records the override for future reimports', function () {
    DB::insert(
        'INSERT INTO public_zones (state, cwa, zone, name, state_zone, lon, lat, boundary, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())',
        ['NM', 'ABQ', '201', 'Northwest Plateau', 'NM201', -108.5, 36.4, 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'],
    );

    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    $record = PublicZone::where('state_zone', 'NM201')->firstOrFail();

    livewire(ShapefileLayerRecords::class, ['record' => $layer->getRouteKey()])
        ->callAction(TestAction::make('override')->table($record), data: [
            'field' => 'name',
            'value' => 'Admin-Corrected Name',
        ])
        ->assertNotified();

    expect(DB::table('public_zones')->where('state_zone', 'NM201')->value('name'))->toBe('Admin-Corrected Name');

    $override = ShapefileRecordOverride::where('shapefile_layer_id', $layer->id)->first();
    expect($override->record_key)->toBe('NM201')
        ->and($override->field)->toBe('name')
        ->and($override->value)->toBe('Admin-Corrected Name');
});
