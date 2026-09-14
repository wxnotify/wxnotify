<?php

use App\Enums\AnnouncementScope;
use App\Filament\Admin\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Admin\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Admin\Resources\Announcements\Pages\ListAnnouncements;
use App\Models\Announcement;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
});

test('a staff member can see seeded announcements in the table', function () {
    $announcement = Announcement::create([
        'title' => 'Scheduled maintenance',
        'body' => 'The dashboard will be briefly unavailable tonight.',
        'type' => 'warning',
        'is_active' => true,
        'is_dismissible' => true,
    ]);

    livewire(ListAnnouncements::class)
        ->assertSee($announcement->title);
});

test('a staff member can create an announcement', function () {
    livewire(CreateAnnouncement::class)
        ->fillForm([
            'title' => 'New feature: Threshold Alerts',
            'body' => 'You can now set up threshold-based alerts from My Alerts.',
            'type' => 'info',
            'is_active' => true,
            'is_dismissible' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    assertDatabaseHas('announcements', [
        'title' => 'New feature: Threshold Alerts',
        'type' => 'info',
    ]);
});

test('a staff member can edit an announcement', function () {
    $announcement = Announcement::create([
        'title' => 'Old title',
        'body' => 'Old body',
        'type' => 'info',
        'is_active' => true,
        'is_dismissible' => true,
    ]);

    livewire(EditAnnouncement::class, ['record' => $announcement->id])
        ->fillForm(['title' => 'Updated title', 'is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($announcement->fresh()->title)->toBe('Updated title')
        ->and($announcement->fresh()->is_active)->toBeFalse();
});

test('a non-staff user cannot reach the announcements resource', function () {
    $subscriber = User::factory()->create(['is_staff' => false]);

    $this->actingAs($subscriber)
        ->get('/admin/announcements')
        ->assertForbidden();
});

test('a staff member can create a tier-targeted announcement', function () {
    livewire(CreateAnnouncement::class)
        ->fillForm([
            'title' => 'Upgrade to Plus',
            'body' => 'Unlock more locations with Plus.',
            'type' => 'info',
            'scope' => 'specific_tiers',
            'tiers' => ['free'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $announcement = Announcement::where('title', 'Upgrade to Plus')->sole();

    expect($announcement->scope)->toBe(AnnouncementScope::SpecificTiers)
        ->and($announcement->tiers)->toBe(['free']);
});

test('tiers is required when the scope is specific_tiers', function () {
    livewire(CreateAnnouncement::class)
        ->fillForm([
            'title' => 'Upgrade to Plus',
            'body' => 'Unlock more locations with Plus.',
            'type' => 'info',
            'scope' => 'specific_tiers',
            'tiers' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['tiers']);
});

test('a staff member can create a user-targeted announcement', function () {
    $subscriber = User::factory()->create();

    livewire(CreateAnnouncement::class)
        ->fillForm([
            'title' => 'Your feedback needed',
            'body' => 'We would love to hear from you.',
            'type' => 'info',
            'scope' => 'specific_users',
            'targetedUsers' => [$subscriber->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $announcement = Announcement::where('title', 'Your feedback needed')->sole();

    expect($announcement->targetedUsers->pluck('id')->all())->toBe([$subscriber->id]);
});
