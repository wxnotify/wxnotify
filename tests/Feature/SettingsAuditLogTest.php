<?php

use App\Filament\Admin\Pages\AppSettings;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\SecretSettings;
use App\Settings\TierThresholdSettings;
use Filament\Facades\Filament;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

test('changing a setting logs the old and new value against the acting user', function () {
    $staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($staff);

    $settings = app(TierThresholdSettings::class);
    $settings->plus_locations = 8;
    $settings->save();

    $activity = Activity::query()->where('log_name', 'settings')->sole();

    expect($activity->description)->toBe('Updated tier_threshold settings')
        ->and($activity->causer_id)->toBe($staff->id)
        ->and($activity->properties['group'])->toBe('tier_threshold')
        ->and($activity->properties['changes'])->toBe(['plus_locations' => ['old' => 5, 'new' => 8]]);
});

test('saving without changing anything logs nothing', function () {
    $settings = app(GeneralSettings::class);
    $settings->save();

    expect(Activity::query()->where('log_name', 'settings')->count())->toBe(0);
});

test('only the changed properties are logged', function () {
    $settings = app(GeneralSettings::class);
    $settings->maintenance_mode = true;
    $settings->save();

    $changes = Activity::query()->where('log_name', 'settings')->sole()->properties['changes'];

    expect(array_keys($changes))->toBe(['maintenance_mode'])
        ->and($changes['maintenance_mode'])->toBe(['old' => false, 'new' => true]);
});

test('saving through the App settings page logs the change', function () {
    Filament::setCurrentPanel('admin');
    $staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($staff);

    livewire(AppSettings::class)
        ->fillForm(['plus_locations' => 9])
        ->call('save')
        ->assertHasNoFormErrors();

    $activity = Activity::query()->where('log_name', 'settings')->where('description', 'Updated tier_threshold settings')->sole();

    expect($activity->causer_id)->toBe($staff->id)
        ->and($activity->properties['changes']['plus_locations'])->toBe(['old' => 5, 'new' => 9]);
});

test('a secret being set logs markers, never the value', function () {
    $staff = User::factory()->create(['is_staff' => true, 'is_owner' => true]);
    $this->actingAs($staff);

    $settings = app(SecretSettings::class);
    $settings->stripe_secret_key = 'sk_test_FAKE_1234';
    $settings->save();

    $activity = Activity::query()->where('log_name', 'settings')->sole();

    expect($activity->description)->toBe('Updated secrets settings')
        ->and($activity->causer_id)->toBe($staff->id)
        ->and($activity->properties['changes'])->toBe(['stripe_secret_key' => ['old' => '[not set]', 'new' => '[set]']]);
});

test('rotating and clearing a secret log markers and no value ever reaches the activity log', function () {
    $settings = app(SecretSettings::class);
    $settings->stripe_secret_key = 'sk_test_FAKE_1234';
    $settings->save();

    $settings->stripe_secret_key = 'sk_test_FAKE_5678';
    $settings->save();

    $settings->stripe_secret_key = null;
    $settings->save();

    $changes = Activity::query()->where('log_name', 'settings')->orderBy('id')->get()
        ->map(fn ($activity) => $activity->properties['changes']['stripe_secret_key'])
        ->all();

    expect($changes)->toBe([
        ['old' => '[not set]', 'new' => '[set]'],
        ['old' => '[set]', 'new' => '[set]'],
        ['old' => '[set]', 'new' => '[not set]'],
    ]);

    $everything = json_encode(Activity::query()->get()->toArray());

    expect($everything)->not->toContain('sk_test_FAKE_1234')->not->toContain('sk_test_FAKE_5678');
});

test('an unchanged secret save logs nothing and non-encrypted settings still log their values', function () {
    app(SecretSettings::class)->save();

    expect(Activity::query()->where('log_name', 'settings')->count())->toBe(0);

    $general = app(GeneralSettings::class);
    $general->maintenance_mode = true;
    $general->save();

    expect(Activity::query()->where('log_name', 'settings')->sole()->properties['changes'])
        ->toBe(['maintenance_mode' => ['old' => false, 'new' => true]]);
});
