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
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(ProductCatalogSeeder::class);

    $this->user = User::factory()->create();
    $this->location = Location::factory()->for($this->user)->create();
    $this->channel = NotificationChannel::factory()->for($this->user)->create();
    $this->tornado = Product::where('code', 'TO.W')->sole();
    $this->severe = Product::where('code', 'SV.W')->sole();
    $this->flood = Product::where('code', 'FF.W')->sole();
    $this->riskProduct = Product::where('family', 'spc_wpc')->whereNotNull('risk_level_options')->orderBy('id')->firstOrFail();

    $this->pivotRows = fn (AlertRule $rule): array => DB::table('alert_rule_product')->where('alert_rule_id', $rule->id)->orderBy('product_id')
        ->get(['product_id', 'matching_mode', 'wfo', 'county_zone_codes'])
        ->map(fn (object $row): array => ['product_id' => (int) $row->product_id, 'matching_mode' => $row->matching_mode, 'wfo' => $row->wfo, 'county_zone_codes' => $row->county_zone_codes])
        ->all();
    $this->productLocationRows = fn (AlertRule $rule): array => DB::table('alert_rule_product_location')->where('alert_rule_id', $rule->id)->orderBy('product_id')->orderBy('location_id')
        ->get()
        ->map(fn (object $row): array => [(int) $row->product_id, (int) $row->location_id])
        ->all();

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
        ->and($nested->settingsFor($this->riskProduct->id)->locations->pluck('id')->all())->toBe([$this->location->id]);
});

test('flat risk levels are stored on each product, limited to the levels that product offers', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $rainfall = Product::where('code', 'WPC.ERO')->sole();

    $saved = ($this->save)([
        'category' => 'spc_wpc',
        'product_ids' => [$outlook->id, $rainfall->id],
        'matching_mode' => 'nationwide',
        'risk_levels' => ['Moderate', 'Enhanced'],
        'channel_ids' => [$this->channel->id],
    ]);

    expect($saved->riskLevelsFor($outlook->id))->toBe(['Enhanced', 'Moderate'])
        ->and($saved->riskLevelsFor($rainfall->id))->toBe(['Moderate']);
});

test('a product\'s own risk levels in product_settings are stored for that product alone', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $rainfall = Product::where('code', 'WPC.ERO')->sole();
    $data = [
        'category' => 'spc_wpc',
        'product_settings' => [
            ['product_id' => $outlook->id, 'matching_mode' => 'nationwide', 'risk_levels' => ['High']],
            ['product_id' => $rainfall->id, 'matching_mode' => 'nationwide', 'risk_levels' => ['Moderate', 'Enhanced']],
        ],
        'risk_levels' => ['Slight'],
        'channel_ids' => [$this->channel->id],
    ];

    $saved = ($this->save)($data);

    expect($saved->riskLevelsFor($outlook->id))->toBe(['High'])
        ->and($saved->riskLevelsFor($rainfall->id))->toBe(['Moderate'])
        ->and($saved->risk_levels)->toBe(['High', 'Moderate']);
});

test('the matching mode decides whether the office and the county/zone codes are kept', function () {
    $wfo = ($this->save)(($this->weatherData)(['matching_mode' => 'wfo', 'wfo' => 'OKX', 'county_zone_codes' => ['NYC103']]));
    $codes = ($this->save)(($this->weatherData)(['matching_mode' => 'county_zone', 'wfo' => 'OKX', 'county_zone_codes' => ['NYC103', 'NYZ072']]));
    $string = ($this->save)(($this->weatherData)(['matching_mode' => 'county_zone', 'county_zone_codes' => 'NYC103,NYZ072']));

    $wfo = $wfo->settingsFor($this->tornado->id);
    $codes = $codes->settingsFor($this->tornado->id);
    $string = $string->settingsFor($this->tornado->id);

    expect($wfo->wfo)->toBe('OKX')
        ->and($wfo->countyZoneCodes)->toBe([])
        ->and($codes->wfo)->toBeNull()
        ->and($codes->countyZoneCodes)->toBe(['NYC103', 'NYZ072'])
        ->and($string->countyZoneCodes)->toBe(['NYC103', 'NYZ072'])
        ->and($wfo->mode)->toBe(MatchingMode::Wfo);
});

