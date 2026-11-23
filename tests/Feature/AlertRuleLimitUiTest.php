<?php

use App\Enums\Tier;
use App\Exceptions\AlertRuleLimitReached;
use App\Filament\Dashboard\Resources\AlertRules\Pages\CreateAlertRule;
use App\Filament\Dashboard\Resources\AlertRules\Pages\ListAlertRules;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Settings\TierThresholdSettings;
use Database\Seeders\ProductCatalogSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->seed(ProductCatalogSeeder::class);

    $this->user = User::factory()->create(['tier_override' => Tier::Free]);
    $this->actingAs($this->user);

    $settings = app(TierThresholdSettings::class);
    $settings->free_alerts = 2;
    $settings->save();
});

function thresholdFormData(User $user): array
{
    return [
        'category' => 'threshold',
        'variable' => 'temperature',
        'direction' => 'below',
        'threshold_value' => 20,
        'timeframe' => 'overnight',
        'location_id' => Location::factory()->for($user)->create()->id,
        'delivery_time' => '06:00',
        'channel_ids' => [NotificationChannel::factory()->for($user)->create()->id],
    ];
}

test('the New Alert button is disabled at the limit and enabled under it', function () {
    AlertRule::factory()->for($this->user)->create();

    livewire(ListAlertRules::class)->assertActionEnabled('create');

    AlertRule::factory()->for($this->user)->create(['is_active' => false]);

    $page = livewire(ListAlertRules::class)->assertActionDisabled('create');

    expect($page->instance()->getAction('create')->getTooltip())->toBe(AlertRuleLimitReached::limit(2)->getMessage());
});

test('the list page says how many alerts are used, paused ones included', function () {
    AlertRule::factory()->for($this->user)->create();
    AlertRule::factory()->for($this->user)->create(['is_active' => false]);

    livewire(ListAlertRules::class)->assertSee("You're using 2 of 2 alerts.");
});

test('the list page pluralises a limit of one', function () {
    $settings = app(TierThresholdSettings::class);
    $settings->free_alerts = 1;
    $settings->save();

    livewire(ListAlertRules::class)->assertSee("You're using 0 of 1 alert.");
});

test('creating at the limit halts with a notification and creates nothing', function () {
    AlertRule::factory()->count(2)->for($this->user)->create();

    livewire(CreateAlertRule::class)
        ->fillForm(thresholdFormData($this->user))
        ->call('create')
        ->assertNotified('Alert limit reached')
        ->assertNoRedirect();

    expect($this->user->alertRules()->count())->toBe(2);
});

test('creating under the limit still works', function () {
    AlertRule::factory()->for($this->user)->create();

    livewire(CreateAlertRule::class)
        ->fillForm(thresholdFormData($this->user))
        ->call('create')
        ->assertHasNoFormErrors();

    expect($this->user->alertRules()->count())->toBe(2);
});

test('a save that races past the early check ends in a notification, not an error', function () {
    AlertRule::factory()->for($this->user)->create();
    $template = AlertRule::factory()->for($this->user)->make()->getAttributes();

    $filled = false;
    AlertRule::saving(function () use (&$filled, $template): void {
        if ($filled) {
            return;
        }
        $filled = true;
        DB::table('alert_rules')->insert(array_merge($template, ['created_at' => now(), 'updated_at' => now()]));
    });

    livewire(CreateAlertRule::class)
        ->fillForm(thresholdFormData($this->user))
        ->call('create')
        ->assertNotified('Alert limit reached')
        ->assertNoRedirect();

    expect($this->user->alertRules()->count())->toBe(2);
});
