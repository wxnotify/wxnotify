<?php

use App\Filament\Admin\Resources\TestProductScenarios\Pages\CreateTestProductScenario;
use App\Filament\Admin\Resources\TestProductScenarios\Schemas\TestProductScenarioForm;
use App\Models\ActiveWwa;
use App\Models\IngestedProduct;
use App\Models\Product;
use App\Models\TestProductScenario;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
    Filament::setCurrentPanel('admin');
});

test('the product select only offers v1-eligible products, including draft ones', function () {
    $draftWarning = Product::factory()->create(['family' => 'warning', 'status' => 'draft', 'has_vtec' => true]);
    $outOfScope = Product::factory()->create(['family' => 'aviation']);
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    // Checks the field's actual resolved options (grouped by family label,
    // so flattened here via `+` to merge without renumbering the int
    // product-id keys), not just the underlying helper -- proving the
    // Select is really wired to eligibleProductsQuery() and not some other
    // source.
    livewire(CreateTestProductScenario::class)
        ->assertFormFieldExists('product_id', function (Select $field) use ($tornadoWarning, $draftWarning, $outOfScope): bool {
            $options = collect($field->getOptions())->reduce(fn (array $carry, array $group): array => $carry + $group, []);

            return array_key_exists($tornadoWarning->id, $options)
                && array_key_exists($draftWarning->id, $options)
                && ! array_key_exists($outOfScope->id, $options);
        });

    expect(TestProductScenarioForm::eligibleProductsQuery()->pluck('id'))
        ->toContain($tornadoWarning->id)
        ->toContain($draftWarning->id)
        ->not->toContain($outOfScope->id);
});

test('the "continue existing event" option only appears once an open synthetic event exists, keyed by its originating scenario', function () {
    livewire(CreateTestProductScenario::class)
        ->assertFormFieldExists('mode')
        ->assertFormFieldHidden('mode');

    $product = Product::where('code', 'TO.W')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create();
    $ingestedProduct = IngestedProduct::factory()->create([
        'is_test' => true, 'test_product_scenario_id' => $scenario->id,
    ]);
    ActiveWwa::factory()->create([
        'product_id' => $product->id, 'is_test' => true, 'status' => 'Active',
        'vtec_office' => 'KTWC', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W', 'vtec_event_number' => '9020', 'vtec_year' => now()->year,
        'latest_ingested_product_id' => $ingestedProduct->id,
    ]);

    $options = TestProductScenarioForm::openSyntheticEventOptions();

    expect($options)->toHaveCount(1)
        ->and($options)->toHaveKey($scenario->id);

    // A fresh mount, now that the open event exists, proves the "mode"
    // field's own ->visible() and the "continues_scenario_id" Select's
    // ->options() are really wired to openSyntheticEventOptions() and not
    // just the static helper checked above.
    livewire(CreateTestProductScenario::class)
        ->assertFormFieldVisible('mode')
        ->set('data.mode', 'continue')
        ->assertFormFieldExists('continues_scenario_id', fn (Select $field): bool => array_key_exists($scenario->id, $field->getOptions()));
});

