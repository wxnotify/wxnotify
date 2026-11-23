<?php

use App\Actions\Alerts\SaveAlertRule;
use App\Actions\Weather\RenderAlertSubject;
use App\Enums\AlertCategory;
use App\Enums\OutlookReportMode;
use App\Enums\Tier;
use App\Filament\Dashboard\Resources\AlertRules\Pages\ListAlertRules;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\ProductCatalogSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Livewire\livewire;

afterEach(function () {
    Carbon::setTestNow();
});

test('an outlook report rule stores its category and mode and names itself by the category label', function () {
    $rule = AlertRule::factory()->outlookReport()->create();

    expect($rule->fresh())
        ->category->toBe(AlertCategory::OutlookReport)
        ->report_mode->toBe(OutlookReportMode::EveryDay)
        ->location_id->toBeNull()
        ->and($rule->displayName())->toBe('Severe Weather Outlook Report');

    $rule->update(['name' => 'Chase outlook']);

    expect($rule->fresh()->displayName())->toBe('Chase outlook');
});

test('the send time is the delivery time, defaulting to 14:30', function () {
    $rule = AlertRule::factory()->outlookReport()->make(['delivery_time' => '08:05:00']);
    expect($rule->outlookSendTime())->toBe('08:05');

    $rule->delivery_time = null;
    expect($rule->outlookSendTime())->toBe('14:30');
});

test('moving the send time reads the clock in the owner\'s local time, not UTC', function () {
    // 15:00 UTC is 05:00 in Honolulu: 09:00 is still ahead there (it has passed in UTC), 04:45 is not.
    Carbon::setTestNow('2026-11-02 15:00:00 UTC');
    $user = User::factory()->create(['timezone' => 'Pacific/Honolulu']);
    $rule = AlertRule::factory()->outlookReport()->for($user)->create([
        'delivery_time' => '04:30',
        'last_scheduled_run_on' => '2026-11-02',
    ]);

    $rule->update(['delivery_time' => '04:45']);
    expect($rule->fresh()->last_scheduled_run_on->toDateString())->toBe('2026-11-02');

    $rule->update(['delivery_time' => '09:00']);
    expect($rule->fresh()->last_scheduled_run_on)->toBeNull();
});

test('the outlook report is listed right after SPC & WPC', function () {
    $cases = AlertCategory::cases();
    $position = array_search(AlertCategory::SpcWpc, $cases, true);

    $category = AlertCategory::OutlookReport;

    expect($cases[$position + 1])->toBe($category)
        ->and($category->value)->toBe('outlook_report')
        ->and($category->label())->toBe('Severe Weather Outlook Report')
        ->and($category->icon())->toBe('heroicon-o-cloud');
});

test('every category renders in the alerts table and the subject renderer', function (AlertCategory $category) {
    Filament::setCurrentPanel('dashboard');
    $user = User::factory()->create();
    $this->actingAs($user);
    $rule = AlertRule::factory()->for($user)->create(['category' => $category]);

    livewire(ListAlertRules::class)
        ->assertCanSeeTableRecords([$rule])
        ->assertSee($category->label());

    expect((new RenderAlertSubject)($rule))->toBeString()->not->toBeEmpty();
})->with(fn () => AlertCategory::cases());

test('saving an outlook report stores the time and mode and only the owner\'s locations', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $mine = Location::factory()->for($user)->create();
    $theirs = Location::factory()->create();
    $channel = NotificationChannel::factory()->for($user)->create();

    $rule = (new SaveAlertRule)($user, [
        'category' => 'outlook_report',
        'delivery_time' => '08:05',
        'report_mode' => 'only_when_forecast',
        'location_ids' => [$mine->id, $theirs->id],
        'channel_ids' => [$channel->id],
    ]);

    expect($rule)
        ->category->toBe(AlertCategory::OutlookReport)
        ->report_mode->toBe(OutlookReportMode::OnlyWhenForecast)
        ->location_id->toBeNull()
        ->and($rule->outlookSendTime())->toBe('08:05')
        ->and($rule->locations->pluck('id')->all())->toBe([$mine->id])
        ->and($rule->notificationChannels->pluck('id')->all())->toBe([$channel->id]);
});

test('switching an outlook report to another category clears its mode and locations', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $location = Location::factory()->for($user)->create();
    $channel = NotificationChannel::factory()->for($user)->create();
    $rule = (new SaveAlertRule)($user, [
        'category' => 'outlook_report', 'delivery_time' => '08:05', 'report_mode' => 'every_day',
        'location_ids' => [$location->id], 'channel_ids' => [$channel->id],
    ]);

    $rule = (new SaveAlertRule)($user, [
        'category' => 'daily_summary', 'location_id' => $location->id, 'delivery_time' => '07:00', 'channel_ids' => [$channel->id],
    ], $rule);

    expect($rule->category)->toBe(AlertCategory::DailySummary)
        ->and($rule->report_mode)->toBeNull()
        ->and($rule->locations)->toBeEmpty()
        ->and($rule->location_id)->toBe($location->id);
});

