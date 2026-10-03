<?php

use App\Filament\Dashboard\Resources\AlertRules\Pages\ListAlertRules;
use App\Filament\Dashboard\Resources\AlertRules\Widgets\ActiveNowWidget;
use App\Filament\Dashboard\Resources\AlertRules\Widgets\AlertStatsWidget;
use App\Filament\Dashboard\Resources\AlertRules\Widgets\NeedsAttentionWidget;
use App\Models\ActiveWwa;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    Carbon::setTestNow('2026-10-03 12:00:00');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** The stats the widget would render, as label => value. */
function widgetStats(object $component): array
{
    return collect((fn () => $this->getStats())->call($component->instance()))
        ->mapWithKeys(fn ($stat) => [(string) $stat->getLabel() => (string) $stat->getValue()])
        ->all();
}

test('the stats strip shows active alerts, deliveries, failures and quiet-hours holds', function () {
    AlertRule::factory()->for($this->user)->count(2)->create(['is_active' => true]);
    AlertRule::factory()->for($this->user)->create(['is_active' => false]);
    DeliveryLog::factory()->for($this->user)->count(3)->create(['status' => 'success', 'dispatched_at' => now()->subHour()]);
    DeliveryLog::factory()->for($this->user)->create(['status' => 'failure', 'dispatched_at' => now()->subHour()]);
    DeliveryLog::factory()->for($this->user)->create(['status' => 'suppressed', 'dispatched_at' => now()->subHour()]);

    expect(widgetStats(livewire(AlertStatsWidget::class)))->toBe([
        'Active alerts' => '2 of 3',
        'Deliveries, last 7 days' => '3',
        'Failed deliveries, last 7 days' => '1',
        'Held for quiet hours, last 7 days' => '1',
    ]);
});

test('the failed-deliveries stat is colored danger above zero and success at zero', function () {
    $colorOf = fn () => (fn () => collect($this->getStats())->first(fn ($stat) => str_starts_with((string) $stat->getLabel(), 'Failed'))->getColor())
        ->call(livewire(AlertStatsWidget::class)->instance());

    expect($colorOf())->toBe('success');

    DeliveryLog::factory()->for($this->user)->create(['status' => 'failure', 'dispatched_at' => now()->subHour()]);

    expect($colorOf())->toBe('danger');
});

test('the deliveries trend compares with the previous 7 days', function () {
    DeliveryLog::factory()->for($this->user)->count(3)->create(['status' => 'success', 'dispatched_at' => now()->subHour()]);
    DeliveryLog::factory()->for($this->user)->create(['status' => 'success', 'dispatched_at' => now()->subDays(9)]);

    $description = (fn () => collect($this->getStats())->first(fn ($stat) => str_starts_with((string) $stat->getLabel(), 'Deliveries'))->getDescription())
        ->call(livewire(AlertStatsWidget::class)->instance());

    expect((string) $description)->toContain('2 more than the previous 7 days');
});

test('the stats strip appears on the My Alerts page, with data and without', function () {
    livewire(ListAlertRules::class)->assertSeeLivewire(AlertStatsWidget::class);

    AlertRule::factory()->for($this->user)->create();

    livewire(ListAlertRules::class)->assertSeeLivewire(AlertStatsWidget::class);
});

test('the stats widget does not poll and spans the full width', function () {
    $widget = livewire(AlertStatsWidget::class)->instance();

    expect((fn () => $this->getPollingInterval())->call($widget))->toBeNull()
        ->and((fn () => $this->columnSpan)->call($widget))->toBe('full');
});

test('Needs attention shows an all-clear when nothing is wrong', function () {
    livewire(NeedsAttentionWidget::class)->assertSee('All your alerts look healthy');
});

test('Needs attention lists each problem with a link to the fix', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create(['label' => 'Work email', 'verified_at' => null]);
    $alert = AlertRule::factory()->dailySummary()->for($this->user)->for(Location::factory()->for($this->user)->create())->create(['name' => 'Morning summary']);
    $alert->notificationChannels()->attach($channel->id);
    $paused = AlertRule::factory()->dailySummary()->for($this->user)->for(Location::factory()->for($this->user)->create())->create(['name' => 'Storm warnings', 'is_active' => false]);

    livewire(NeedsAttentionWidget::class)
        ->assertSee('Work email')
        ->assertSee("isn't verified yet")
        ->assertSee('Storm warnings')
        ->assertSee('is turned off')
        ->assertSeeHtml(route('filament.dashboard.resources.alert-rules.edit', ['record' => $paused->id]))
        ->assertSeeHtml(route('filament.dashboard.resources.notification-channels.index'));
});

test('Needs attention shows at most 8 rows and counts the rest', function () {
    foreach (range(1, 11) as $i) {
        AlertRule::factory()->dailySummary()->for($this->user)->for(Location::factory()->for($this->user)->create())->create(['name' => sprintf('Paused %02d', $i), 'is_active' => false]);
    }

    livewire(NeedsAttentionWidget::class)
        ->assertSee('Paused 08')
        ->assertDontSee('Paused 09')
        ->assertSee('and 3 more');
});

test('Needs attention escapes subscriber-supplied names', function () {
    AlertRule::factory()->dailySummary()->for($this->user)->for(Location::factory()->for($this->user)->create())->create(['name' => '<script>alert(1)</script>', 'is_active' => false]);

    livewire(NeedsAttentionWidget::class)
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSee('<script>alert(1)</script>', escape: true);
});

test('Needs attention appears second on the My Alerts page', function () {
    livewire(ListAlertRules::class)
        ->assertSeeLivewire(AlertStatsWidget::class)
        ->assertSeeLivewire(NeedsAttentionWidget::class);
});

test('Active now invites a subscriber with no locations to add one', function () {
    livewire(ActiveNowWidget::class)->assertSee('Add a location to see active weather alerts here');
});

test('Active now says so when nothing is active at the subscriber\'s locations', function () {
    Location::factory()->for($this->user)->create(['lat' => 40.0, 'lng' => -105.0, 'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014']);

    livewire(ActiveNowWidget::class)->assertSee('No active weather alerts at your locations');
});

test('Active now shows each warning with where it applies and whether an alert was sent', function () {
    Location::factory()->for($this->user)->create(['label' => 'Home', 'lat' => 40.0, 'lng' => -105.0, 'timezone' => 'America/Denver', 'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014']);
    ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => 'Tornado Warning', 'family' => 'warning'])->id,
        'ugc_codes' => ['COZ040'], 'expires' => now()->addHour(),
    ]);

    livewire(ActiveNowWidget::class)
        ->assertSee('Tornado Warning')
        ->assertSee('Home')
        ->assertSee('None of your alerts matched this warning')
        ->assertSeeHtml('wire:poll.60s');
});

test('Active now escapes subscriber-supplied location names', function () {
    Location::factory()->for($this->user)->create(['label' => '<script>alert(1)</script>', 'lat' => 40.0, 'lng' => -105.0, 'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014']);
    ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => 'Tornado Warning', 'family' => 'warning'])->id,
        'ugc_codes' => ['COZ040'], 'expires' => now()->addHour(),
    ]);

    livewire(ActiveNowWidget::class)->assertDontSeeHtml('<script>alert(1)</script>');
});

test('all three widgets appear on the My Alerts page in order', function () {
    livewire(ListAlertRules::class)
        ->assertSeeLivewire(AlertStatsWidget::class)
        ->assertSeeLivewire(NeedsAttentionWidget::class)
        ->assertSeeLivewire(ActiveNowWidget::class);
});
