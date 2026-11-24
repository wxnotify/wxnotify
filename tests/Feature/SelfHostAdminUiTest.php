<?php

use App\Enums\AnnouncementScope;
use App\Enums\Tier;
use App\Filament\Admin\Pages\AppSettings;
use App\Filament\Admin\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Admin\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Admin\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\Announcement;
use App\Models\User;
use App\Settings\TierThresholdSettings;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['is_staff' => true]));
});

test('app settings shows the tier tab in cloud mode', function () {
    livewire(AppSettings::class)
        ->assertSee('Tier & threshold defaults')
        ->assertFormFieldExists('free_alerts')
        ->assertFormFieldExists('lightning_min_radius_miles');
});

test('app settings hides the tier tab but keeps the lightning radius in self-host mode', function () {
    config(['wxnotify.mode' => 'selfhost']);

    livewire(AppSettings::class)
        ->assertDontSee('Tier & threshold defaults')
        ->assertFormFieldDoesNotExist('free_alerts')
        ->assertFormFieldDoesNotExist('plus_locations')
        ->assertFormFieldExists('lightning_min_radius_miles');
});

test('saving app settings in self-host mode succeeds and leaves stored tier values untouched', function () {
    config(['wxnotify.mode' => 'selfhost']);
    $tiers = app(TierThresholdSettings::class);
    $tiers->free_alerts = 7;
    $tiers->plus_locations = 33;
    $tiers->save();

    livewire(AppSettings::class)
        ->fillForm(['lightning_min_radius_miles' => 12])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = app(TierThresholdSettings::class);
    expect($fresh->free_alerts)->toBe(7)
        ->and($fresh->plus_locations)->toBe(33)
        ->and($fresh->lightning_min_radius_miles)->toBe(12);
});

test('the lightning radius still enforces its range in self-host mode', function () {
    config(['wxnotify.mode' => 'selfhost']);

    livewire(AppSettings::class)
        ->fillForm(['lightning_min_radius_miles' => 4])
        ->call('save')
        ->assertHasFormErrors(['lightning_min_radius_miles']);
});

test('the user form has the tier override fields in cloud mode', function () {
    $subscriber = User::factory()->create();

    livewire(EditUser::class, ['record' => $subscriber->getRouteKey()])
        ->assertFormFieldExists('tier_override')
        ->assertFormFieldExists('tier_override_reason');
});

test('the user form has no tier override fields in self-host mode and saves', function () {
    config(['wxnotify.mode' => 'selfhost']);
    $subscriber = User::factory()->create();

    livewire(EditUser::class, ['record' => $subscriber->getRouteKey()])
        ->assertFormFieldDoesNotExist('tier_override')
        ->assertFormFieldDoesNotExist('tier_override_reason')
        ->fillForm(['is_staff' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($subscriber->fresh()->is_staff)->toBeTrue();
});

test('the users table shows the tier column and filter only in cloud mode', function () {
    livewire(ListUsers::class)
        ->assertTableColumnExists('tier')
        ->assertTableFilterExists('tier_override');

    config(['wxnotify.mode' => 'selfhost']);

    livewire(ListUsers::class)
        ->assertTableColumnHidden('tier')
        ->assertTableFilterHidden('tier_override');
});

test('the announcement scope offers specific tiers only in cloud mode', function () {
    livewire(CreateAnnouncement::class)
        ->assertFormFieldExists('scope', fn ($field): bool => array_key_exists(AnnouncementScope::SpecificTiers->value, $field->getOptions()));

    config(['wxnotify.mode' => 'selfhost']);

    livewire(CreateAnnouncement::class)
        ->assertFormFieldExists('scope', fn ($field): bool => ! array_key_exists(AnnouncementScope::SpecificTiers->value, $field->getOptions()))
        ->assertFormFieldHidden('tiers');
});

test('an existing specific-tiers announcement still lists in self-host mode', function () {
    config(['wxnotify.mode' => 'selfhost']);
    $announcement = Announcement::create([
        'title' => 'Legacy tier notice',
        'body' => 'Body.',
        'type' => 'info',
        'scope' => AnnouncementScope::SpecificTiers,
        'tiers' => ['free'],
        'is_active' => true,
        'is_dismissible' => true,
    ]);

    livewire(ListAnnouncements::class)->assertSee($announcement->title);
});

test('a legacy specific-tiers announcement is shown to every subscriber when self-hosted, and still targeted in cloud mode', function () {
    $announcement = Announcement::create([
        'title' => 'Legacy tier notice',
        'body' => 'Body.',
        'type' => 'info',
        'scope' => AnnouncementScope::SpecificTiers,
        'tiers' => ['free'],
        'is_active' => true,
        'is_dismissible' => true,
    ]);
    $pro = User::factory()->create(['tier_override' => Tier::Pro]);

    expect(Announcement::visibleToUser($pro)->pluck('id'))->not->toContain($announcement->id);

    config(['wxnotify.mode' => 'selfhost']);

    expect(Announcement::visibleToUser($pro)->pluck('id'))->toContain($announcement->id);
});

test('the announcements table labels a legacy specific-tiers audience in self-host mode', function () {
    config(['wxnotify.mode' => 'selfhost']);
    $announcement = Announcement::create([
        'title' => 'Legacy tier notice',
        'body' => 'Body.',
        'type' => 'info',
        'scope' => AnnouncementScope::SpecificTiers,
        'tiers' => ['free'],
        'is_active' => true,
        'is_dismissible' => true,
    ]);

    livewire(ListAnnouncements::class)
        ->assertTableColumnFormattedStateSet('scope', 'Specific tiers (shown to everyone when self-hosted)', $announcement);
});

test('editing a legacy specific-tiers announcement in self-host mode opens on All subscribers', function () {
    config(['wxnotify.mode' => 'selfhost']);
    $announcement = Announcement::create([
        'title' => 'Legacy tier notice',
        'body' => 'Body.',
        'type' => 'info',
        'scope' => AnnouncementScope::SpecificTiers,
        'tiers' => ['free'],
        'is_active' => true,
        'is_dismissible' => true,
    ]);

    livewire(EditAnnouncement::class, ['record' => $announcement->id])
        ->assertFormSet(['scope' => AnnouncementScope::AllSubscribers->value]);
});

test('saving the user form in self-host mode keeps an existing tier override', function () {
    config(['wxnotify.mode' => 'selfhost']);
    $subscriber = User::factory()->create(['tier_override' => Tier::Plus, 'tier_override_reason' => 'Beta gift']);

    livewire(EditUser::class, ['record' => $subscriber->getRouteKey()])
        ->fillForm(['is_staff' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $subscriber->fresh();
    expect($fresh->tier_override)->toBe(Tier::Plus)
        ->and($fresh->tier_override_reason)->toBe('Beta gift');
});

test('the Heads Up settings description does not mention an entitlement gate', function () {
    livewire(AppSettings::class)->assertDontSee('entitlement that gates');
});
