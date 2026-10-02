<?php

use App\Enums\UnitSystem;
use App\Filament\Dashboard\Pages\Auth\EditProfile;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    // fresh(): the profile form fills from the loaded attributes, and a just-created
    // model does not carry the column's database default until it is re-read.
    $this->user = User::factory()->create()->fresh();
    $this->actingAs($this->user);
});

test('the profile form offers a Units choice that defaults to Imperial', function () {
    livewire(EditProfile::class)
        ->assertFormFieldExists('unit_system')
        ->assertFormSet(['unit_system' => 'imperial']);
});

test('a subscriber can switch to Metric and it persists', function () {
    livewire(EditProfile::class)
        ->fillForm(['unit_system' => 'metric'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->user->fresh()->unit_system)->toBe(UnitSystem::Metric);
});

test('an unknown unit system is rejected', function () {
    livewire(EditProfile::class)
        ->fillForm(['unit_system' => 'furlongs'])
        ->call('save')
        ->assertHasFormErrors(['unit_system']);

    expect($this->user->fresh()->unit_system)->toBe(UnitSystem::Imperial);
});
