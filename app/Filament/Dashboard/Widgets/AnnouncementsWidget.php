<?php

namespace App\Filament\Dashboard\Widgets;

use App\Models\Announcement;
use Illuminate\Support\Collection;
use Marcelodelgado\Announcements\Filament\Widgets\AnnouncementsWidget as VendorAnnouncementsWidget;

/**
 * Only getAnnouncements() is overridden — mount(), the Blade view, polling,
 * and dismiss() are all inherited unchanged from the vendor widget. Adds
 * App\Models\Announcement::visibleToUser() (tier/specific-subscriber
 * targeting, absent from the vendor package) to the same
 * visibleForDashboard()/orderedForDisplay()/dismissal-exclusion query it
 * already built.
 */
class AnnouncementsWidget extends VendorAnnouncementsWidget
{
    /**
     * @return Collection<int, Announcement>
     */
    public function getAnnouncements(): Collection
    {
        $userId = auth()->id();

        return Announcement::query()
            ->visibleForDashboard()
            ->visibleToUser(auth()->user())
            ->orderedForDisplay()
            ->whereDoesntHave('users', function ($query) use ($userId): void {
                $query->where('users.id', $userId)
                    ->whereNotNull('announcement_user.dismissed_at');
            })
            ->get();
    }
}
