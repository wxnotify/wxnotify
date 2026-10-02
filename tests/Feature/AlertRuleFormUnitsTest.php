<?php

use App\Filament\Dashboard\Resources\AlertRules\Pages\CreateAlertRule;
use App\Filament\Dashboard\Resources\AlertRules\Pages\EditAlertRule;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Settings\TierThresholdSettings;
use Database\Seeders\ProductCatalogSeeder;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->seed(ProductCatalogSeeder::class);

    $this->user = User::factory()->create(['unit_system' => 'metric']);
    $this->actingAs($this->user);
    $this->location = Location::factory()->for($this->user)->create(['label' => 'Home']);
    $this->channel = NotificationChannel::factory()->for($this->user)->create();
});

test('a metric subscriber creates a threshold in celsius and it is stored in fahrenheit', function () {
    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'threshold',
            'variable' => 'temperature',
            'direction' => 'below',
            'threshold_value' => 0,
            'timeframe' => 'overnight',
            'location_id' => $this->location->id,
            'delivery_time' => '06:00',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect((float) $rule->threshold_value)->toBe(32.0)
        ->and($rule->displayName())->toBe('Temperature Falls below 0.0°C');
});

test('the threshold field shows the metric unit and the value in celsius when editing', function () {
    $rule = AlertRule::factory()->for($this->user)->for($this->location)->create(['variable' => 'temperature', 'threshold_value' => 35]);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->assertFormSet(['threshold_value' => 1.7]);
});

test('saving without touching a converted value never rewrites it (no drift)', function () {
    $rule = AlertRule::factory()->for($this->user)->for($this->location)->create(['variable' => 'temperature', 'threshold_value' => 35]);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->fillForm(['delivery_time' => '07:30'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->threshold_value)->toBe('35.0');
});

test('changing the value converts it back to fahrenheit', function () {
    $rule = AlertRule::factory()->for($this->user)->for($this->location)->create(['variable' => 'temperature', 'threshold_value' => 35]);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->fillForm(['threshold_value' => 2])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->threshold_value)->toBe('35.6');
});

test('a wind threshold converts km/h to mph and percentages are never converted', function () {
    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'threshold', 'variable' => 'wind_speed', 'direction' => 'above', 'threshold_value' => 32.2,
            'timeframe' => 'day', 'location_id' => $this->location->id, 'delivery_time' => '06:00', 'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();
    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'threshold', 'variable' => 'humidity', 'direction' => 'above', 'threshold_value' => 70,
            'timeframe' => 'day', 'location_id' => $this->location->id, 'delivery_time' => '06:00', 'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $wind = AlertRule::where('variable', 'wind_speed')->sole();
    $humidity = AlertRule::where('variable', 'humidity')->sole();

    expect((float) $wind->threshold_value)->toEqualWithDelta(20.0, 0.1)
        ->and((float) $humidity->threshold_value)->toBe(70.0);
});

test('a metric subscriber sets the lightning radius in km, stored as whole miles', function () {
    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'lightning', 'location_id' => $this->location->id, 'radius_miles' => 24,
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->radius_miles)->toBe(15)
        ->and($rule->displayName())->toBe('Lightning within 24 km of Home');

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->assertFormSet(['radius_miles' => 24])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->radius_miles)->toBe(15);
});

test('the lightning radius minimum and maximum are enforced in the subscriber\'s unit', function () {
    $minMiles = app(TierThresholdSettings::class)->lightning_min_radius_miles;
    $minKm = (int) ceil($minMiles * 1.609344);

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'lightning', 'location_id' => $this->location->id, 'radius_miles' => $minKm - 1, 'channel_ids' => [$this->channel->id]])
        ->call('create')
        ->assertHasFormErrors(['radius_miles' => 'min']);

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'lightning', 'location_id' => $this->location->id, 'radius_miles' => 81, 'channel_ids' => [$this->channel->id]])
        ->call('create')
        ->assertHasFormErrors(['radius_miles' => 'max']);

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'lightning', 'location_id' => $this->location->id, 'radius_miles' => $minKm, 'channel_ids' => [$this->channel->id]])
        ->call('create')
        ->assertHasNoFormErrors();
});

test('an imperial subscriber\'s form is untouched', function () {
    $imperial = User::factory()->create();
    $this->actingAs($imperial);
    $location = Location::factory()->for($imperial)->create();
    $channel = NotificationChannel::factory()->for($imperial)->create();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'threshold', 'variable' => 'temperature', 'direction' => 'below', 'threshold_value' => 32,
            'timeframe' => 'overnight', 'location_id' => $location->id, 'delivery_time' => '06:00', 'channel_ids' => [$channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AlertRule::where('user_id', $imperial->id)->sole()->threshold_value)->toBe('32.0');
});

/**
 * Edits a stored lightning rule as the Metric subscriber, changing only the
 * channel so the radius is untouched, and returns the fresh rule.
 */
function editLightningRuleWithoutTouchingRadius(object $test, int $storedMiles, int $expectedDisplay): AlertRule
{
    $rule = AlertRule::factory()->lightning()->for($test->user)->for($test->location)->create(['radius_miles' => $storedMiles]);
    $rule->notificationChannels()->attach($test->channel);
    $otherChannel = NotificationChannel::factory()->for($test->user)->create();

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->assertFormSet(['radius_miles' => $expectedDisplay])
        ->fillForm(['channel_ids' => [$test->channel->id, $otherChannel->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    return $rule->fresh();
}

test('a rule stored at the minimum radius shows the minimum in km and saves untouched', function () {
    $minMiles = app(TierThresholdSettings::class)->lightning_min_radius_miles;
    $minKm = (int) ceil($minMiles * 1.609344);

    $rule = editLightningRuleWithoutTouchingRadius($this, $minMiles, $minKm);

    expect($rule->radius_miles)->toBe($minMiles)
        ->and($rule->notificationChannels)->toHaveCount(2);
});

test('a rule stored below the current minimum shows the minimum and saves untouched', function () {
    $minKm = (int) ceil(app(TierThresholdSettings::class)->lightning_min_radius_miles * 1.609344);

    expect(editLightningRuleWithoutTouchingRadius($this, 5, $minKm)->radius_miles)->toBe(5);
});

test('a rule stored at the maximum radius shows 80 km and saves untouched, and 80 km is accepted on create', function () {
    expect(editLightningRuleWithoutTouchingRadius($this, 50, 80)->radius_miles)->toBe(50);

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'lightning', 'location_id' => $this->location->id, 'radius_miles' => 80, 'channel_ids' => [$this->channel->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AlertRule::where('user_id', $this->user->id)->latest('id')->first()->radius_miles)->toBe(50);
});

test('a radius the subscriber changes is converted and stored', function () {
    $rule = AlertRule::factory()->lightning()->for($this->user)->for($this->location)->create(['radius_miles' => 15]);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->fillForm(['radius_miles' => 30])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->radius_miles)->toBe(19);
});
