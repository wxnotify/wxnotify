<?php

use App\Actions\Pipeline\ReplayIngestedProduct;
use App\Filament\Admin\Resources\IngestedProducts\IngestedProductResource;
use App\Filament\Admin\Resources\IngestedProducts\Pages\ListIngestedProducts;
use App\Filament\Admin\Resources\IngestedProducts\Pages\ViewIngestedProduct;
use App\Filament\Admin\Resources\TestProductScenarios\TestProductScenarioResource;
use App\Models\IngestedProduct;
use App\Models\TestProductScenario;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

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

test('the scenario link shows the scenario name, falling back to "Scenario #id" when unnamed', function () {
    $named = TestProductScenario::factory()->create(['name' => 'Squall line demo']);
    $unnamed = TestProductScenario::factory()->create(['name' => null]);
    $namedProduct = IngestedProduct::factory()->create(['is_test' => true, 'test_product_scenario_id' => $named->id]);
    $unnamedProduct = IngestedProduct::factory()->create(['is_test' => true, 'test_product_scenario_id' => $unnamed->id]);

    livewire(ListIngestedProducts::class)
        ->assertSee('Squall line demo')
        ->assertSee("Scenario #{$unnamed->id}");
    livewire(ViewIngestedProduct::class, ['record' => $namedProduct->id])->assertSee('Squall line demo');
    livewire(ViewIngestedProduct::class, ['record' => $unnamedProduct->id])->assertSee("Scenario #{$unnamed->id}");
});

function replayCopyIds(): array
{
    return IngestedProduct::whereNotNull('replay_of_id')->orderBy('id')->pluck('replay_of_id')->all();
}

test('the replay row action creates a test copy and notifies', function () {
    $product = IngestedProduct::factory()->create(['cccc' => 'ktwc']);

    livewire(ListIngestedProducts::class)
        ->callAction(TestAction::make('replay')->table($product))
        ->assertNotified('Replayed 1 product to test accounts');

    expect(IngestedProduct::where('replay_of_id', $product->id)->where('is_test', true)->count())->toBe(1);
});

test('the replay actions are hidden in production and Filament refuses a forged call', function () {
    $product = IngestedProduct::factory()->create();
    app()->instance('env', 'production');

    livewire(ListIngestedProducts::class)
        ->assertActionHidden(TestAction::make('replay')->table($product))
        ->assertActionHidden(TestAction::make('replay')->table()->bulk());

    expect(ReplayIngestedProduct::allowed())->toBeFalse();

    livewire(ListIngestedProducts::class)
        ->call('mountAction', 'replay', [], ['table' => true, 'recordKey' => (string) $product->id])
        ->call('callMountedAction')
        ->assertNotNotified();

    expect(IngestedProduct::whereNotNull('replay_of_id')->count())->toBe(0);
});

test('the bulk replay action replays the selected products oldest first', function () {
    $newest = IngestedProduct::factory()->create(['received_at' => now()->subHour()]);
    $oldest = IngestedProduct::factory()->create(['received_at' => now()->subDays(3)]);
    $middle = IngestedProduct::factory()->create(['received_at' => now()->subDay()]);
    $untouched = IngestedProduct::factory()->create();

    livewire(ListIngestedProducts::class)
        ->selectTableRecords([$newest->id, $oldest->id, $middle->id])
        ->callAction(TestAction::make('replay')->table()->bulk())
        ->assertNotified('Replayed 3 products to test accounts');

    expect(replayCopyIds())->toBe([$oldest->id, $middle->id, $newest->id])
        ->and(IngestedProduct::where('replay_of_id', $untouched->id)->exists())->toBeFalse();
});

test('the bulk replay action refuses more than 25 products and replays nothing', function () {
    $products = IngestedProduct::factory()->count(26)->create();

    livewire(ListIngestedProducts::class)
        ->selectTableRecords($products->pluck('id')->all())
        ->callAction(TestAction::make('replay')->table()->bulk())
        ->assertNotified('Select 25 or fewer products to replay');

    expect(IngestedProduct::whereNotNull('replay_of_id')->count())->toBe(0);
});

test('one product that cannot be replayed does not stop the rest', function () {
    $first = IngestedProduct::factory()->create(['received_at' => now()->subDays(2)]);
    $broken = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create(['format' => 'cap', 'text' => 'not a cap document', 'received_at' => now()->subDay()]));
    $last = IngestedProduct::factory()->create(['received_at' => now()->subHour()]);

    livewire(ListIngestedProducts::class)
        ->selectTableRecords([$first->id, $broken->id, $last->id])
        ->callAction(TestAction::make('replay')->table()->bulk())
        ->assertNotified('Replayed 2 products; 1 could not be replayed');

    expect(replayCopyIds())->toBe([$first->id, $last->id]);
});

