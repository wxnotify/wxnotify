<?php

use App\Filament\Admin\Resources\TestProductScenarios\Pages\CreateTestProductScenario;
use App\Filament\Admin\Resources\TestProductScenarios\Schemas\TestProductScenarioForm;
use App\Models\ActiveWwa;
use App\Models\IngestedProduct;
use App\Models\Product;
use App\Models\TestProductScenario;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
    Filament::setCurrentPanel('admin');
});

test('the product select only offers v1-eligible products, including draft ones', function () {
    $draftWarning = Product::factory()->create(['family' => 'warning', 'status' => 'draft', 'has_vtec' => true]);
    $outOfScope = Product::factory()->create(['family' => 'aviation']);

    livewire(CreateTestProductScenario::class)
        ->assertFormFieldExists('product_id');

    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    expect(TestProductScenarioForm::eligibleProductsQuery()->pluck('id'))
        ->toContain($tornadoWarning->id)
        ->toContain($draftWarning->id)
        ->not->toContain($outOfScope->id);
});

test('the "continue existing event" option only appears once an open synthetic event exists, keyed by its originating scenario', function () {
    livewire(CreateTestProductScenario::class)
        ->assertFormFieldExists('mode');

    $product = Product::where('code', 'TO.W')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create();
    $ingestedProduct = IngestedProduct::factory()->create([
        'is_test' => true, 'test_product_scenario_id' => $scenario->id,
    ]);
    $wwa = ActiveWwa::factory()->create([
        'product_id' => $product->id, 'is_test' => true, 'status' => 'Active',
        'vtec_office' => 'KTWC', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W', 'vtec_event_number' => '9020', 'vtec_year' => now()->year,
        'latest_ingested_product_id' => $ingestedProduct->id,
    ]);

    $options = TestProductScenarioForm::openSyntheticEventOptions();

    expect($options)->toHaveCount(1)
        ->and($options)->toHaveKey($scenario->id);
});

test('choosing a continued event inherits its product, WFO, event number, and prior content into form state', function () {
    $product = Product::where('code', 'TO.W')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => ['hazard' => 'Large hail.', 'ugc_codes' => ['AZC019']],
    ]);
    $ingestedProduct = IngestedProduct::factory()->create([
        'is_test' => true, 'test_product_scenario_id' => $scenario->id,
    ]);
    ActiveWwa::factory()->create([
        'product_id' => $product->id, 'is_test' => true, 'status' => 'Active',
        'vtec_office' => 'KTWC', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W', 'vtec_event_number' => '9020', 'vtec_year' => now()->year,
        'latest_ingested_product_id' => $ingestedProduct->id,
    ]);

    livewire(CreateTestProductScenario::class)
        ->set('data.mode', 'continue')
        ->set('data.continues_scenario_id', $scenario->id)
        ->assertFormSet([
            'product_id' => $product->id,
            'input.wfo' => 'TWC',
            'input.vtec_event_number' => '9020',
            'input.hazard' => 'Large hail.',
            'input.ugc_codes' => ['AZC019'],
        ]);
});
