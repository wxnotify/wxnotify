<?php

use App\Actions\Alerts\SaveAlertRule;
use App\Enums\AlertCategory;
use App\Enums\MatchingMode;
use App\Enums\MessageBodyStyle;
use App\Enums\Tier;
use App\Enums\UnitSystem;
use App\Models\AlertRule;
use App\Models\AlertTemplate;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\ProductCatalogSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(ProductCatalogSeeder::class);

    $this->user = User::factory()->create();
    $this->location = Location::factory()->for($this->user)->create();
    $this->channel = NotificationChannel::factory()->for($this->user)->create();
    $this->tornado = Product::where('code', 'TO.W')->sole();
    $this->riskProduct = Product::where('family', 'spc_wpc')->whereNotNull('risk_level_options')->orderBy('id')->firstOrFail();

    $this->save = fn (array $data, ?AlertRule $rule = null, ?User $user = null): AlertRule => app(SaveAlertRule::class)($user ?? $this->user, $data, $rule);

    $this->thresholdData = fn (array $overrides = []): array => [
        'category' => 'threshold',
        'variable' => 'temperature',
        'direction' => 'below',
        'threshold_value' => 32,
        'timeframe' => 'overnight',
        'location_id' => $this->location->id,
        'delivery_time' => '06:00',
        'channel_ids' => [$this->channel->id],
        ...$overrides,
    ];

    $this->weatherData = fn (array $overrides = []): array => [
        'category' => 'weather_alerts',
        'product_ids' => [$this->tornado->category->value => [$this->tornado->id]],
        'matching_mode' => 'nationwide',
        'channel_ids' => [$this->channel->id],
        ...$overrides,
    ];
});

test('it creates a threshold rule owned by the user with its channel and no products or locations', function () {
    $rule = ($this->save)(($this->thresholdData)());

    expect($rule->user_id)->toBe($this->user->id)
        ->and($rule->category)->toBe(AlertCategory::Threshold)
        ->and((float) $rule->threshold_value)->toBe(32.0)
        ->and($rule->location_id)->toBe($this->location->id)
        ->and($rule->is_active)->toBeTrue()
        ->and($rule->notificationChannels->pluck('id')->all())->toBe([$this->channel->id])
        ->and($rule->products)->toBeEmpty()
        ->and($rule->locations)->toBeEmpty();
});

test('a Metric subscriber\'s temperature and radius are converted to the stored imperial units', function () {
    $metric = User::factory()->create(['unit_system' => UnitSystem::Metric]);
    $location = Location::factory()->for($metric)->create();
    $channel = NotificationChannel::factory()->for($metric)->create();

    $threshold = ($this->save)(($this->thresholdData)(['threshold_value' => 0, 'location_id' => $location->id, 'channel_ids' => [$channel->id]]), null, $metric);
    $lightning = ($this->save)(['category' => 'lightning', 'radius_miles' => 24, 'location_id' => $location->id, 'channel_ids' => [$channel->id]], null, $metric);

    expect((float) $threshold->threshold_value)->toBe(32.0)
        ->and($lightning->radius_miles)->toBe(15);
});

test('saving an untouched Metric threshold keeps the stored number instead of drifting', function () {
    $metric = User::factory()->create(['unit_system' => UnitSystem::Metric]);
    $location = Location::factory()->for($metric)->create();
    $channel = NotificationChannel::factory()->for($metric)->create();
    $rule = AlertRule::factory()->for($metric)->create(['location_id' => $location->id, 'threshold_value' => 35]);

    // 35 degF is shown as 1.7 degC; saving that unchanged value must not turn it into 35.06 -> 35.1.
    $saved = ($this->save)(($this->thresholdData)(['threshold_value' => 1.7, 'location_id' => $location->id, 'channel_ids' => [$channel->id]]), $rule, $metric);

    expect((float) $saved->threshold_value)->toBe(35.0);
});

test('SPC and WPC risk levels are stored flat whether they arrive flat or per product category', function () {
    $levels = array_slice($this->riskProduct->risk_level_options, 0, 2);
    $base = [
        'category' => 'spc_wpc',
        'product_ids' => [$this->riskProduct->category->value => [$this->riskProduct->id]],
        'matching_mode' => 'risk_area',
        'location_ids' => [$this->location->id],
        'channel_ids' => [$this->channel->id],
    ];

    $nested = ($this->save)([...$base, 'risk_levels' => [$this->riskProduct->category->value => $levels]]);
    $flat = ($this->save)([...$base, 'risk_levels' => $levels]);

    expect($nested->risk_levels)->toBe($levels)
        ->and($flat->risk_levels)->toBe($levels)
        ->and($nested->products->pluck('id')->all())->toBe([$this->riskProduct->id])
        ->and($nested->locations->pluck('id')->all())->toBe([$this->location->id]);
});

