<?php

use App\Enums\Tier;
use App\Exceptions\AlertRuleLimitReached;
use App\Filament\Dashboard\Resources\AlertRules\Pages\ListAlertRules;
use App\Filament\Dashboard\Resources\Locations\Pages\CreateLocation;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\TraccarServer;
use App\Models\User;
use App\Settings\TierThresholdSettings;
use Database\Seeders\ProductCatalogSeeder;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

function lowerCloudLimitsToOne(): void
{
    $settings = app(TierThresholdSettings::class);
    $settings->free_locations = 1;
    $settings->plus_locations = 1;
    $settings->free_phone_locations = 1;
    $settings->plus_phone_locations = 1;
    $settings->free_alerts = 1;
    $settings->plus_alerts = 1;
    $settings->pro_alerts = 1;
    $settings->free_traccar_servers = 1;
    $settings->plus_traccar_servers = 1;
    $settings->pro_traccar_servers = 1;
    $settings->free_traccar_devices_per_server = 1;
    $settings->plus_traccar_devices_per_server = 1;
    $settings->pro_traccar_devices_per_server = 1;
    $settings->save();
}

test('in self-host mode tier() is Pro whatever the override', function () {
    config(['wxnotify.mode' => 'selfhost']);

    $free = User::factory()->create(['tier_override' => Tier::Free]);
    $none = User::factory()->create(['tier_override' => null]);

    expect(User::hasTiers())->toBeFalse()
        ->and($free->tier())->toBe(Tier::Pro)
        ->and($none->tier())->toBe(Tier::Pro);
});

test('in cloud mode tier() is unchanged', function () {
    config(['wxnotify.mode' => 'cloud']);

    expect(User::hasTiers())->toBeTrue()
        ->and(User::factory()->create()->tier())->toBe(Tier::Free)
        ->and(User::factory()->create(['tier_override' => Tier::Plus])->tier())->toBe(Tier::Plus);
});

test('every limit is null in self-host mode', function () {
    config(['wxnotify.mode' => 'selfhost']);
    lowerCloudLimitsToOne();

    $user = User::factory()->create(['tier_override' => Tier::Free]);
    AlertRule::factory()->count(50)->for($user)->create();
    Location::factory()->count(5)->for($user)->create();
    TraccarServer::factory()->count(3)->for($user)->create();

    expect($user->locationLimit())->toBeNull()
        ->and($user->phoneLocationLimit())->toBeNull()
        ->and($user->alertRuleLimit())->toBeNull()
        ->and($user->traccarServerLimit())->toBeNull()
        ->and($user->traccarDeviceLimitPerServer())->toBeNull()
        ->and($user->canCreateAlertRule())->toBeTrue()
        ->and($user->canCreateLocation())->toBeTrue()
        ->and($user->canCreatePhoneLocation())->toBeTrue()
        ->and($user->canCreateTraccarServer())->toBeTrue();
});

test('a self-host user can create alert rules past the cloud limit and a second Outlook Report', function () {
    config(['wxnotify.mode' => 'selfhost']);
    lowerCloudLimitsToOne();

    $user = User::factory()->create(['tier_override' => Tier::Free]);
    AlertRule::factory()->count(3)->for($user)->create();
    AlertRule::factory()->count(2)->outlookReport()->for($user)->create();

    expect($user->alertRules()->count())->toBe(5);
});

test('the options payload says unlimited in self-host mode', function () {
    config(['wxnotify.mode' => 'selfhost']);
    $this->seed(ProductCatalogSeeder::class);
    lowerCloudLimitsToOne();

    $user = User::factory()->create(['tier_override' => Tier::Free]);
    AlertRule::factory()->count(2)->for($user)->create();
    AlertRule::factory()->outlookReport()->for($user)->create();
    $token = $user->createToken('Pixel 9', ['app'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/alert-rule-options')
        ->assertOk()
        ->assertJsonPath('data.alert_limit', null)
        ->assertJsonPath('data.alert_count', 3)
        ->assertJsonPath('data.can_create_alert', true)
        ->assertJsonPath('data.can_create_outlook_report', true);
});

test('the My Alerts page shows no limit in self-host mode', function () {
    config(['wxnotify.mode' => 'selfhost']);
    Filament::setCurrentPanel('dashboard');
    lowerCloudLimitsToOne();

    $user = User::factory()->create(['tier_override' => Tier::Free]);
    AlertRule::factory()->count(2)->for($user)->create();
    $this->actingAs($user);

    livewire(ListAlertRules::class)
        ->assertDontSee("You're using")
        ->assertSee("Alerts you've set up to be notified about")
        ->assertActionEnabled('create');
});

test('switching back to cloud mode restores the override', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);

    config(['wxnotify.mode' => 'selfhost']);
    expect($user->tier())->toBe(Tier::Pro);

    config(['wxnotify.mode' => 'cloud']);
    expect($user->tier())->toBe(Tier::Plus)
        ->and($user->fresh()->tier_override)->toBe(Tier::Plus);
});

test('in cloud mode a Free user at the alert limit is refused', function () {
    config(['wxnotify.mode' => 'cloud']);
    lowerCloudLimitsToOne();

    $user = User::factory()->create(['tier_override' => Tier::Free]);
    AlertRule::factory()->for($user)->create();

    expect($user->alertRuleLimit())->toBe(1)
        ->and($user->canCreateAlertRule())->toBeFalse()
        ->and(fn () => AlertRule::factory()->for($user)->create())
        ->toThrow(AlertRuleLimitReached::class, "You have reached your plan's limit of 1 alert.");
});

test('in cloud mode a Free user at the location limit is refused', function () {
    config(['wxnotify.mode' => 'cloud']);
    Filament::setCurrentPanel('dashboard');
    lowerCloudLimitsToOne();

    $user = User::factory()->create(['tier_override' => Tier::Free]);
    Location::factory()->for($user)->create();
    $this->actingAs($user);

    livewire(CreateLocation::class)
        ->fillForm(['label' => 'Second place', 'lat' => 40.0, 'lng' => -99.0, 'timezone' => 'America/Chicago'])
        ->call('create')
        ->assertNotified('Saved-location limit reached');

    expect($user->locationLimit())->toBe(1)
        ->and($user->locations()->count())->toBe(1);
});
