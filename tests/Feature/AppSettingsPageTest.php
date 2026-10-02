<?php

use App\Filament\Admin\Pages\AppSettings;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\TierThresholdSettings;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['is_staff' => true]));
});

test('the page loads the current values of both settings groups', function () {
    livewire(AppSettings::class)
        ->assertFormSet([
            'maintenance_mode' => false,
            'plus_locations' => 5,
            'lightning_min_radius_miles' => 10,
            'message_prefix_enabled' => false,
            'message_prefix' => '',
        ]);
});

test('the message prefix field is hidden until the toggle is turned on', function () {
    $component = livewire(AppSettings::class);

    $component->assertFormFieldHidden('message_prefix');

    $component->set('data.message_prefix_enabled', true);

    $component->assertFormFieldVisible('message_prefix');
});

test('saving the message prefix persists both fields', function () {
    livewire(AppSettings::class)
        ->fillForm([
            'message_prefix_enabled' => true,
            'message_prefix' => '[STAGING]',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(app(GeneralSettings::class)->refresh()->message_prefix_enabled)->toBeTrue()
        ->and(app(GeneralSettings::class)->message_prefix)->toBe('[STAGING]');
});

test('turning the prefix on with no text is a form error', function () {
    livewire(AppSettings::class)
        ->fillForm([
            'message_prefix_enabled' => true,
            'message_prefix' => '',
        ])
        ->call('save')
        ->assertHasFormErrors(['message_prefix']);
});

test('saving writes both groups', function () {
    livewire(AppSettings::class)
        ->fillForm([
            'maintenance_mode' => true,
            'maintenance_message' => 'Back at 10:00 UTC.',
            'plus_locations' => 8,
            'lightning_min_radius_miles' => 12,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(app(GeneralSettings::class)->refresh()->maintenance_mode)->toBeTrue()
        ->and(app(GeneralSettings::class)->maintenance_message)->toBe('Back at 10:00 UTC.')
        ->and(app(TierThresholdSettings::class)->refresh()->plus_locations)->toBe(8)
        ->and(app(TierThresholdSettings::class)->lightning_min_radius_miles)->toBe(12);
});

test('an empty maintenance message is stored as null', function () {
    livewire(AppSettings::class)
        ->fillForm(['maintenance_message' => 'Temp'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(GeneralSettings::class)->refresh()->maintenance_message)->toBe('Temp');

    livewire(AppSettings::class)
        ->fillForm(['maintenance_message' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(GeneralSettings::class)->refresh()->maintenance_message)->toBeNull();
});

test('out-of-range and non-integer values are rejected and nothing is saved', function (string $field, mixed $value) {
    $original = app(TierThresholdSettings::class)->refresh()->{$field};

    livewire(AppSettings::class)
        ->fillForm([$field => $value])
        ->call('save')
        ->assertHasFormErrors([$field]);

    expect(app(TierThresholdSettings::class)->refresh()->{$field})->toBe($original);
})->with([
    'lightning floor below range' => ['lightning_min_radius_miles', 4],
    'lightning floor above range' => ['lightning_min_radius_miles', 16],
    'free locations below one' => ['free_locations', 0],
    'plus locations above cap' => ['plus_locations', 101],
    'negative channel slots' => ['free_no_cost_channels', -1],
    'device limit above cap' => ['pro_traccar_devices_per_server', 101],
    'fractional value' => ['plus_traccar_servers', 1.5],
]);

test('a higher tier cannot be set below a lower tier', function () {
    livewire(AppSettings::class)
        ->fillForm(['free_locations' => 6, 'plus_locations' => 5])
        ->call('save')
        ->assertHasFormErrors(['plus_locations']);

    livewire(AppSettings::class)
        ->fillForm(['free_traccar_servers' => 2, 'plus_traccar_servers' => 1])
        ->call('save')
        ->assertHasFormErrors(['plus_traccar_servers']);

    livewire(AppSettings::class)
        ->fillForm(['plus_traccar_devices_per_server' => 4, 'pro_traccar_devices_per_server' => 3])
        ->call('save')
        ->assertHasFormErrors(['pro_traccar_devices_per_server']);
});

test('only staff can open the page', function () {
    $this->actingAs(User::factory()->create(['is_staff' => false]));

    expect(AppSettings::canAccess())->toBeFalse();

    livewire(AppSettings::class)->assertForbidden();
});
