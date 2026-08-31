<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Resources\AlertRules\Pages\CreateAlertRule;
use App\Filament\Dashboard\Resources\AlertRules\Pages\ListAlertRules;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

beforeEach(function () {
    // Populates Product::category, which the weather-alerts/spc-wpc product
    // sections below are grouped by.
    $this->seed(ProductCatalogSeeder::class);

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
            'product_ids' => [$tornadoWarning->category->value => [$tornadoWarning->id]],
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
            'product_ids' => [$tornadoWarning->category->value => [$tornadoWarning->id]],
            'matching_mode' => 'nationwide',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->matching_mode->value)->toBe('nationwide')
        ->and($rule->location_id)->toBeNull();
});

test('a weather alerts rule can select products outside the graduated warning/watch/advisory/statement families', function () {
    // Regression: the product-picker sections used to filter by family
    // (warning/watch/advisory/statement only), which silently excluded
    // every administrative/informational/aviation product — entire
    // category sections (Airport-related, most of Other Forecasts...)
    // never appeared, and others (Non-Meteorological Hazards...) showed
    // only a handful of their products.
    $civilDanger = Product::where('code', 'CDW')->sole();
    expect($civilDanger->family->value)->toBe('administrative');

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_ids' => [$civilDanger->category->value => [$civilDanger->id]],
            'matching_mode' => 'nationwide',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->products->pluck('id')->all())->toBe([$civilDanger->id]);
});

test('creating an spc wpc rule persists the chosen risk levels', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'spc_wpc',
            'product_ids' => [$outlook->category->value => [$outlook->id]],
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

test('creating a location inline from the location field attaches it to the alert', function () {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();

    $component = livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'lightning'])
        ->callFormComponentAction('location_id', 'createOption', data: [
            'label' => 'Office',
            'lat' => 38.8976,
            'lng' => -77.0365,
            'address' => '1600 Pennsylvania Ave NW, Washington, DC',
        ]);

    $location = Location::where('user_id', $this->user->id)->where('label', 'Office')->sole();

    $component
        ->fillForm(['radius_miles' => 10, 'channel_ids' => [$this->channel->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->location_id)->toBe($location->id);
});

test('the inline location action is hidden once a Free subscriber is at their location limit', function () {
    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'lightning'])
        ->assertFormComponentActionHidden('location_id', 'createOption');
});

test('connecting a channel inline from the channels field dispatches a code and attaches it to the alert', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    // beforeEach's $this->channel (discord) already fills the Free tier's one
    // no-cost slot, so a second no-cost type like ntfy needs a paid tier here.
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();

    $component = livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'lightning', 'channel_ids' => [$this->channel->id]])
        ->mountFormComponentAction('channel_ids', 'connect_ntfy')
        ->setFormComponentActionData(['topic' => 'wxnotify-inline-topic'])
        ->callMountedFormComponentAction();

    $newChannel = NotificationChannel::where('user_id', $this->user->id)
        ->where('channel_type', 'ntfy')
        ->sole();

    expect($newChannel->apprise_url)->toBe('ntfy://wxnotify-inline-topic')
        ->and($newChannel->isVerified())->toBeFalse()
        ->and($newChannel->confirmation_code)->not->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/notify/'));

    $component
        ->fillForm(['location_id' => $this->location->id, 'radius_miles' => 10])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->notificationChannels->pluck('id')->all())->toBe([$this->channel->id, $newChannel->id]);
});

test('the inline channel action for a no-cost type is hidden once a Free subscriber has used their slot', function () {
    // beforeEach's $this->channel is a discord channel, which fills the Free tier's one no-cost slot.
    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'lightning'])
        ->assertFormComponentActionHidden('channel_ids', 'connect_slack')
        ->assertFormComponentActionVisible('channel_ids', 'connect_email');
});
