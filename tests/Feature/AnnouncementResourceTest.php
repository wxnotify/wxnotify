<?php

use App\Models\User;
use Filament\Facades\Filament;
use Marcelodelgado\Announcements\Filament\Resources\AnnouncementResource\Pages\CreateAnnouncement;
use Marcelodelgado\Announcements\Filament\Resources\AnnouncementResource\Pages\EditAnnouncement;
use Marcelodelgado\Announcements\Filament\Resources\AnnouncementResource\Pages\ListAnnouncements;
use Marcelodelgado\Announcements\Models\Announcement;

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
