<?php

use App\Filament\Dashboard\Widgets\AnnouncementsWidget;
use App\Models\Announcement;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->subscriber = User::factory()->create();
    $this->actingAs($this->subscriber);
});

function makeAnnouncement(array $attributes = []): Announcement
{
    return Announcement::create([
        'title' => 'Announcement',
        'body' => 'Body',
        'type' => 'info',
        'is_active' => true,
        'is_dismissible' => true,
        ...$attributes,
    ]);
}

test('an active announcement is visible to a subscriber', function () {
    $announcement = makeAnnouncement();

    expect(
        livewire(AnnouncementsWidget::class)->instance()->getAnnouncements()->pluck('id')
    )->toContain($announcement->id);
});

test('an inactive announcement is not visible', function () {
    $announcement = makeAnnouncement(['is_active' => false]);

    expect(
        livewire(AnnouncementsWidget::class)->instance()->getAnnouncements()->pluck('id')
    )->not->toContain($announcement->id);
});

test('an announcement that has not started yet is not visible', function () {
    $announcement = makeAnnouncement(['starts_at' => now()->addDay()]);

    expect(
        livewire(AnnouncementsWidget::class)->instance()->getAnnouncements()->pluck('id')
    )->not->toContain($announcement->id);
});

test('an expired announcement is not visible', function () {
    $announcement = makeAnnouncement(['expires_at' => now()->subDay()]);

    expect(
        livewire(AnnouncementsWidget::class)->instance()->getAnnouncements()->pluck('id')
    )->not->toContain($announcement->id);
});

test('dismissing an announcement hides it from that subscriber only', function () {
    $announcement = makeAnnouncement();
    $otherSubscriber = User::factory()->create();

    livewire(AnnouncementsWidget::class)->call('dismiss', $announcement->id);

    expect(
        livewire(AnnouncementsWidget::class)->instance()->getAnnouncements()->pluck('id')
    )->not->toContain($announcement->id);

    $this->actingAs($otherSubscriber);

    expect(
        livewire(AnnouncementsWidget::class)->instance()->getAnnouncements()->pluck('id')
    )->toContain($announcement->id);
});

test('a non-dismissible announcement cannot be dismissed', function () {
    $announcement = makeAnnouncement(['is_dismissible' => false]);

    livewire(AnnouncementsWidget::class)->call('dismiss', $announcement->id);

    expect(
        livewire(AnnouncementsWidget::class)->instance()->getAnnouncements()->pluck('id')
    )->toContain($announcement->id);
});

test('a tier-targeted announcement is only visible to subscribers in that tier', function () {
    $announcement = makeAnnouncement(['scope' => 'specific_tiers', 'tiers' => ['plus']]);

    expect(
        livewire(AnnouncementsWidget::class)->instance()->getAnnouncements()->pluck('id')
    )->not->toContain($announcement->id);

    $this->subscriber->forceFill(['tier_override' => 'plus'])->save();

    expect(
        livewire(AnnouncementsWidget::class)->instance()->getAnnouncements()->pluck('id')
    )->toContain($announcement->id);
});

test('a user-targeted announcement is only visible to the targeted subscribers', function () {
    $announcement = makeAnnouncement(['scope' => 'specific_users']);
    $announcement->targetedUsers()->attach($this->subscriber->id);

    $otherSubscriber = User::factory()->create();

    expect(
        livewire(AnnouncementsWidget::class)->instance()->getAnnouncements()->pluck('id')
    )->toContain($announcement->id);

    $this->actingAs($otherSubscriber);

    expect(
        livewire(AnnouncementsWidget::class)->instance()->getAnnouncements()->pluck('id')
    )->not->toContain($announcement->id);
});
