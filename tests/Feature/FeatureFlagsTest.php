<?php

use App\Enums\Feature;
use App\Filament\Admin\Pages\AppSettings;
use App\Models\User;
use App\Settings\FeatureFlagsSettings;
use App\Support\FeatureFlags;
use Filament\Facades\Filament;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

function setFeatureFlag(string $property, bool $value): void
{
    $settings = app(FeatureFlagsSettings::class);
    $settings->{$property} = $value;
    $settings->save();
}

test('the Heads Up flag defaults to enabled', function () {
    expect(app(FeatureFlagsSettings::class)->heads_up_enabled)->toBeTrue()
        ->and(FeatureFlags::enabled(Feature::HeadsUp))->toBeTrue();
});

test('the helper follows the saved setting in both directions', function () {
    setFeatureFlag('heads_up_enabled', false);

    expect(FeatureFlags::enabled(Feature::HeadsUp))->toBeFalse()
        ->and(FeatureFlags::disabled(Feature::HeadsUp))->toBeTrue();

    setFeatureFlag('heads_up_enabled', true);

    expect(FeatureFlags::enabled(Feature::HeadsUp))->toBeTrue()
        ->and(FeatureFlags::disabled(Feature::HeadsUp))->toBeFalse();
});

test('every feature maps to a real property on the settings class', function () {
    $properties = array_keys(app(FeatureFlagsSettings::class)->toArray());

    foreach (Feature::cases() as $feature) {
        expect($properties)->toContain($feature->settingsProperty());
    }
});

test('the App settings page loads and saves the flag', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['is_staff' => true]));

    livewire(AppSettings::class)->assertFormSet(['heads_up_enabled' => true]);

    livewire(AppSettings::class)
        ->fillForm(['heads_up_enabled' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(FeatureFlagsSettings::class)->refresh()->heads_up_enabled)->toBeFalse();
});

test('flipping a flag is written to the audit log with old and new values', function () {
    $staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($staff);

    setFeatureFlag('heads_up_enabled', false);

    $activity = Activity::query()->where('log_name', 'settings')->sole();

    expect($activity->description)->toBe('Updated feature_flags settings')
        ->and($activity->causer_id)->toBe($staff->id)
        ->and($activity->properties['changes'])->toBe(['heads_up_enabled' => ['old' => true, 'new' => false]]);
});
