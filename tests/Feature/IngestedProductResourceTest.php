<?php

use App\Filament\Admin\Resources\IngestedProducts\Pages\ListIngestedProducts;
use App\Filament\Admin\Resources\IngestedProducts\Pages\ViewIngestedProduct;
use App\Filament\Admin\Resources\TestProductScenarios\TestProductScenarioResource;
use App\Models\IngestedProduct;
use App\Models\TestProductScenario;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
});

test('staff can list ingested products', function () {
    $product = IngestedProduct::factory()->create(['cccc' => 'kkci']);

    livewire(ListIngestedProducts::class)
        ->assertCanSeeTableRecords([$product]);
});

test('the cccc filter narrows results to the selected office', function () {
    $kkci = IngestedProduct::factory()->create(['cccc' => 'kkci']);
    $ktae = IngestedProduct::factory()->create(['cccc' => 'ktae']);

    livewire(ListIngestedProducts::class)
        ->filterTable('cccc', 'kkci')
        ->assertCanSeeTableRecords([$kkci])
        ->assertCanNotSeeTableRecords([$ktae]);
});

test('the received_at range filter narrows results to the selected window', function () {
    $inRange = IngestedProduct::factory()->create(['received_at' => now()->subDays(2)]);
    $outOfRange = IngestedProduct::factory()->create(['received_at' => now()->subDays(10)]);

    livewire(ListIngestedProducts::class)
        ->filterTable('received_at', ['from' => now()->subDays(5)->toDateString()])
        ->assertCanSeeTableRecords([$inRange])
        ->assertCanNotSeeTableRecords([$outOfRange]);
});

test('there are no create, edit, or delete actions', function () {
    IngestedProduct::factory()->create();

    livewire(ListIngestedProducts::class)
        ->assertActionDoesNotExist('create')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');
});

test('the is_test filter isolates synthetic test runs from real archived products', function () {
    $real = IngestedProduct::factory()->create(['is_test' => false]);
    $test = IngestedProduct::factory()->create(['is_test' => true]);

    livewire(ListIngestedProducts::class)
        ->filterTable('is_test', true)
        ->assertCanSeeTableRecords([$test])
        ->assertCanNotSeeTableRecords([$real])
        ->filterTable('is_test', false)
        ->assertCanSeeTableRecords([$real])
        ->assertCanNotSeeTableRecords([$test]);
});

test('the list page eager-loads scenarios instead of querying once per row', function () {
    IngestedProduct::factory()->count(5)->create([
        'is_test' => true,
        'test_product_scenario_id' => fn () => TestProductScenario::factory()->create()->id,
    ]);

    DB::enableQueryLog();
    livewire(ListIngestedProducts::class);
    $scenarioQueries = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains($q['query'], 'from "test_product_scenarios"'))
        ->count();
    DB::disableQueryLog();

    expect($scenarioQueries)->toBeLessThanOrEqual(1);
});

test('a test product links to its originating scenario from the list and view pages', function () {
    $scenario = TestProductScenario::factory()->create();
    $product = IngestedProduct::factory()->create(['is_test' => true, 'test_product_scenario_id' => $scenario->id]);
    $href = TestProductScenarioResource::getUrl('edit', ['record' => $scenario->id]);

    livewire(ListIngestedProducts::class)->assertSeeHtml('href="'.$href.'"');
    livewire(ViewIngestedProduct::class, ['record' => $product->id])->assertSeeHtml('href="'.$href.'"');
});

test('a real product shows no scenario link', function () {
    $product = IngestedProduct::factory()->create(['is_test' => false]);
    $prefix = TestProductScenarioResource::getUrl('index');

    livewire(ListIngestedProducts::class)->assertDontSeeHtml('href="'.$prefix);
    livewire(ViewIngestedProduct::class, ['record' => $product->id])->assertDontSeeHtml('href="'.$prefix);
});

test('a test product whose scenario was deleted shows no link and does not error', function () {
    $scenario = TestProductScenario::factory()->create();
    $product = IngestedProduct::factory()->create(['is_test' => true, 'test_product_scenario_id' => $scenario->id]);
    $scenario->delete();
    $prefix = TestProductScenarioResource::getUrl('index');

    expect($product->fresh()->test_product_scenario_id)->toBeNull();

    livewire(ListIngestedProducts::class)->assertSuccessful()->assertDontSeeHtml('href="'.$prefix);
    livewire(ViewIngestedProduct::class, ['record' => $product->id])->assertSuccessful()->assertDontSeeHtml('href="'.$prefix);
});

test('no scenario link is rendered when the scenario resource is inaccessible', function () {
    $scenario = TestProductScenario::factory()->create();
    $product = IngestedProduct::factory()->create(['is_test' => true, 'test_product_scenario_id' => $scenario->id]);
    $prefix = TestProductScenarioResource::getUrl('index');

    app()->instance('env', 'production');

    livewire(ListIngestedProducts::class)->assertDontSeeHtml('href="'.$prefix);
    livewire(ViewIngestedProduct::class, ['record' => $product->id])->assertDontSeeHtml('href="'.$prefix);
});