test('the API refuses to write an outlook report and leaves it out of the options', function () {
    $user = User::factory()->create();
    $token = $user->createToken('Pixel 9', ['app'])->plainTextToken;
    $location = Location::factory()->for($user)->create();
    $channel = NotificationChannel::factory()->for($user)->create();

    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/alert-rules', [
        'category' => 'outlook_report', 'delivery_time' => '08:05', 'report_mode' => 'every_day',
        'location_ids' => [$location->id], 'channel_ids' => [$channel->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('category');

    app('auth')->forgetGuards();
    $values = collect($this->withToken($token)->getJson('/api/v1/alert-rule-options')->assertOk()->json('data.categories'))->pluck('value');

    expect($values)->not->toContain('outlook_report')->toContain('daily_summary');
});

test('the API still lists and shows a dashboard-made outlook report', function () {
    $user = User::factory()->create();
    $token = $user->createToken('Pixel 9', ['app'])->plainTextToken;
    $rule = AlertRule::factory()->outlookReport()->for($user)->create();

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/alert-rules')->assertOk()
        ->assertJsonPath('data.0.id', $rule->id)
        ->assertJsonPath('data.0.category', 'outlook_report')
        ->assertJsonPath('data.0.category_label', 'Severe Weather Outlook Report');
});

test('the API refuses a PUT that makes a rule an outlook report, and can move one to another category', function () {
    $user = User::factory()->create();
    $token = $user->createToken('Pixel 9', ['app'])->plainTextToken;
    $location = Location::factory()->for($user)->create();
    $channel = NotificationChannel::factory()->for($user)->create();
    $rule = AlertRule::factory()->outlookReport()->for($user)->create();
    $rule->locations()->sync([$location->id]);

    app('auth')->forgetGuards();
    $this->withToken($token)->putJson("/api/v1/alert-rules/{$rule->id}", [
        'category' => 'outlook_report', 'delivery_time' => '08:05', 'report_mode' => 'every_day',
        'location_ids' => [$location->id], 'channel_ids' => [$channel->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('category');

    app('auth')->forgetGuards();
    $this->withToken($token)->putJson("/api/v1/alert-rules/{$rule->id}", [
        'category' => 'daily_summary', 'location_id' => $location->id, 'delivery_time' => '07:00', 'channel_ids' => [$channel->id],
    ])->assertOk()->assertJsonPath('data.category', 'daily_summary');

    expect($rule->fresh())->report_mode->toBeNull()
        ->and($rule->locations()->count())->toBe(0);
});

test('switching a product rule to an outlook report drops its products and keeps only the owner\'s locations', function () {
    $this->seed(ProductCatalogSeeder::class);
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $mine = Location::factory()->for($user)->create();
    $theirs = Location::factory()->create();
    $channel = NotificationChannel::factory()->for($user)->create();
    $product = Product::where('family', 'spc_wpc')->whereNotNull('risk_level_options')->orderBy('id')->firstOrFail();

    $rule = (new SaveAlertRule)($user, [
        'category' => 'spc_wpc',
        'product_settings' => [['product_id' => $product->id, 'matching_mode' => 'risk_area', 'location_ids' => [$mine->id]]],
        'risk_levels' => array_slice($product->risk_level_options, 0, 2),
        'channel_ids' => [$channel->id],
    ]);
    expect(DB::table('alert_rule_product_location')->where('alert_rule_id', $rule->id)->count())->toBe(1);

    $rule = (new SaveAlertRule)($user, [
        'category' => 'outlook_report', 'delivery_time' => '09:15', 'report_mode' => 'every_day',
        'location_ids' => [$mine->id, $theirs->id], 'channel_ids' => [$channel->id],
    ], $rule);

    expect($rule->products)->toBeEmpty()
        ->and(DB::table('alert_rule_product_location')->where('alert_rule_id', $rule->id)->count())->toBe(0)
        ->and($rule)->matching_mode->toBeNull()->wfo->toBeNull()->county_zone_codes->toBeNull()->risk_levels->toBeNull()
        ->and($rule->report_mode)->toBe(OutlookReportMode::EveryDay)
        ->and($rule->outlookSendTime())->toBe('09:15')
        ->and($rule->locations->pluck('id')->all())->toBe([$mine->id]);
});

test('SaveAlertRule saves a Free subscriber\'s first outlook report and refuses a second', function () {
    $user = User::factory()->create(['tier_override' => null]);
    $channel = NotificationChannel::factory()->for($user)->create();
    $input = ['category' => 'outlook_report', 'delivery_time' => '14:30', 'report_mode' => 'every_day', 'channel_ids' => [$channel->id]];

    $first = (new SaveAlertRule)($user, $input);
    expect($first->category)->toBe(AlertCategory::OutlookReport);

    expect(fn () => (new SaveAlertRule)($user, $input))
        ->toThrow(ValidationException::class, 'Your plan includes one Severe Weather Outlook Report, and you already have one.');
    expect(AlertRule::count())->toBe(1);

    $renamed = (new SaveAlertRule)($user, ['name' => 'Renamed'], $first);
    expect($renamed->name)->toBe('Renamed');
});

test('SaveAlertRule saves an outlook report for Plus and Pro subscribers', function (Tier $tier) {
    $user = User::factory()->create(['tier_override' => $tier]);
    $channel = NotificationChannel::factory()->for($user)->create();

    $rule = (new SaveAlertRule)($user, ['category' => 'outlook_report', 'delivery_time' => '14:30', 'report_mode' => 'every_day', 'channel_ids' => [$channel->id]]);

    expect($rule->category)->toBe(AlertCategory::OutlookReport);
})->with([Tier::Plus, Tier::Pro]);
