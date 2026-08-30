<?php

use App\Filament\Admin\Resources\ActivityAlertRules\Pages\CreateActivityAlertRule;
use App\Filament\Admin\Resources\ActivityAlertRules\Pages\EditActivityAlertRule;
use App\Filament\Admin\Resources\ActivityAlertRules\Pages\ListActivityAlertRules;
use App\Models\ActivityAlertRule;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
});

test('the list page shows the not-yet-enforced notice', function () {
    livewire(ListActivityAlertRules::class)
        ->assertSee('not yet enforced');
});

test('a specific_users rule persists its attached users through the pivot', function () {
    $watched = User::factory()->create();

    livewire(CreateActivityAlertRule::class)
        ->fillForm([
            'name' => 'Account deletions',
            'scope' => 'specific_users',
            'users' => [$watched->id],
            'activity_types' => ['account_deleted'],
            'apprise_target' => 'slack://token/botname/channel',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = ActivityAlertRule::sole();

    expect($rule->scope->value)->toBe('specific_users')
        ->and($rule->users->pluck('id')->all())->toBe([$watched->id])
        ->and($rule->activity_types)->toBe(['account_deleted']);
});

test('an all_subscribers rule does not require any users attached', function () {
    livewire(CreateActivityAlertRule::class)
        ->fillForm([
            'scope' => 'all_subscribers',
            'activity_types' => ['account_deleted'],
            'apprise_target' => 'slack://token/botname/channel',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = ActivityAlertRule::sole();

    expect($rule->scope->value)->toBe('all_subscribers')
        ->and($rule->users)->toBeEmpty();
});

test('staff can edit an existing rule', function () {
    $rule = ActivityAlertRule::factory()->create(['name' => 'Old name']);

    livewire(EditActivityAlertRule::class, ['record' => $rule->getRouteKey()])
        ->fillForm(['name' => 'New name'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->name)->toBe('New name');
});
