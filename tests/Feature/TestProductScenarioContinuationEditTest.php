<?php

use App\Filament\Admin\Resources\TestProductScenarios\Pages\CreateTestProductScenario;
use App\Filament\Admin\Resources\TestProductScenarios\Pages\EditTestProductScenario;
use App\Models\ActiveWwa;
use App\Models\IngestedProduct;
use App\Models\Product;
use App\Models\TestProductScenario;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['is_staff' => true]));
    Filament::setCurrentPanel('admin');

    DB::table('cwa_boundaries')->insert([
        'wfo' => 'PIH', 'cwa' => 'PIH', 'city' => 'Pocatello', 'st' => 'ID', 'citystate' => 'Pocatello ID',
        'lon' => -112.44, 'lat' => 42.87,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-114 41, -111 41, -111 44, -114 44, -114 41))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

/**
 * Creates a NEW SV.W scenario then a CAN continuation of it through the real
 * Create page, returning [original, continuation].
 *
 * @return array{0: TestProductScenario, 1: TestProductScenario}
 */
function contEditCreateNewThenCancel(): array
{
    $product = Product::where('code', 'SV.W')->sole();
    $content = [
        'input.hazard' => '60 mph wind gusts.',
        'input.hazard_source' => 'Radar indicated.',
        'input.impact' => 'Tree damage possible.',
        'input.call_to_action' => 'Move indoors.',
    ];

    livewire(CreateTestProductScenario::class)
        ->fillForm([
            'product_id' => $product->id,
            'input.wfo' => 'PIH',
            'input.issued_at' => now(),
            'input.expires_minutes' => 60,
            'input.polygon_center_lat' => 42.87,
            'input.polygon_center_lon' => -112.44,
            'input.polygon_radius_miles' => 8,
            ...$content,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $original = TestProductScenario::sole();

    livewire(CreateTestProductScenario::class)
        ->set('data.mode', 'continue')
        ->set('data.continues_scenario_id', $original->id)
        ->set('data.input.vtec_action', 'CAN')
        ->set('data.input.issued_at', now())
        ->call('create')
        ->assertHasNoFormErrors();

    return [$original, TestProductScenario::where('id', '!=', $original->id)->sole()];
}

test('editing and resending a CAN continuation keeps it a CAN of the same event', function () {
    [$original, $continuation] = contEditCreateNewThenCancel();

    $eventNumber = $original->fresh()->input['vtec_event_number'] ?? ActiveWwa::sole()->vtec_event_number;
    expect(ActiveWwa::count())->toBe(1)
        ->and($continuation->input['vtec_action'])->toBe('CAN');

    livewire(EditTestProductScenario::class, ['record' => $continuation->id])
        ->assertFormSet(['mode' => 'continue'])
        ->call('save')
        ->assertHasNoFormErrors();

    $continuation->refresh();
    $latest = IngestedProduct::where('test_product_scenario_id', $continuation->id)->latest('id')->firstOrFail();

    expect($continuation->input['vtec_action'])->toBe('CAN')
        ->and($continuation->input['vtec_event_number'])->toBe($eventNumber)
        ->and($continuation->input['wfo'])->toBe('PIH')
        ->and($continuation->product_id)->toBe($original->product_id)
        ->and($continuation->continues_scenario_id)->toBe($original->id)
        ->and($latest->vtec_action)->toBe('CAN')
        ->and($latest->vtec_event_number)->toBe($eventNumber)
        ->and(ActiveWwa::count())->toBe(1)
        ->and(ActiveWwa::sole()->status)->toBe('Cancelled');
});

test('the Edit page hydrates mode as new for a plain scenario', function () {
    $scenario = TestProductScenario::factory()->for(Product::where('code', 'AFD')->sole())->create();

    livewire(EditTestProductScenario::class, ['record' => $scenario->id])
        ->assertFormSet(['mode' => 'new']);
});

test('the wizard submit action is labelled Generate on both Create and Edit', function () {
    $scenario = TestProductScenario::factory()->for(Product::where('code', 'AFD')->sole())->create();

    $create = livewire(CreateTestProductScenario::class)->instance();
    $edit = livewire(EditTestProductScenario::class, ['record' => $scenario->id])->instance();

    expect($create->getWizardComponent()->getSubmitAction()->getLabel())->toBe('Generate')
        ->and($edit->getWizardComponent()->getSubmitAction()->getLabel())->toBe('Generate');
});

test('the scenario name persists through Create and Edit', function () {
    $product = Product::where('code', 'AFD')->sole();

    livewire(CreateTestProductScenario::class)
        ->fillForm([
            'name' => 'Boise AFD',
            'product_id' => $product->id,
            'input.wfo' => 'PIH',
            'input.issued_at' => now(),
            'input.body_text' => 'Clear skies.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $scenario = TestProductScenario::sole();
    expect($scenario->name)->toBe('Boise AFD');

    livewire(EditTestProductScenario::class, ['record' => $scenario->id])
        ->assertFormSet(['name' => 'Boise AFD'])
        ->fillForm(['name' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($scenario->fresh()->name)->toBe('Renamed');
});

test('re-pointing a continuation at a different open event on Edit adopts that event identity', function () {
    $product = Product::where('code', 'SV.W')->sole();
    $newEvent = function () use ($product): TestProductScenario {
        livewire(CreateTestProductScenario::class)
            ->fillForm([
                'product_id' => $product->id,
                'input.wfo' => 'PIH',
                'input.issued_at' => now(),
                'input.expires_minutes' => 60,
                'input.polygon_center_lat' => 42.87,
                'input.polygon_center_lon' => -112.44,
                'input.polygon_radius_miles' => 8,
                'input.hazard' => '60 mph wind gusts.',
                'input.hazard_source' => 'Radar indicated.',
                'input.impact' => 'Tree damage possible.',
                'input.call_to_action' => 'Move indoors.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        return TestProductScenario::latest('id')->firstOrFail();
    };

    $eventA = $newEvent();
    $eventB = $newEvent();
    $numberB = ActiveWwa::query()
        ->whereHas('latestIngestedProduct', fn ($q) => $q->where('test_product_scenario_id', $eventB->id))
        ->value('vtec_event_number');

    livewire(CreateTestProductScenario::class)
        ->set('data.mode', 'continue')
        ->set('data.continues_scenario_id', $eventA->id)
        ->set('data.input.vtec_action', 'CON')
        ->set('data.input.issued_at', now())
        ->call('create')
        ->assertHasNoFormErrors();
    $continuation = TestProductScenario::latest('id')->firstOrFail();

    livewire(EditTestProductScenario::class, ['record' => $continuation->id])
        ->set('data.continues_scenario_id', $eventB->id)
        ->call('save')
        ->assertHasNoFormErrors();

    $continuation->refresh();
    expect($continuation->continues_scenario_id)->toBe($eventB->id)
        ->and($continuation->input['vtec_event_number'])->toBe($numberB);
});
