<?php

use App\Actions\Alerts\SaveAlertRule;
use App\Actions\Weather\RenderAlertSubject;
use App\Enums\AlertCategory;
use App\Enums\OutlookReportMode;
use App\Filament\Dashboard\Resources\AlertRules\Pages\ListAlertRules;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

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

test('moving the send time reads the clock in UTC, not the subscriber timezone', function () {
    // 15:00 UTC is 05:00 in Honolulu: a local-time reading would treat both new times as still ahead.
    Carbon::setTestNow('2026-11-02 15:00:00 UTC');
    $user = User::factory()->create(['timezone' => 'Pacific/Honolulu']);
    $rule = AlertRule::factory()->outlookReport()->for($user)->create([
        'delivery_time' => '14:30',
        'last_scheduled_run_on' => '2026-11-02',
    ]);

    $rule->update(['delivery_time' => '09:00']);
    expect($rule->fresh()->last_scheduled_run_on->toDateString())->toBe('2026-11-02');

    $rule->update(['delivery_time' => '16:00']);
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
    $user = User::factory()->create();
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
    $user = User::factory()->create();
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
