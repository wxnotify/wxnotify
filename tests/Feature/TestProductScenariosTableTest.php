<?php

use App\Actions\Testing\GenerateTestProduct;
use App\Filament\Admin\Resources\TestProductScenarios\Pages\ListTestProductScenarios;
use App\Models\IngestedProduct;
use App\Models\Product;
use App\Models\TestProductScenario;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
    Filament::setCurrentPanel('admin');
});

test('send again re-runs Generate against the scenario unchanged', function () {
    $product = Product::where('code', 'AFD')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => ['mode' => 'new', 'wfo' => 'PIH', 'ugc_codes' => [], 'body_text' => 'Original text.'],
    ]);

    livewire(ListTestProductScenarios::class)
        ->callAction(TestAction::make('send_again')->table($scenario))
        ->assertNotified();

    expect($scenario->fresh()->ingestedProducts)->toHaveCount(1)
        ->and($scenario->fresh()->input['body_text'])->toBe('Original text.');
});

test('duplicate clones a scenario into a new, unsent copy', function () {
    $product = Product::where('code', 'AFD')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create([
        'name' => 'Original',
        'input' => ['mode' => 'new', 'wfo' => 'PIH', 'ugc_codes' => [], 'body_text' => 'Some text.'],
    ]);

    livewire(ListTestProductScenarios::class)
        ->callAction(TestAction::make('duplicate')->table($scenario))
        ->assertNotified();

    expect(TestProductScenario::count())->toBe(2);
    $copy = TestProductScenario::where('id', '!=', $scenario->id)->sole();
    expect($copy->input)->toBe($scenario->input)
        ->and($copy->ingestedProducts)->toHaveCount(0);
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

    expect(IngestedProduct::count())->toBe(0);
});

test('delete removes the scenario', function () {
    $product = Product::where('code', 'AFD')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create();

    livewire(ListTestProductScenarios::class)
        ->callAction(TestAction::make('delete')->table($scenario));

    expect(TestProductScenario::count())->toBe(0);
});
