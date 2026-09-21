<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Resources\AlertRules\Pages\CreateAlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Settings\TierThresholdSettings;
use Database\Seeders\ProductCatalogSeeder;

use function Pest\Livewire\livewire;

function setTierSetting(string $property, int $value): void
{
    $settings = app(TierThresholdSettings::class);
    $settings->{$property} = $value;
    $settings->save();
}

test('the location limits follow the settings and Pro stays unlimited', function () {
    setTierSetting('free_locations', 2);
    setTierSetting('plus_locations', 9);

    expect(User::factory()->create(['tier_override' => Tier::Free])->locationLimit())->toBe(2)
        ->and(User::factory()->create(['tier_override' => Tier::Plus])->locationLimit())->toBe(9)
        ->and(User::factory()->create(['tier_override' => Tier::Pro])->locationLimit())->toBeNull();
});

test('a Free user can create locations up to the configured limit', function () {
    setTierSetting('free_locations', 2);
    $user = User::factory()->create(['tier_override' => Tier::Free]);
    Location::factory()->for($user)->create();

    expect($user->canCreateLocation())->toBeTrue();

    Location::factory()->for($user)->create();

    expect($user->canCreateLocation())->toBeFalse();
});

test('the Traccar server and device limits follow the settings', function () {
    setTierSetting('free_traccar_servers', 1);
    setTierSetting('plus_traccar_servers', 2);
    setTierSetting('pro_traccar_servers', 4);
    setTierSetting('free_traccar_devices_per_server', 1);
    setTierSetting('plus_traccar_devices_per_server', 6);
    setTierSetting('pro_traccar_devices_per_server', 12);

    $free = User::factory()->create(['tier_override' => Tier::Free]);
    $plus = User::factory()->create(['tier_override' => Tier::Plus]);
    $pro = User::factory()->create(['tier_override' => Tier::Pro]);

    expect([$free->traccarServerLimit(), $plus->traccarServerLimit(), $pro->traccarServerLimit()])->toBe([1, 2, 4])
        ->and([$free->traccarDeviceLimitPerServer(), $plus->traccarDeviceLimitPerServer(), $pro->traccarDeviceLimitPerServer()])->toBe([1, 6, 12]);
});

test('the Free no-cost channel slot count follows the setting', function () {
    setTierSetting('free_no_cost_channels', 2);
    $user = User::factory()->create(['tier_override' => Tier::Free]);
    NotificationChannel::factory()->for($user)->create(['channel_type' => 'discord']);

    expect($user->fresh()->canAddChannel('slack'))->toBeTrue();

    NotificationChannel::factory()->for($user)->create(['channel_type' => 'slack']);

    expect($user->fresh()->canAddChannel('telegram'))->toBeFalse();
});

test('a Free channel slot of zero blocks every no-cost channel, while email stays unlimited', function () {
    setTierSetting('free_no_cost_channels', 0);
    $user = User::factory()->create(['tier_override' => Tier::Free]);

    expect($user->canAddChannel('discord'))->toBeFalse()
        ->and($user->canAddChannel('email'))->toBeTrue();
});

test('the lightning radius floor follows the setting on create', function () {
    $this->seed(ProductCatalogSeeder::class);
    $user = User::factory()->create();
    $this->actingAs($user);
    $location = Location::factory()->for($user)->create(['label' => 'Home']);
    $channel = NotificationChannel::factory()->for($user)->create();

    setTierSetting('lightning_min_radius_miles', 12);

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'lightning',
            'location_id' => $location->id,
            'radius_miles' => 11,
            'channel_ids' => [$channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['radius_miles' => 'min']);

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'lightning',
            'location_id' => $location->id,
            'radius_miles' => 12,
            'channel_ids' => [$channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();
});
