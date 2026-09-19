<?php

use App\Actions\Testing\GenerateTestProduct;
use App\Filament\Admin\Resources\TestProductScenarios\Pages\CreateTestProductScenario;
use App\Filament\Admin\Resources\TestProductScenarios\Pages\EditTestProductScenario;
use App\Filament\Admin\Resources\TestProductScenarios\TestProductScenarioResource;
use App\Models\IngestedProduct;
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

    // The WFO select validates its submitted value against the real
    // cwa_boundaries table (see TestProductScenarioForm::productAndTimingStep()),
    // which RefreshDatabase truncates between tests -- PIH must exist here or
    // ->fillForm(['input.wfo' => 'PIH']) fails validation before Generate ever runs.
    DB::table('cwa_boundaries')->insert([
        'wfo' => 'PIH', 'cwa' => 'PIH', 'city' => 'Pocatello', 'st' => 'ID', 'citystate' => 'Pocatello ID',
        'lon' => -112.44, 'lat' => 42.87,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-114 41, -111 41, -111 44, -114 44, -114 41))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

test('clicking Generate on Create saves the scenario and creates one IngestedProduct', function () {
    $product = Product::where('code', 'AFD')->sole();

    livewire(CreateTestProductScenario::class)
        ->fillForm([
            'product_id' => $product->id,
            'input.wfo' => 'PIH',
            'input.issued_at' => now(),
            'input.body_text' => 'Clear skies expected through the week.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $scenario = TestProductScenario::sole();

    expect($scenario->product_id)->toBe($product->id)
        ->and(IngestedProduct::where('test_product_scenario_id', $scenario->id)->count())->toBe(1)
        ->and(IngestedProduct::sole()->is_test)->toBeTrue();
});

test('clicking Generate on Edit updates the stored input and creates a second IngestedProduct', function () {
    $product = Product::where('code', 'AFD')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => ['mode' => 'new', 'wfo' => 'PIH', 'ugc_codes' => [], 'body_text' => 'Original text.'],
    ]);
    app(GenerateTestProduct::class)($scenario);

    livewire(EditTestProductScenario::class, ['record' => $scenario->id])
        ->fillForm(['input.body_text' => 'Updated text.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($scenario->fresh()->input['body_text'])->toBe('Updated text.')
        ->and($scenario->fresh()->ingestedProducts)->toHaveCount(2);
});

test('a failed generation on Create shows a danger notification instead of the success one', function () {
    $product = Product::where('code', 'AFD')->sole();

    $this->partialMock(GenerateTestProduct::class, function ($mock) {
        $mock->shouldReceive('__invoke')->once()->andReturn(null);
    });

    livewire(CreateTestProductScenario::class)
        ->fillForm([
            'product_id' => $product->id,
            'input.wfo' => 'PIH',
            'input.issued_at' => now(),
            'input.body_text' => 'Clear skies expected through the week.',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Failed to generate the test product');

    expect(IngestedProduct::count())->toBe(0);
});

test('a failed generation on Edit shows a danger notification instead of the success one', function () {
    $product = Product::where('code', 'AFD')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => ['mode' => 'new', 'wfo' => 'PIH', 'ugc_codes' => [], 'body_text' => 'Original text.'],
    ]);

    $this->partialMock(GenerateTestProduct::class, function ($mock) {
        $mock->shouldReceive('__invoke')->once()->andReturn(null);
    });

    livewire(EditTestProductScenario::class, ['record' => $scenario->id])
        ->fillForm(['input.body_text' => 'Updated text.'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Failed to generate the test product');

    expect(IngestedProduct::count())->toBe(0);
});

test('Save on the Review step stores the scenario, generates nothing, and returns to the list', function () {
    $product = Product::where('code', 'AFD')->sole();

    livewire(CreateTestProductScenario::class)
        ->fillForm([
            'product_id' => $product->id,
            'input.wfo' => 'PIH',
            'input.issued_at' => now(),
            'input.body_text' => 'Clear skies.',
        ])
        ->call('saveWithoutGenerating')
        ->assertHasNoFormErrors()
        ->assertRedirect(TestProductScenarioResource::getUrl('index'));

    expect(TestProductScenario::count())->toBe(1)
        ->and(IngestedProduct::where('is_test', true)->count())->toBe(0);
});

test('Save on an existing scenario updates it without generating', function () {
    $scenario = TestProductScenario::factory()->for(Product::where('code', 'AFD')->sole())
        ->create(['input' => ['wfo' => 'PIH', 'issued_at' => now()->toIso8601String(), 'body_text' => 'Clear skies.']]);

    livewire(EditTestProductScenario::class, ['record' => $scenario->id])
        ->fillForm(['name' => 'Renamed only'])
        ->call('saveWithoutGenerating')
        ->assertHasNoFormErrors();

    expect($scenario->fresh()->name)->toBe('Renamed only')
        ->and(IngestedProduct::where('is_test', true)->count())->toBe(0);
});

test('the Review step renders both a Save and a Generate button', function () {
    livewire(CreateTestProductScenario::class)
        ->assertSeeHtml('saveWithoutGenerating')
        ->assertSee('Generate');
});
