<?php

use App\Enums\Tier;
use App\Filament\Admin\Pages\AppSettings;
use App\Models\AlertRule;
use App\Models\User;
use App\Settings\TierThresholdSettings;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

function userOnTier(Tier $tier): User
{
    return User::factory()->create(['tier_override' => $tier]);
}

test('the default alert limits are 3, 5 and 10', function () {
    expect(userOnTier(Tier::Free)->alertRuleLimit())->toBe(3)
        ->and(userOnTier(Tier::Plus)->alertRuleLimit())->toBe(5)
        ->and(userOnTier(Tier::Pro)->alertRuleLimit())->toBe(10);
});

test('a changed setting changes the limit', function () {
    $settings = app(TierThresholdSettings::class);
    $settings->free_alerts = 1;
    $settings->save();

    expect(userOnTier(Tier::Free)->alertRuleLimit())->toBe(1);
});

test('paused alerts count toward the limit', function () {
    $user = userOnTier(Tier::Free);
    AlertRule::factory()->count(2)->create(['user_id' => $user->id]);
    AlertRule::factory()->create(['user_id' => $user->id, 'is_active' => false]);

    expect($user->canCreateAlertRule())->toBeFalse();
});

test('a Free user over the limit cannot create and has room again once under it', function () {
    $user = userOnTier(Tier::Free);
    $rules = AlertRule::factory()->count(7)->create(['user_id' => $user->id]);

    expect($user->canCreateAlertRule())->toBeFalse();

    $rules->take(5)->each->delete();
    expect($user->canCreateAlertRule())->toBeTrue();
});

test('a Free user may have one Outlook Report but Plus may have several', function () {
    $free = userOnTier(Tier::Free);
    expect($free->canCreateOutlookReport())->toBeTrue();

    AlertRule::factory()->outlookReport()->create(['user_id' => $free->id]);
    expect($free->canCreateOutlookReport())->toBeFalse();

    $plus = userOnTier(Tier::Plus);
    AlertRule::factory()->outlookReport()->count(2)->create(['user_id' => $plus->id]);
    expect($plus->canCreateOutlookReport())->toBeTrue();
});

describe('App settings page', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create(['is_staff' => true]));
    });

    test('saves the alert limits', function () {
        livewire(AppSettings::class)
            ->fillForm(['free_alerts' => 2, 'plus_alerts' => 6, 'pro_alerts' => 20])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(TierThresholdSettings::class)->refresh();
        expect([$settings->free_alerts, $settings->plus_alerts, $settings->pro_alerts])->toBe([2, 6, 20]);
    });

    test('rejects Plus below Free', function () {
        livewire(AppSettings::class)
            ->fillForm(['free_alerts' => 4, 'plus_alerts' => 3])
            ->call('save')
            ->assertHasFormErrors(['plus_alerts']);
    });
});
