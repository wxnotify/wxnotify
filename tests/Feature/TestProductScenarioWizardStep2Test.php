<?php

use App\Filament\Admin\Resources\TestProductScenarios\Pages\CreateTestProductScenario;
use App\Filament\Admin\Resources\TestProductScenarios\Schemas\TestProductScenarioForm;
use App\Models\Product;
use App\Models\TestProductScenario;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
    Filament::setCurrentPanel('admin');
});

function insertGeographyTwcBoundary(): void
{
    DB::table('cwa_boundaries')->insert([
        'wfo' => 'TWC', 'cwa' => 'TWC', 'city' => 'Tucson', 'st' => 'AZ', 'citystate' => 'Tucson AZ',
        'lon' => -110.9, 'lat' => 32.2,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-112 31, -109 31, -109 34, -112 34, -112 31))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('UGC options are scoped to zones/counties whose cwa matches the chosen WFO', function () {
    DB::table('public_zones')->insert([
        'state' => 'AZ', 'cwa' => 'TWC', 'zone' => '019', 'name' => 'Pima', 'state_zone' => 'AZ019',
        'lon' => -110.9, 'lat' => 32.2,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-112 31, -109 31, -109 34, -112 34, -112 31))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('public_zones')->insert([
        'state' => 'NY', 'cwa' => 'OKX', 'zone' => '072', 'name' => 'Suffolk', 'state_zone' => 'NY072',
        'lon' => -72.5, 'lat' => 40.9,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $options = TestProductScenarioForm::wfoScopedUgcOptions('TWC');

    expect($options)->toHaveKey('AZZ019')
        ->and($options)->not->toHaveKey('NYZ072');
});

test('polygon fields only appear when the selected product has a polygon', function () {
    $withPolygon = Product::where('code', 'TO.W')->sole();
    $withoutPolygon = Product::where('code', 'AFD')->sole();

    livewire(CreateTestProductScenario::class)
        ->set('data.product_id', $withPolygon->id)
        ->assertFormFieldVisible('input.polygon_advanced')
        ->assertFormFieldVisible('input.polygon_radius_miles')
        ->set('data.product_id', $withoutPolygon->id)
        ->assertFormFieldHidden('input.polygon_advanced')
        ->assertFormFieldHidden('input.polygon_radius_miles');
});

test('the raw-coordinates textarea only appears once the advanced toggle is on, and hides the guided radius field', function () {
    $withPolygon = Product::where('code', 'TO.W')->sole();

    livewire(CreateTestProductScenario::class)
        ->set('data.product_id', $withPolygon->id)
        ->assertFormFieldHidden('input.polygon_raw')
        ->assertFormFieldVisible('input.polygon_radius_miles')
        ->set('data.input.polygon_advanced', true)
        ->assertFormFieldVisible('input.polygon_raw')
        ->assertFormFieldHidden('input.polygon_radius_miles');
});

test('the UGC picker only appears when the selected product has UGC codes', function () {
    $withUgc = Product::where('code', 'TO.W')->sole();
    $withoutUgc = Product::where('code', 'AFD')->sole();

    livewire(CreateTestProductScenario::class)
        ->set('data.product_id', $withUgc->id)
        ->assertFormFieldVisible('input.ugc_codes')
        ->set('data.product_id', $withoutUgc->id)
        ->assertFormFieldHidden('input.ugc_codes');
});

test('the WFO sanity-check warning appears when the polygon center falls outside the chosen WFO boundary', function () {
    insertGeographyTwcBoundary();
    $product = Product::where('code', 'TO.W')->sole();

    livewire(CreateTestProductScenario::class)
        ->set('data.product_id', $product->id)
        ->set('data.input.wfo', 'TWC')
        ->set('data.input.polygon_center_lat', 35.0)
        ->set('data.input.polygon_center_lon', -40.0)
        ->set('data.input.polygon_radius_miles', 10)
        ->assertFormFieldVisible('wfo_sanity_warning');
});

test('the WFO sanity-check warning does not appear when the polygon center falls inside the chosen WFO boundary', function () {
    insertGeographyTwcBoundary();
    $product = Product::where('code', 'TO.W')->sole();

    livewire(CreateTestProductScenario::class)
        ->set('data.product_id', $product->id)
        ->set('data.input.wfo', 'TWC')
        ->set('data.input.polygon_center_lat', 32.5)
        ->set('data.input.polygon_center_lon', -110.5)
        ->set('data.input.polygon_radius_miles', 10)
        ->assertFormFieldHidden('wfo_sanity_warning');
});

test('the WFO sanity-check warning is advisory only and does not block the wizard from generating', function () {
    insertGeographyTwcBoundary();
    $product = Product::where('code', 'TO.W')->sole();

    livewire(CreateTestProductScenario::class)
        ->set('data.product_id', $product->id)
        ->set('data.input.wfo', 'TWC')
        ->set('data.input.polygon_center_lat', 35.0)
        ->set('data.input.polygon_center_lon', -40.0)
        ->set('data.input.polygon_radius_miles', 10)
        ->assertFormFieldVisible('wfo_sanity_warning')
        // TO.W is has_vtec, so Step 3's warning-shaped fields are required
        // to actually reach create() successfully.
        ->set('data.input.hazard', 'Large hail.')
        ->set('data.input.hazard_source', 'Radar indicated.')
        ->set('data.input.impact', 'Damaging winds possible.')
        ->set('data.input.call_to_action', 'Take shelter now.')
        ->call('create')
        ->assertHasNoFormErrors();

    // Also confirms the fields added in this task dehydrate normally (no
    // mutateFormDataBeforeCreate() rescue needed for them, unlike the 3
    // fields that stay hidden during "continue" mode) -- the point
    // TestProductScenarioForm::geographyStep()'s docblock/context relies on.
    $created = TestProductScenario::sole();
    expect((float) ($created->input['polygon_center_lat'] ?? null))->toBe(35.0)
        ->and((float) ($created->input['polygon_center_lon'] ?? null))->toBe(-40.0);
});
