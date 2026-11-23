<?php

use App\Actions\Alerts\SaveAlertRule;
use App\Enums\AlertCategory;
use App\Enums\Tier;
use App\Exceptions\AlertRuleLimitReached;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Settings\TierThresholdSettings;
use Illuminate\Validation\ValidationException;

function limitTestUser(Tier $tier): User
{
    return User::factory()->create(['tier_override' => $tier]);
}

function setFreeAlertLimit(int $limit): void
{
    $settings = app(TierThresholdSettings::class);
    $settings->free_alerts = $limit;
    $settings->save();
}

test('creating a rule at the limit throws AlertRuleLimitReached', function () {
    $user = limitTestUser(Tier::Free);
    AlertRule::factory()->count(3)->for($user)->create();

    expect(fn () => AlertRule::factory()->for($user)->create())
        ->toThrow(AlertRuleLimitReached::class, "You have reached your plan's limit of 3 alerts. Remove one or upgrade to add more.");
    expect($user->alertRules()->count())->toBe(3);
});

test('a paused rule counts', function () {
    $user = limitTestUser(Tier::Free);
    AlertRule::factory()->count(2)->for($user)->create();
    AlertRule::factory()->for($user)->create(['is_active' => false]);

    expect(fn () => AlertRule::factory()->for($user)->create())->toThrow(AlertRuleLimitReached::class);
});

test('a Free user over the limit can still edit, pause and delete', function () {
    $user = limitTestUser(Tier::Free);
    setFreeAlertLimit(7);
    $rules = AlertRule::factory()->count(7)->for($user)->create();
    setFreeAlertLimit(3);

    $rules[0]->update(['name' => 'Renamed']);
    $rules[1]->update(['is_active' => false]);
    $rules[2]->delete();

    expect($rules[0]->fresh()->name)->toBe('Renamed')
        ->and($rules[1]->fresh()->is_active)->toBeFalse()
        ->and($user->alertRules()->count())->toBe(6);
});

test('a Free user can create their first Outlook Report', function () {
    $user = limitTestUser(Tier::Free);

    $rule = AlertRule::factory()->outlookReport()->for($user)->create();

    expect($rule->exists)->toBeTrue();
});

test('a Free user cannot create a second Outlook Report', function () {
    $user = limitTestUser(Tier::Free);
    AlertRule::factory()->outlookReport()->for($user)->create();

    expect(fn () => AlertRule::factory()->outlookReport()->for($user)->create())
        ->toThrow(AlertRuleLimitReached::class, 'Your plan includes one Severe Weather Outlook Report, and you already have one.');
});

test('a Plus user can create several Outlook Reports', function () {
    $user = limitTestUser(Tier::Plus);

    AlertRule::factory()->outlookReport()->count(2)->for($user)->create();

    expect($user->alertRules()->where('category', AlertCategory::OutlookReport)->count())->toBe(2);
});

test('a Free user with an Outlook Report can edit it', function () {
    $user = limitTestUser(Tier::Free);
    $rule = AlertRule::factory()->outlookReport()->for($user)->create();

    $rule->update(['name' => 'Chase outlook', 'category' => AlertCategory::OutlookReport]);

    expect($rule->fresh()->name)->toBe('Chase outlook');
});

test('a Free user with an Outlook Report cannot switch another alert to Outlook', function () {
    $user = limitTestUser(Tier::Free);
    AlertRule::factory()->outlookReport()->for($user)->create();
    $other = AlertRule::factory()->for($user)->create();

    expect(fn () => $other->update(['category' => AlertCategory::OutlookReport]))
        ->toThrow(AlertRuleLimitReached::class, 'Your plan includes one Severe Weather Outlook Report, and you already have one.');
    expect($other->fresh()->category)->not->toBe(AlertCategory::OutlookReport);
});

test('a Free user with no Outlook Report can switch an alert to Outlook', function () {
    $user = limitTestUser(Tier::Free);
    $rule = AlertRule::factory()->for($user)->create();

    $rule->update(['category' => AlertCategory::OutlookReport]);

    expect($rule->fresh()->category)->toBe(AlertCategory::OutlookReport);
});

describe('SaveAlertRule', function () {
    beforeEach(function () {
        $this->user = limitTestUser(Tier::Free);
        $this->channel = NotificationChannel::factory()->for($this->user)->create();
        $this->outlookInput = ['category' => 'outlook_report', 'delivery_time' => '14:30', 'report_mode' => 'every_day', 'channel_ids' => [$this->channel->id]];
    });

    test('gives a ValidationException on category with the limit message at the limit', function () {
        AlertRule::factory()->count(3)->for($this->user)->create();

        try {
            (new SaveAlertRule)($this->user, $this->outlookInput);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toBe(['category' => ["You have reached your plan's limit of 3 alerts. Remove one or upgrade to add more."]]);
        }

        expect($this->user->alertRules()->count())->toBe(3);
    });

    test('gives a ValidationException on category with the outlook message for a second Free Outlook Report', function () {
        (new SaveAlertRule)($this->user, $this->outlookInput);

        try {
            (new SaveAlertRule)($this->user, $this->outlookInput);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toBe(['category' => ['Your plan includes one Severe Weather Outlook Report, and you already have one.']]);
        }

        expect($this->user->alertRules()->count())->toBe(1);
    });

    test('gives the outlook message when switching another alert to Outlook, and saves the existing report', function () {
        $report = (new SaveAlertRule)($this->user, $this->outlookInput);
        $other = AlertRule::factory()->for($this->user)->create();

        expect(fn () => (new SaveAlertRule)($this->user, $this->outlookInput, $other))
            ->toThrow(ValidationException::class, 'Your plan includes one Severe Weather Outlook Report, and you already have one.');

        $saved = (new SaveAlertRule)($this->user, [...$this->outlookInput, 'name' => 'Renamed'], $report);
        expect($saved->name)->toBe('Renamed');
    });

    test('lets a user at the limit edit an existing alert', function () {
        $rules = AlertRule::factory()->count(3)->for($this->user)->create();

        $saved = (new SaveAlertRule)($this->user, ['name' => 'Renamed'], $rules[0]);

        expect($saved->name)->toBe('Renamed');
    });
});