test('the matching mode decides whether the office and the county/zone codes are kept', function () {
    $wfo = ($this->save)(($this->weatherData)(['matching_mode' => 'wfo', 'wfo' => 'OKX', 'county_zone_codes' => ['NYC103']]));
    $codes = ($this->save)(($this->weatherData)(['matching_mode' => 'county_zone', 'wfo' => 'OKX', 'county_zone_codes' => ['NYC103', 'NYZ072']]));
    $string = ($this->save)(($this->weatherData)(['matching_mode' => 'county_zone', 'county_zone_codes' => 'NYC103,NYZ072']));

    expect($wfo->wfo)->toBe('OKX')
        ->and($wfo->county_zone_codes)->toBeNull()
        ->and($codes->wfo)->toBeNull()
        ->and($codes->county_zone_codes)->toBe('NYC103,NYZ072')
        ->and($string->county_zone_codes)->toBe('NYC103,NYZ072')
        ->and($wfo->matching_mode)->toBe(MatchingMode::Wfo);
});

test('only the matching modes that use locations keep the chosen locations', function (string $mode, bool $kept) {
    $rule = ($this->save)(($this->weatherData)(['matching_mode' => $mode, 'wfo' => 'OKX', 'county_zone_codes' => ['NYC103'], 'location_ids' => [$this->location->id]]));

    expect($rule->locations->pluck('id')->all())->toBe($kept ? [$this->location->id] : []);
})->with([
    'nationwide' => ['nationwide', false],
    'office' => ['wfo', false],
    'county and zone' => ['county_zone', false],
    'location' => ['location', true],
    'polygon' => ['polygon', true],
]);

test('switching a location rule to nationwide drops its locations', function () {
    $rule = ($this->save)(($this->weatherData)(['matching_mode' => 'location', 'location_ids' => [$this->location->id]]));

    $rule = ($this->save)(($this->weatherData)(['matching_mode' => 'nationwide', 'location_ids' => [$this->location->id]]), $rule);

    expect($rule->locations)->toBeEmpty();
});

test('changing the category clears the columns and relations that no longer apply', function () {
    $weather = ($this->save)(($this->weatherData)(['matching_mode' => 'location', 'location_ids' => [$this->location->id]]));

    $daily = ($this->save)(['category' => 'daily_summary', 'location_id' => $this->location->id, 'delivery_time' => '07:00', 'channel_ids' => [$this->channel->id]], $weather);

    expect($daily->matching_mode)->toBeNull()
        ->and($daily->products)->toBeEmpty()
        ->and($daily->locations)->toBeEmpty()
        ->and($daily->location_id)->toBe($this->location->id);

    $threshold = ($this->save)(($this->thresholdData)(), $daily);
    $back = ($this->save)(($this->weatherData)(), $threshold);

    expect($back->variable)->toBeNull()
        ->and($back->direction)->toBeNull()
        ->and($back->threshold_value)->toBeNull()
        ->and($back->timeframe)->toBeNull()
        ->and($back->location_id)->toBeNull()
        ->and($back->delivery_time)->toBeNull()
        ->and($back->matching_mode)->toBe(MatchingMode::Nationwide);
});

test('a Free subscriber\'s message customisation is ignored', function () {
    $rule = ($this->save)(($this->weatherData)(['message_body_style' => 'brief', 'custom_subject_template' => '{product}']));

    expect($rule->message_body_style)->toBeNull()
        ->and($rule->custom_subject_template)->toBeNull();
});

test('a paid subscriber\'s message customisation is stored, defaulting the length to full, and a template only where the category supports one', function () {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();
    $template = AlertTemplate::factory()->for($this->user)->create(['category' => AlertCategory::WeatherAlerts]);

    $default = ($this->save)(($this->weatherData)());
    $custom = ($this->save)(($this->weatherData)(['message_body_style' => 'brief', 'custom_subject_template' => '{product} at {location}', 'alert_template_id' => $template->id]));
    $threshold = ($this->save)(($this->thresholdData)(['alert_template_id' => $template->id]));

    expect($default->message_body_style)->toBe(MessageBodyStyle::Full)
        ->and($custom->message_body_style)->toBe(MessageBodyStyle::Brief)
        ->and($custom->custom_subject_template)->toBe('{product} at {location}')
        ->and($custom->alert_template_id)->toBe($template->id)
        ->and($threshold->alert_template_id)->toBeNull();
});

test('a blank name and blank quiet hours are stored as null', function () {
    $rule = ($this->save)(($this->thresholdData)(['name' => '', 'quiet_hours_start' => '', 'quiet_hours_end' => '']));

    expect($rule->name)->toBeNull()
        ->and($rule->quiet_hours_start)->toBeNull()
        ->and($rule->quiet_hours_end)->toBeNull();
});

test('the write is logged with the subscriber as the causer although there is no session', function () {
    ($this->save)(($this->thresholdData)());

    $activity = Activity::query()->where('subject_type', AlertRule::class)->latest('id')->firstOrFail();

    expect($activity->causer_id)->toBe($this->user->id);
});