test('only the matching modes that use locations keep the chosen locations', function (string $mode, bool $kept) {
    $rule = ($this->save)(($this->weatherData)(['matching_mode' => $mode, 'wfo' => 'OKX', 'county_zone_codes' => ['NYC103'], 'location_ids' => [$this->location->id]]));

    expect($rule->settingsFor($this->tornado->id)->locations->pluck('id')->all())->toBe($kept ? [$this->location->id] : []);
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

    expect($rule->settingsFor($this->tornado->id)->locations)->toBeEmpty();
});

test('changing the category clears the columns and relations that no longer apply', function () {
    $weather = ($this->save)(($this->weatherData)(['matching_mode' => 'location', 'location_ids' => [$this->location->id]]));

    $daily = ($this->save)(['category' => 'daily_summary', 'location_id' => $this->location->id, 'delivery_time' => '07:00', 'channel_ids' => [$this->channel->id]], $weather);

    expect($daily->matching_mode)->toBeNull()
        ->and($daily->products)->toBeEmpty()
        ->and($daily->locations)->toBeEmpty()
        ->and($daily->productLocations)->toBeEmpty()
        ->and($daily->location_id)->toBe($this->location->id);

    $threshold = ($this->save)(($this->thresholdData)(), $daily);
    $back = ($this->save)(($this->weatherData)(), $threshold);

    expect($back->variable)->toBeNull()
        ->and($back->direction)->toBeNull()
        ->and($back->threshold_value)->toBeNull()
        ->and($back->timeframe)->toBeNull()
        ->and($back->location_id)->toBeNull()
        ->and($back->delivery_time)->toBeNull()
        ->and($back->settingsFor($this->tornado->id)->mode)->toBe(MatchingMode::Nationwide);
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

test('product_settings writes each product\'s own mode, office, zones and locations', function () {
    $other = Location::factory()->for($this->user)->create();

    $rule = ($this->save)([
        'category' => 'weather_alerts',
        'product_settings' => [
            ['product_id' => $this->tornado->id, 'matching_mode' => 'polygon', 'location_ids' => [$other->id, $this->location->id, $other->id]],
            ['product_id' => $this->severe->id, 'matching_mode' => 'wfo', 'wfo' => 'OKX'],
            ['product_id' => $this->flood->id, 'matching_mode' => 'county_zone', 'county_zone_codes' => ['NYC103', 'NYZ072']],
        ],
        'channel_ids' => [$this->channel->id],
    ]);

    expect(($this->pivotRows)($rule))->toEqualCanonicalizing([
        ['product_id' => $this->tornado->id, 'matching_mode' => 'polygon', 'wfo' => null, 'county_zone_codes' => null],
        ['product_id' => $this->severe->id, 'matching_mode' => 'wfo', 'wfo' => 'OKX', 'county_zone_codes' => null],
        ['product_id' => $this->flood->id, 'matching_mode' => 'county_zone', 'wfo' => null, 'county_zone_codes' => 'NYC103,NYZ072'],
    ])
        ->and(($this->productLocationRows)($rule))->toEqualCanonicalizing([
            [$this->tornado->id, $this->location->id],
            [$this->tornado->id, $other->id],
        ])
        ->and($rule->settingsFor($this->tornado->id)->locations->pluck('id')->all())->toBe([$this->location->id, $other->id]);
});

test('the flat input expands to one row per product with the same mode and settings', function () {
    $rule = ($this->save)(($this->weatherData)([
        'product_ids' => [$this->tornado->category->value => [$this->tornado->id, $this->severe->id], $this->flood->category->value => [$this->flood->id]],
        'matching_mode' => 'location',
        'location_ids' => [$this->location->id],
    ]));

    expect(($this->pivotRows)($rule))->toEqualCanonicalizing(collect([$this->tornado, $this->severe, $this->flood])->map(fn (Product $product): array => [
        'product_id' => $product->id, 'matching_mode' => 'location', 'wfo' => null, 'county_zone_codes' => null,
    ])->all())
        ->and(($this->productLocationRows)($rule))->toEqualCanonicalizing([
            [$this->tornado->id, $this->location->id],
            [$this->severe->id, $this->location->id],
            [$this->flood->id, $this->location->id],
        ]);
});

test('settings a product\'s mode does not use are not stored', function () {
    $rule = ($this->save)([
        'category' => 'weather_alerts',
        'product_settings' => [
            ['product_id' => $this->tornado->id, 'matching_mode' => 'nationwide', 'location_ids' => [$this->location->id], 'wfo' => 'OKX', 'county_zone_codes' => ['NYC103']],
            ['product_id' => $this->severe->id, 'matching_mode' => 'location', 'location_ids' => [$this->location->id], 'wfo' => 'OKX', 'county_zone_codes' => 'NYC103'],
        ],
        'channel_ids' => [$this->channel->id],
    ]);

    expect(($this->pivotRows)($rule))->toEqualCanonicalizing([
        ['product_id' => $this->tornado->id, 'matching_mode' => 'nationwide', 'wfo' => null, 'county_zone_codes' => null],
        ['product_id' => $this->severe->id, 'matching_mode' => 'location', 'wfo' => null, 'county_zone_codes' => null],
    ])
        ->and(($this->productLocationRows)($rule))->toBe([[$this->severe->id, $this->location->id]]);
});

test('re-saving with a changed list leaves no product or location rows behind', function () {
    $other = Location::factory()->for($this->user)->create();
    $rule = ($this->save)([
        'category' => 'weather_alerts',
        'product_settings' => [
            ['product_id' => $this->tornado->id, 'matching_mode' => 'location', 'location_ids' => [$this->location->id, $other->id]],
            ['product_id' => $this->severe->id, 'matching_mode' => 'polygon', 'location_ids' => [$this->location->id]],
        ],
        'channel_ids' => [$this->channel->id],
    ]);

    $rule = ($this->save)([
        'category' => 'weather_alerts',
        'product_settings' => [
            ['product_id' => $this->tornado->id, 'matching_mode' => 'location', 'location_ids' => [$other->id]],
            ['product_id' => $this->flood->id, 'matching_mode' => 'nationwide'],
        ],
        'channel_ids' => [$this->channel->id],
    ], $rule);

    expect(($this->pivotRows)($rule))->toEqualCanonicalizing([
        ['product_id' => $this->tornado->id, 'matching_mode' => 'location', 'wfo' => null, 'county_zone_codes' => null],
        ['product_id' => $this->flood->id, 'matching_mode' => 'nationwide', 'wfo' => null, 'county_zone_codes' => null],
    ])
        ->and(($this->productLocationRows)($rule))->toBe([[$this->tornado->id, $other->id]]);
});

test('a product-based rule no longer stores its own mode, office, zones or locations', function (array $flat) {
    $rule = ($this->save)(($this->weatherData)(['location_ids' => [$this->location->id], ...$flat]));

    expect($rule->matching_mode)->toBeNull()
        ->and($rule->wfo)->toBeNull()
        ->and($rule->county_zone_codes)->toBeNull()
        ->and(DB::table('alert_rule_location')->where('alert_rule_id', $rule->id)->exists())->toBeFalse()
        ->and($rule->settingsFor($this->tornado->id)->inherited)->toBeFalse();
})->with([
    'location' => [['matching_mode' => 'location']],
    'office' => [['matching_mode' => 'wfo', 'wfo' => 'OKX']],
    'county and zone' => [['matching_mode' => 'county_zone', 'county_zone_codes' => ['NYC103']]],
]);

test('changing the category to threshold clears every product row and per-product location', function () {
    $rule = ($this->save)(($this->weatherData)(['matching_mode' => 'location', 'location_ids' => [$this->location->id]]));

    $threshold = ($this->save)(($this->thresholdData)(), $rule);

    expect(($this->pivotRows)($threshold))->toBe([])
        ->and(($this->productLocationRows)($threshold))->toBe([]);
});

test('a stored mixed rule saved without any product fields keeps its products, their settings and its risk levels', function () {
    $plain = Product::where('family', 'spc_wpc')->whereNull('risk_level_options')->orderBy('id')->firstOrFail();
    $levels = array_slice($this->riskProduct->risk_level_options, 0, 2);
    $rule = ($this->save)([
        'category' => 'spc_wpc',
        'product_settings' => [
            ['product_id' => $this->riskProduct->id, 'matching_mode' => 'risk_area', 'location_ids' => [$this->location->id]],
            ['product_id' => $plain->id, 'matching_mode' => 'nationwide'],
        ],
        'risk_levels' => $levels,
        'channel_ids' => [$this->channel->id],
    ]);
    $pivotRows = ($this->pivotRows)($rule);
    $locationRows = ($this->productLocationRows)($rule);
    $otherChannel = NotificationChannel::factory()->for($this->user)->create();

    $saved = ($this->save)(['name' => 'Renamed', 'channel_ids' => [$otherChannel->id]], $rule);

    expect(app(SaveAlertRule::class)->productSettings(['name' => 'Renamed']))->toBeNull()
        ->and($saved->name)->toBe('Renamed')
        ->and($saved->notificationChannels->pluck('id')->all())->toBe([$otherChannel->id])
        ->and($saved->risk_levels)->toBe($levels)
        ->and(($this->pivotRows)($saved))->toBe($pivotRows)
        ->and(($this->productLocationRows)($saved))->toBe($locationRows);
});

test('an old-style rule saved without any product fields keeps its own mode and locations', function () {
    $rule = AlertRule::factory()->for($this->user)->create(['category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => null]);
    $rule->products()->attach($this->tornado->id);
    $rule->locations()->attach($this->location->id);

    $saved = ($this->save)(['name' => 'Renamed', 'channel_ids' => [$this->channel->id]], $rule);

    expect($saved->matching_mode)->toBe(MatchingMode::Location)
        ->and($saved->locations->pluck('id')->all())->toBe([$this->location->id])
        ->and($saved->settingsFor($this->tornado->id)->inherited)->toBeTrue();
});

test('the write is logged with the subscriber as the causer although there is no session', function () {
    ($this->save)(($this->thresholdData)());

    $activity = Activity::query()->where('subject_type', AlertRule::class)->latest('id')->firstOrFail();

    expect($activity->causer_id)->toBe($this->user->id);
});

test('saving an old-style rule through the per-product settings does not log its own matching columns going to null', function () {
    $rule = AlertRule::factory()->for($this->user)->create(['category' => 'weather_alerts', 'matching_mode' => 'wfo', 'wfo' => 'TBW', 'location_id' => null]);
    $rule->products()->attach($this->tornado->id);
    Activity::query()->delete();

    ($this->save)([
        'category' => 'weather_alerts',
        'name' => 'Renamed',
        'channel_ids' => [$this->channel->id],
        'product_settings' => [['product_id' => $this->tornado->id, 'matching_mode' => 'nationwide']],
    ], $rule);

    $logged = Activity::query()->where('subject_type', AlertRule::class)->where('subject_id', $rule->id)->get()
        ->flatMap(fn (Activity $activity): array => array_keys($activity->attribute_changes['attributes'] ?? []))
        ->unique()
        ->all();

    expect($logged)->toContain('name')
        ->and($logged)->not->toContain('matching_mode')
        ->and($logged)->not->toContain('wfo')
        ->and($logged)->not->toContain('county_zone_codes');
});

test('a product\'s locations are only ever the rule owner\'s own', function () {
    $othersLocation = Location::factory()->for(User::factory())->create();

    $rule = ($this->save)([
        'category' => 'weather_alerts',
        'product_settings' => [['product_id' => $this->tornado->id, 'matching_mode' => 'polygon', 'location_ids' => [$this->location->id, $othersLocation->id]]],
        'channel_ids' => [$this->channel->id],
    ]);

    expect(($this->productLocationRows)($rule))->toBe([[$this->tornado->id, $this->location->id]]);
});

describe('per-product custom subjects', function () {
    beforeEach(function () {
        $this->user->forceFill(['tier_override' => Tier::Plus])->save();

        $this->subjectRows = fn (AlertRule $rule): array => DB::table('alert_rule_product')->where('alert_rule_id', $rule->id)->orderBy('product_id')
            ->pluck('custom_subject_template', 'product_id')
            ->map(fn ($subject): ?string => $subject)
            ->all();

        $this->subjectData = fn (array $subjects): array => ['category' => 'weather_alerts', 'channel_ids' => [$this->channel->id], 'product_settings' => [
            ['product_id' => $this->tornado->id, 'matching_mode' => 'nationwide', ...$subjects[0]],
            ['product_id' => $this->severe->id, 'matching_mode' => 'nationwide', ...$subjects[1]],
        ]];
    });

    test('a subject is trimmed and stored on its own product alone; blank ones store null', function () {
        $rule = ($this->save)(($this->subjectData)([['custom_subject_template' => '  Tornado: {{ event }}  '], ['custom_subject_template' => '   ']]));
        $blank = ($this->save)(($this->subjectData)([['custom_subject_template' => ''], ['custom_subject_template' => 'Severe']]));

        expect(($this->subjectRows)($rule))->toBe([$this->tornado->id => 'Tornado: {{ event }}', $this->severe->id => null])
            ->and(($this->subjectRows)($blank))->toBe([$this->tornado->id => null, $this->severe->id => 'Severe']);
    });

    test('entries that omit the key keep the stored subjects, and an explicit null clears only its product', function () {
        $rule = ($this->save)(($this->subjectData)([['custom_subject_template' => 'One'], ['custom_subject_template' => 'Two']]));

        ($this->save)(($this->subjectData)([[], []]), $rule);
        expect(($this->subjectRows)($rule))->toBe([$this->tornado->id => 'One', $this->severe->id => 'Two']);

        ($this->save)(($this->subjectData)([['custom_subject_template' => null], []]), $rule);
        expect(($this->subjectRows)($rule))->toBe([$this->tornado->id => null, $this->severe->id => 'Two']);
    });

    test('the flat product_ids shape keeps stored subjects, gives a new product none and drops removed ones', function () {
        $rule = ($this->save)(($this->subjectData)([['custom_subject_template' => 'One'], ['custom_subject_template' => 'Two']]));

        ($this->save)(($this->weatherData)(['product_ids' => [$this->tornado->category->value => [$this->tornado->id, $this->flood->id]]]), $rule);

        expect(($this->subjectRows)($rule))->toBe([$this->tornado->id => 'One', $this->flood->id => null]);
    });

    test('customSubjectFor returns the subject for a paid owner and null otherwise', function () {
        $rule = ($this->save)(($this->subjectData)([['custom_subject_template' => 'One'], ['custom_subject_template' => ' ']]));
        $rule = $rule->fresh();

        expect($rule->customSubjectFor($this->tornado->id))->toBe('One')
            ->and($rule->customSubjectFor($this->severe->id))->toBeNull()
            ->and($rule->customSubjectFor($this->flood->id))->toBeNull();

        $this->user->forceFill(['tier_override' => Tier::Free])->save();

        expect($rule->fresh()->customSubjectFor($this->tornado->id))->toBeNull()
            ->and(($this->subjectRows)($rule)[$this->tornado->id])->toBe('One');
    });
});