test('choosing a continued event inherits its product, WFO, event number, and prior content, and persists them on create', function () {
    // TestProductScenarioForm::geographyStep()'s `input.ugc_codes`
    // CheckboxList validates any inherited selection against its own
    // WFO-scoped options list (Filament's built-in "in" rule for
    // multi-value fields) -- this fixture row is what makes 'AZC019' a
    // valid option for WFO 'TWC', matching wfoScopedUgcOptions()'s own
    // county-code derivation (state + "C" + last 3 digits of fips).
    DB::table('counties')->insert([
        'state' => 'AZ', 'cwa' => 'TWC', 'countyname' => 'Pima', 'fips' => '04019',
        'lon' => -110.9, 'lat' => 32.2,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-112 31, -109 31, -109 34, -112 34, -112 31))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);

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
        ])
        ->set('data.input.vtec_action', 'CON')
        ->call('create')
        ->assertHasNoFormErrors();

    // The critical assertion: the live form state above (`assertFormSet`)
    // only proves Livewire's in-memory state looked right. product_id and
    // input.wfo/input.vtec_event_number are all ->visible() only while
    // mode !== 'continue', so without
    // CreateTestProductScenario::mutateFormDataBeforeCreate() re-deriving
    // them independent of dehydration, Filament's default
    // isDehydratedWhenHidden(false) strips them from $data before
    // ::create() ever runs -- the DB row is the only place that bug is
    // actually visible.
    //
    // input.hazard/input.ugc_codes are deliberately NOT asserted here even
    // though assertFormSet() above proved them present in live state: with
    // no real Step 2/3 field declared for those paths yet, they don't
    // dehydrate into $data at all (there's no Field to dehydrate), and
    // mutateFormDataBeforeCreate() must NOT force them back in the way it
    // does for wfo/vtec_event_number -- see
    // TestProductScenarioForm::dehydrationSafeInheritedFieldsFor()'s
    // docblock for why (an admin's real edit to a submitted content field
    // would otherwise be silently overwritten by the inherited value).
    $created = TestProductScenario::where('id', '!=', $scenario->id)->sole();

    expect($created->product_id)->toBe($product->id)
        ->and($created->continues_scenario_id)->toBe($scenario->id)
        ->and($created->input['wfo'] ?? null)->toBe('TWC')
        ->and($created->input['vtec_event_number'] ?? null)->toBe('9020')
        ->and($created->input['vtec_action'] ?? null)->toBe('CON');
});

test('mutateFormDataBeforeCreate rescues only wfo/event-number/product_id, never overwriting submitted content', function () {
    $product = Product::where('code', 'TO.W')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => ['hazard' => 'Inherited hazard text.'],
    ]);
    $ingestedProduct = IngestedProduct::factory()->create([
        'is_test' => true, 'test_product_scenario_id' => $scenario->id,
    ]);
    ActiveWwa::factory()->create([
        'product_id' => $product->id, 'is_test' => true, 'status' => 'Active',
        'vtec_office' => 'KTWC', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W', 'vtec_event_number' => '9020', 'vtec_year' => now()->year,
        'latest_ingested_product_id' => $ingestedProduct->id,
    ]);

    // Called directly rather than through the full Livewire form: Steps 2/3
    // don't have a real `input.hazard` field yet, so there's no way to get
    // Livewire to actually submit a differing value for it today. Simulates
    // what a submitted $data array will look like once such a field
    // exists -- a `hazard` key already present and different from the
    // continued scenario's own inherited value.
    $page = new CreateTestProductScenario;
    $method = new ReflectionMethod($page, 'mutateFormDataBeforeCreate');
    $method->setAccessible(true);

    $data = $method->invoke($page, [
        'mode' => 'continue',
        'continues_scenario_id' => $scenario->id,
        'input' => [
            'hazard' => 'Admin-edited hazard text.',
            'issued_at' => now(),
            'expires_minutes' => 60,
            'vtec_action' => 'CON',
        ],
    ]);

    expect($data['product_id'])->toBe($product->id)
        ->and($data['input']['wfo'])->toBe('TWC')
        ->and($data['input']['vtec_event_number'])->toBe('9020')
        // The actual point of this test: hazard is NOT one of the
        // structurally-hidden fields dehydrationSafeInheritedFieldsFor()
        // rescues, so the admin's submitted value must win over the
        // originally-inherited one, not get silently overwritten by it.
        ->and($data['input']['hazard'])->toBe('Admin-edited hazard text.');
});

test('switching mode back to "new" clears inherited fields from live form state', function () {
    $product = Product::where('code', 'TO.W')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => ['hazard' => 'Large hail.'],
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
        ->assertFormSet(['product_id' => $product->id, 'input.wfo' => 'TWC'])
        ->set('data.mode', 'new')
        ->assertFormSet([
            'continues_scenario_id' => null,
            'product_id' => null,
            'input.wfo' => null,
            'input.vtec_event_number' => null,
            'input.hazard' => null,
        ]);
});
