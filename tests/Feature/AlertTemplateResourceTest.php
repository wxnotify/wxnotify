<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Resources\AlertTemplates\AlertTemplateResource;
use App\Filament\Dashboard\Resources\AlertTemplates\Pages\CreateAlertTemplate;
use App\Filament\Dashboard\Resources\AlertTemplates\Pages\EditAlertTemplate;
use App\Filament\Dashboard\Resources\AlertTemplates\Pages\ListAlertTemplates;
use App\Models\AlertTemplate;
use App\Models\User;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($this->user);
});

test('a Plus/Pro subscriber can create a weather_alerts template with sections', function () {
    livewire(CreateAlertTemplate::class)
        ->fillForm([
            'name' => 'Detailed Severe Weather',
            'category' => 'weather_alerts',
            'subject_template' => '{product} for {location}',
            'sections' => ['hazard', 'impact'],
        ])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors();

    assertDatabaseHas(AlertTemplate::class, [
        'user_id' => $this->user->id,
        'name' => 'Detailed Severe Weather',
        'category' => 'weather_alerts',
    ]);

    expect(AlertTemplate::sole()->sections)->toBe(['hazard', 'impact']);
});

test('a Plus/Pro subscriber can create an spc_wpc template with no sections field involved', function () {
    livewire(CreateAlertTemplate::class)
        ->fillForm([
            'name' => 'Outlook Subject Only',
            'category' => 'spc_wpc',
            'subject_template' => 'SPC: {product}',
        ])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors();

    assertDatabaseHas(AlertTemplate::class, [
        'name' => 'Outlook Subject Only',
        'category' => 'spc_wpc',
    ]);
});

test('editing an existing template persists changes', function () {
    $template = AlertTemplate::factory()->for($this->user)->create(['name' => 'Old name']);

    livewire(EditAlertTemplate::class, ['record' => $template->id])
        ->fillForm(['name' => 'New name'])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    assertDatabaseHas(AlertTemplate::class, ['id' => $template->id, 'name' => 'New name']);
});

test('the list only shows the current subscriber\'s own templates', function () {
    $mine = AlertTemplate::factory()->for($this->user)->create();
    $someoneElses = AlertTemplate::factory()->create();

    livewire(ListAlertTemplates::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$someoneElses]);
});

test('the list page explains what it\'s for', function () {
    livewire(ListAlertTemplates::class)
        ->assertSee('Reusable message templates');
});

test('a Free-tier subscriber cannot access the resource at all', function () {
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Free]));

    expect(AlertTemplateResource::canAccess())->toBeFalse();
});
