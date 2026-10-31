<?php

use App\Actions\Weather\RenderAlertSubject;
use App\Enums\AlertCategory;
use App\Enums\OutlookReportMode;
use App\Filament\Dashboard\Resources\AlertRules\Pages\ListAlertRules;
use App\Models\AlertRule;
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
