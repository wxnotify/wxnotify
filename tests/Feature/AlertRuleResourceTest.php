<?php

use App\Filament\Dashboard\Resources\AlertRules\Pages\CreateAlertRule;
use App\Filament\Dashboard\Resources\AlertRules\Pages\ListAlertRules;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->location = Location::factory()->for($this->user)->create(['label' => 'Home']);
    $this->channel = NotificationChannel::factory()->for($this->user)->create();
});

test('a subscriber only sees their own alert rules', function () {
    $mine = AlertRule::factory()->for($this->user)->create();
    $someoneElses = AlertRule::factory()->for(User::factory())->create();

    livewire(ListAlertRules::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$someoneElses]);
});

test('creating a threshold alert persists the rule and its channel', function () {
    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'threshold',
            'variable' => 'temperature',
            'direction' => 'below',
            'threshold_value' => 32,
            'timeframe' => 'overnight',
            'location_id' => $this->location->id,
            'delivery_time' => '06:00',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->category->value)->toBe('threshold')
        ->and($rule->variable->value)->toBe('temperature')
        ->and($rule->direction->value)->toBe('below')
        ->and((float) $rule->threshold_value)->toBe(32.0)
        ->and($rule->location_id)->toBe($this->location->id)
        ->and($rule->displayName())->toBe('Temperature Falls below 32.0°F');

    expect($rule->notificationChannels->pluck('id')->all())->toBe([$this->channel->id]);
});

test('creating a weather alerts rule persists the chosen products and matching mode', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_ids' => [$tornadoWarning->id],
            'matching_mode' => 'location',
            'location_id' => $this->location->id,
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->matching_mode->value)->toBe('location')
        ->and($rule->products->pluck('id')->all())->toBe([$tornadoWarning->id])
        ->and($rule->displayName())->toBe('Tornado Warning');
});

test('creating a nationwide weather alerts rule does not require a location', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_ids' => [$tornadoWarning->id],
            'matching_mode' => 'nationwide',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->matching_mode->value)->toBe('nationwide')
        ->and($rule->location_id)->toBeNull();
});

test('creating an spc wpc rule persists the chosen risk levels', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'spc_wpc',
            'product_ids' => [$outlook->id],
            'risk_levels' => ['Enhanced', 'Moderate', 'High'],
            'matching_mode' => 'nationwide',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->risk_levels)->toBe(['Enhanced', 'Moderate', 'High'])
        ->and($rule->products->pluck('id')->all())->toBe([$outlook->id]);
});

test('creating a lightning alert rejects a radius below the enforced minimum', function () {
    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'lightning',
            'location_id' => $this->location->id,
            'radius_miles' => 2,
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['radius_miles' => 'min']);

    expect(AlertRule::where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('creating a lightning alert at the minimum radius succeeds', function () {
    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'lightning',
            'location_id' => $this->location->id,
            'radius_miles' => 5,
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->radius_miles)->toBe(5)
        ->and($rule->displayName())->toBe('Lightning within 5 mi of Home');
});

test('creating a forecast discussion rule uppercases the office code', function () {
    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'forecast_discussion',
            'wfo' => 'okx',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->wfo)->toBe('OKX');
});

test('a subscriber cannot point an alert rule at another subscriber\'s location', function () {
    $othersLocation = Location::factory()->for(User::factory())->create();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'threshold',
            'variable' => 'temperature',
            'direction' => 'below',
            'threshold_value' => 32,
            'timeframe' => 'overnight',
            'location_id' => $othersLocation->id,
            'delivery_time' => '06:00',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['location_id']);

    expect(AlertRule::where('user_id', $this->user->id)->exists())->toBeFalse();
});
