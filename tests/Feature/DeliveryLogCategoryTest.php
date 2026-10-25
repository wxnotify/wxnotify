<?php

use App\Actions\Weather\MatchWeatherAlertRules;
use App\Jobs\DeliverScheduledAlert;
use App\Jobs\DeliverWeatherAlert;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Support\Delivery\ChannelDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('the backfill migration fills each existing log row from its rule and leaves orphaned rows null', function () {
    $threshold = AlertRule::factory()->create(); // category: threshold
    $lightning = AlertRule::factory()->lightning()->create();

    $withRule = DeliveryLog::factory()->for($threshold->user)->create(['alert_rule_id' => $threshold->id, 'category' => null]);
    $withOtherRule = DeliveryLog::factory()->for($lightning->user)->create(['alert_rule_id' => $lightning->id, 'category' => null]);
    $orphan = DeliveryLog::factory()->for($threshold->user)->create(['alert_rule_id' => null, 'category' => null]);
    $alreadySet = DeliveryLog::factory()->for($threshold->user)->create(['alert_rule_id' => $threshold->id, 'category' => 'daily_summary']);

    $backfill = require database_path('migrations/2026_10_03_100002_backfill_delivery_log_category.php');

    $backfill->up();
    $backfill->up(); // a re-run changes nothing

    expect($withRule->fresh()->category)->toBe('threshold')
        ->and($withOtherRule->fresh()->category)->toBe('lightning')
        ->and($orphan->fresh()->category)->toBeNull()
        ->and($alreadySet->fresh()->category)->toBe('threshold'); // the backfill is authoritative for rows that have a rule
});

test('the add-column migration is safe to run when the column already exists', function () {
    $migration = require database_path('migrations/2026_10_03_100001_add_category_to_delivery_logs_table.php');

    $migration->up();
    $migration->up();

    expect(DB::getSchemaBuilder()->hasColumn('delivery_logs', 'category'))->toBeTrue();
});

test('a successful scheduled delivery records the rule\'s category', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $rule = AlertRule::factory()->dailySummary()->create();
    $channel = NotificationChannel::factory()->for($rule->user)->create(['apprise_url' => 'discord://token/id']);

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', 'Today: Sunny.'))->handle(app(ChannelDispatcher::class));

    expect(DeliveryLog::sole()->category)->toBe('daily_summary');
});

test('a skipped scheduled delivery (rule deactivated) records the rule\'s category too', function () {
    Http::fake();
    $rule = AlertRule::factory()->lightning()->create(['is_active' => false]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Lightning', 'body'))->handle(app(ChannelDispatcher::class));

    $log = DeliveryLog::sole();
    expect($log->status)->toBe('skipped')->and($log->category)->toBe('lightning');
});

test('a weather alert delivery records the rule\'s category, success and skipped alike', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $channel = NotificationChannel::factory()->for($rule->user)->create(['apprise_url' => 'discord://token/id']);
    $product = IngestedProduct::factory()->create();

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(ChannelDispatcher::class));

    $rule->update(['is_active' => false]);
    (new DeliverWeatherAlert($rule->id, $channel->id, IngestedProduct::factory()->create()->id))->handle(app(ChannelDispatcher::class));

    expect(DeliveryLog::orderBy('id')->pluck('category', 'status')->all())->toBe(['success' => 'weather_alerts', 'skipped' => 'weather_alerts']);
});

test('the quiet-hours suppressed row records the rule\'s category', function () {
    Queue::fake();
    Carbon::setTestNow('2026-10-02 09:00:00'); // 03:00 America/Denver: inside the rule's quiet hours
    $tornado = Product::where('code', 'TO.W')->sole();
    $location = Location::factory()->create(['timezone' => 'America/Denver', 'forecast_zone_ugc' => 'COZ040']);
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => null,
        'quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00',
    ]);
    $rule->locations()->attach($location);
    $rule->products()->attach($tornado);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create(['product_id' => $tornado->id, 'ugc_codes' => ['COZ040']]));

    (new MatchWeatherAlertRules)($ingested);

    $log = DeliveryLog::sole();
    expect($log->status)->toBe('suppressed')->and($log->category)->toBe('weather_alerts');
    Carbon::setTestNow();
});
