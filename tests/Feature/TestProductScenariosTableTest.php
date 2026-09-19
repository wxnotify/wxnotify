<?php

use App\Actions\Testing\GenerateTestProduct;
use App\Filament\Admin\Resources\TestProductScenarios\Pages\ListTestProductScenarios;
use App\Models\IngestedProduct;
use App\Models\Product;
use App\Models\TestProductScenario;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
    Filament::setCurrentPanel('admin');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('send again re-runs Generate against the scenario unchanged', function () {
    $product = Product::where('code', 'AFD')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => ['mode' => 'new', 'wfo' => 'PIH', 'ugc_codes' => [], 'body_text' => 'Original text.'],
    ]);

    livewire(ListTestProductScenarios::class)
        ->callAction(TestAction::make('send_again')->table($scenario))
        ->assertNotified('Test product generated');

    expect($scenario->fresh()->ingestedProducts)->toHaveCount(1)
        ->and($scenario->fresh()->input)->toBe($scenario->input);
});

test('send again renders with a fresh issued_at without persisting it', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-15 12:34:00'));

    $product = Product::where('code', 'AFD')->sole();
    $staleIssuedAt = now()->subDays(3)->toIso8601String();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => [
            'mode' => 'new', 'wfo' => 'PIH', 'ugc_codes' => [], 'body_text' => 'Original text.',
            'issued_at' => $staleIssuedAt,
        ],
    ]);

    livewire(ListTestProductScenarios::class)
        ->callAction(TestAction::make('send_again')->table($scenario))
        ->assertNotified('Test product generated');

    $text = $scenario->fresh()->ingestedProducts->sole()->text;

    expect($text)->toContain('KPIH 151234')
        ->not->toContain('121234')
        ->and($scenario->fresh()->input['issued_at'])->toBe($staleIssuedAt);
});

test('send again does not add issued_at to a stored scenario that had none', function () {
    $product = Product::where('code', 'AFD')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => ['mode' => 'new', 'wfo' => 'PIH', 'ugc_codes' => [], 'body_text' => 'Original text.'],
    ]);

    livewire(ListTestProductScenarios::class)
        ->callAction(TestAction::make('send_again')->table($scenario))
        ->assertNotified('Test product generated');

    expect($scenario->fresh()->input)->not->toHaveKey('issued_at');
});

test('a failed send again shows a danger notification instead of a false success one', function () {
    $product = Product::where('code', 'AFD')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => ['mode' => 'new', 'wfo' => 'PIH', 'ugc_codes' => [], 'body_text' => 'Original text.'],
    ]);

    $this->partialMock(GenerateTestProduct::class, function ($mock) {
        $mock->shouldReceive('__invoke')->once()->andReturn(null);
    });

    livewire(ListTestProductScenarios::class)
        ->callAction(TestAction::make('send_again')->table($scenario))
        ->assertNotified('Failed to generate the test product');
});

test('duplicate clones a scenario into a new, unsent copy with a (copy) name suffix', function () {
    $product = Product::where('code', 'AFD')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'name' => 'Original',
        'input' => ['mode' => 'new', 'wfo' => 'PIH', 'ugc_codes' => [], 'body_text' => 'Some text.'],
    ]);
    app(GenerateTestProduct::class)($scenario);

    livewire(ListTestProductScenarios::class)
        ->callAction(TestAction::make('duplicate')->table($scenario))
        ->assertNotified('Scenario duplicated');

    expect(TestProductScenario::count())->toBe(2);
    $copy = TestProductScenario::where('id', '!=', $scenario->id)->sole();
    expect($copy->input)->toBe($scenario->input)
        ->and($copy->product_id)->toBe($product->id)
        ->and($copy->name)->toBe('Original (copy)')
        ->and($copy->ingestedProducts)->toHaveCount(0);
});

test('duplicate keeps a null name null rather than suffixing it', function () {
    $product = Product::where('code', 'AFD')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create(['name' => null]);

    livewire(ListTestProductScenarios::class)
        ->callAction(TestAction::make('duplicate')->table($scenario));

    $copy = TestProductScenario::where('id', '!=', $scenario->id)->sole();
    expect($copy->name)->toBeNull();
});

test('deleting a scenario keeps the products it spawned and nulls their scenario link', function () {
    $product = Product::where('code', 'AFD')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => ['mode' => 'new', 'wfo' => 'PIH', 'ugc_codes' => [], 'body_text' => 'Original text.'],
    ]);

    livewire(ListTestProductScenarios::class)
        ->callAction(TestAction::make('send_again')->table($scenario))
        ->callAction(TestAction::make('delete')->table($scenario));

    expect(TestProductScenario::count())->toBe(0)
        ->and(IngestedProduct::count())->toBe(1)
        ->and(IngestedProduct::sole()->test_product_scenario_id)->toBeNull();
});

test('the Times sent column reflects how many products a scenario has spawned', function () {
    $product = Product::where('code', 'AFD')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => ['mode' => 'new', 'wfo' => 'PIH', 'ugc_codes' => [], 'body_text' => 'Original text.'],
    ]);

    livewire(ListTestProductScenarios::class)
        ->assertTableColumnStateSet('ingested_products_count', 0, $scenario)
        ->callAction(TestAction::make('send_again')->table($scenario))
        ->assertTableColumnStateSet('ingested_products_count', 1, $scenario);
});

test('send again is hidden for a scenario whose product was deleted', function () {
    $scenario = TestProductScenario::factory()->create();
    $scenario->product->delete();

    livewire(ListTestProductScenarios::class)
        ->assertActionHidden(TestAction::make('send_again')->table($scenario->fresh()));
});

test('send again warns when the pipeline ingested the product but could not resolve it to a catalog product', function () {
    $product = Product::where('code', 'SPS')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => ['wfo' => 'TOP', 'ugc_codes' => ['KSZ038'], 'body_text' => 'Strong thunderstorms are moving through the area.'],
    ]);

    livewire(ListTestProductScenarios::class)
        ->callAction(TestAction::make('send_again')->table($scenario))
        ->assertNotified('Test product generated, but not recognized');

    expect(IngestedProduct::sole()->product_id)->toBeNull();
});
