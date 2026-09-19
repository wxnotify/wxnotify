<?php

use App\Filament\Admin\Resources\TestProductScenarios\Pages\CreateTestProductScenario;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
    Filament::setCurrentPanel('admin');
});

test('damage tag checkboxes only appear for a product with damage_tag_options configured', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    livewire(CreateTestProductScenario::class)
        ->fillForm(['product_id' => $tornadoWarning->id])
        ->assertFormFieldVisible('input.damage_tags');
});

test('damage tag checkboxes are absent for a product with no damage_tag_options', function () {
    $flashFloodWarning = Product::where('code', 'FF.W')->sole();

    livewire(CreateTestProductScenario::class)
        ->fillForm(['product_id' => $flashFloodWarning->id])
        ->assertFormFieldHidden('input.damage_tags');
});

test('warning-shaped narrative fields only appear for a has_vtec product', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $forecastDiscussion = Product::where('code', 'AFD')->sole();

    livewire(CreateTestProductScenario::class)
        ->set('data.product_id', $tornadoWarning->id)
        ->assertFormFieldVisible('input.hazard')
        ->assertFormFieldVisible('input.hazard_source')
        ->assertFormFieldVisible('input.impact')
        ->assertFormFieldVisible('input.locations_impacted')
        ->assertFormFieldVisible('input.call_to_action')
        ->set('data.product_id', $forecastDiscussion->id)
        ->assertFormFieldHidden('input.hazard')
        ->assertFormFieldHidden('input.hazard_source')
        ->assertFormFieldHidden('input.impact')
        ->assertFormFieldHidden('input.locations_impacted')
        ->assertFormFieldHidden('input.call_to_action');
});

test('the discussion text field only appears for an spc_wpc-family product', function () {
    $mesoscaleDiscussion = Product::where('code', 'SPC.MD')->sole();
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    livewire(CreateTestProductScenario::class)
        ->set('data.product_id', $mesoscaleDiscussion->id)
        ->assertFormFieldVisible('input.discussion_text')
        ->set('data.product_id', $tornadoWarning->id)
        ->assertFormFieldHidden('input.discussion_text');
});

test('the body text field only appears for a plain-text-shaped product', function () {
    $forecastDiscussion = Product::where('code', 'AFD')->sole();
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    livewire(CreateTestProductScenario::class)
        ->set('data.product_id', $forecastDiscussion->id)
        ->assertFormFieldVisible('input.body_text')
        ->set('data.product_id', $tornadoWarning->id)
        ->assertFormFieldHidden('input.body_text');
});

test('motion vector fields only appear for a has_vtec product', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $forecastDiscussion = Product::where('code', 'AFD')->sole();

    livewire(CreateTestProductScenario::class)
        ->set('data.product_id', $tornadoWarning->id)
        ->assertFormFieldVisible('input.motion_direction_degrees')
        ->assertFormFieldVisible('input.motion_speed_kt')
        ->set('data.product_id', $forecastDiscussion->id)
        ->assertFormFieldHidden('input.motion_direction_degrees')
        ->assertFormFieldHidden('input.motion_speed_kt');
});
