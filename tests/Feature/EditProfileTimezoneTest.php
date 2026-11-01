<?php

use App\Filament\Dashboard\Pages\Auth\EditProfile;
use App\Models\User;
use App\Support\Timezones;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->user = User::factory()->create()->fresh();
    $this->actingAs($this->user);
});

test('the profile form offers an optional Timezone field that starts empty', function () {
    livewire(EditProfile::class)
        ->assertFormFieldExists('timezone')
        ->assertFormSet(['timezone' => null]);
});

test('a subscriber can save a timezone and clear it again', function () {
    livewire(EditProfile::class)
        ->fillForm(['timezone' => 'America/Denver'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->user->fresh()->timezone)->toBe('America/Denver');

    livewire(EditProfile::class)
        ->fillForm(['timezone' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->user->fresh()->timezone)->toBeNull();
});

test('an unknown timezone is treated as unset rather than saved', function () {
    livewire(EditProfile::class)
        ->fillForm(['timezone' => 'Mars/Olympus_Mons'])
        ->assertFormSet(['timezone' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->user->fresh()->timezone)->toBeNull();
});

test('saving the profile without touching the timezone leaves it unchanged', function () {
    $this->user->update(['timezone' => 'America/Chicago']);

    livewire(EditProfile::class)
        ->fillForm(['name' => 'New Name'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->user->fresh())->name->toBe('New Name')->timezone->toBe('America/Chicago');
});

test('a browser-detected legacy alias is normalised or cleared so an unrelated save still works', function () {
    livewire(EditProfile::class)
        ->set('data.timezone', 'America/Indianapolis')
        ->fillForm(['name' => 'Password Changer'])
        ->call('save')
        ->assertHasNoFormErrors();

    $stored = $this->user->fresh()->timezone;

    expect($stored)->not->toBe('America/Indianapolis')
        ->and($stored === null || Timezones::isValid($stored))->toBeTrue();
});

const TIMEZONE_NOT_SAVED_NOTE = 'Detected from your browser, not saved yet. Click Save changes to use it for your alerts.';

test('a timezone the browser filled in is flagged as not saved yet', function () {
    livewire(EditProfile::class)
        ->fillForm(['timezone' => 'America/Denver'])
        ->assertSee(TIMEZONE_NOT_SAVED_NOTE);
});

test('the not-saved note goes away once the timezone has been saved', function () {
    livewire(EditProfile::class)
        ->fillForm(['timezone' => 'America/Denver'])
        ->assertSee(TIMEZONE_NOT_SAVED_NOTE)
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertDontSee(TIMEZONE_NOT_SAVED_NOTE);

    expect($this->user->fresh()->timezone)->toBe('America/Denver');
});

test('no not-saved note is shown when a timezone is already saved', function () {
    $this->user->update(['timezone' => 'America/Chicago']);

    livewire(EditProfile::class)
        ->assertDontSee(TIMEZONE_NOT_SAVED_NOTE)
        ->fillForm(['timezone' => 'America/Denver'])
        ->assertDontSee(TIMEZONE_NOT_SAVED_NOTE);
});

test('no not-saved note is shown while the timezone field is empty', function () {
    livewire(EditProfile::class)
        ->assertDontSee(TIMEZONE_NOT_SAVED_NOTE);
});