test('a replay copy links back to its original on the view page and an ordinary product does not', function () {
    $original = IngestedProduct::factory()->create();
    $copy = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create(['is_test' => true, 'replay_of_id' => $original->id]));

    livewire(ViewIngestedProduct::class, ['record' => $copy->id])
        ->assertSee('Replay of')
        ->assertSee("Product #{$original->id}");

    livewire(ViewIngestedProduct::class, ['record' => $original->id])
        ->assertDontSee('Replay of');
});

function paneProduct(array $overrides = []): IngestedProduct
{
    return IngestedProduct::factory()->create([
        'cccc' => 'kbou', 'ttaaii' => 'wfus53', 'awipsid' => 'torbou', 'wmo_ddhhmm' => '191622', 'source' => 'nwws',
        'text' => "WFUS53 KBOU 191622\nTORBOU\nTornado Warning for Boulder County",
        ...$overrides,
    ]);
}

test('the content pane shows a placeholder until a product is selected', function () {
    paneProduct();

    livewire(ListIngestedProducts::class)
        ->assertSet('selectedProductId', null)
        ->assertSee('Select a product below to view its contents');
});

test('clicking a row shows that product text and details in the pane', function () {
    $product = paneProduct();
    $other = paneProduct(['awipsid' => 'svrbou', 'text' => "WUUS53 KBOU 191700\nSVRBOU\nSevere Thunderstorm Warning"]);

    livewire(ListIngestedProducts::class)
        ->callAction(TestAction::make('select')->table($product))
        ->assertSet('selectedProductId', $product->id)
        ->assertDontSee('Select a product below to view its contents')
        ->assertSee('Tornado Warning for Boulder County')
        ->assertSee('KBOU')
        ->assertSee('WFUS53')
        ->assertSee('TORBOU')
        ->assertDontSee('Severe Thunderstorm Warning')
        ->callAction(TestAction::make('select')->table($other))
        ->assertSee('Severe Thunderstorm Warning')
        ->assertDontSee('Tornado Warning for Boulder County');
});

test('the selected product is preselected from the product query parameter', function () {
    $product = paneProduct();

    Livewire::withQueryParams(['product' => $product->id])
        ->test(ListIngestedProducts::class)
        ->assertSee('Tornado Warning for Boulder County');
});

test('an unknown or malformed product query parameter falls back to the placeholder', function (mixed $value) {
    paneProduct();

    Livewire::withQueryParams(['product' => $value])
        ->test(ListIngestedProducts::class)
        ->assertSee('Select a product below to view its contents');
})->with(['unknown id' => 999999, 'not a number' => 'abc', 'injection' => '1 OR 1=1']);

test('product text is escaped in the pane', function () {
    $product = paneProduct(['text' => "Line one\n<script>alert(1)</script> & more"]);

    livewire(ListIngestedProducts::class)
        ->callAction(TestAction::make('select')->table($product))
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt; &amp; more')
        ->assertDontSeeHtml('<script>alert(1)</script>');
});

test('the pane links to the full page and to the original of a replay copy', function () {
    $original = paneProduct();
    $copy = IngestedProduct::withoutEvents(fn () => paneProduct(['is_test' => true, 'replay_of_id' => $original->id]));

    livewire(ListIngestedProducts::class)
        ->callAction(TestAction::make('select')->table($copy))
        ->assertSee('Open full page')
        ->assertSeeHtml(IngestedProductResource::getUrl('view', ['record' => $copy]))
        ->assertSee('Replay of')
        ->assertSeeHtml(IngestedProductResource::getUrl('view', ['record' => $original]));
});

test('the selected product stays in the pane when the table is filtered', function () {
    $real = paneProduct();
    paneProduct(['is_test' => true, 'awipsid' => 'svrbou']);

    livewire(ListIngestedProducts::class)
        ->callAction(TestAction::make('select')->table($real))
        ->filterTable('is_test', true)
        ->assertCanNotSeeTableRecords([$real])
        ->assertSee('Tornado Warning for Boulder County');
});

test('rows no longer have a view button and replay is still there', function () {
    $product = paneProduct();

    livewire(ListIngestedProducts::class)
        ->assertTableActionDoesNotExist('view')
        ->assertActionExists(TestAction::make('replay')->table($product))
        ->assertActionExists(TestAction::make('select')->table($product));
});
