<?php

namespace App\Models;

use App\Enums\AnnouncementScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Marcelodelgado\Announcements\Models\Announcement as VendorAnnouncement;

/**
 * Extends the vendor package's model rather than replacing it: the
 * announcements/announcement_user tables, casts, and dismissal-scoping
 * (visibleForDashboard()/orderedForDisplay()) it already defines are reused
 * as-is. This subclass only adds the targeting concept the package doesn't
 * have — scope/tiers columns (added by a local migration) and a dedicated
 * announcement_targeted_users pivot, kept separate from the vendor's own
 * announcement_user dismissal pivot.
 */
class Announcement extends VendorAnnouncement
{
    protected $fillable = [
        'title',
        'body',
        'type',
        'is_active',
        'is_dismissible',
        'starts_at',
        'expires_at',
        'scope',
        'tiers',
    ];

    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'scope' => AnnouncementScope::class,
            'tiers' => 'array',
        ];
    }

    public function targetedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'announcement_targeted_users');
    }

    public function scopeVisibleToUser(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user) {
            $query->where('scope', AnnouncementScope::AllSubscribers)
                ->orWhere(fn (Builder $q) => $q->where('scope', AnnouncementScope::SpecificTiers)
                    ->whereJsonContains('tiers', $user->tier()->value))
                ->orWhere(fn (Builder $q) => $q->where('scope', AnnouncementScope::SpecificUsers)
                    ->whereHas('targetedUsers', fn (Builder $q) => $q->whereKey($user->id)));
        });
    }
}
