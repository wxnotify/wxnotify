<?php

namespace App\Filament\Admin\Resources\Announcements\Pages;

use App\Enums\AnnouncementScope;
use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;

class EditAnnouncement extends EditRecord
{
    protected static string $resource = AnnouncementResource::class;

    /**
     * A legacy specific-tiers announcement has no matching option without tiers, so the form
     * opens on All subscribers (which is who sees it anyway).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (! User::hasTiers() && ($data['scope'] ?? null) === AnnouncementScope::SpecificTiers->value) {
            $data['scope'] = AnnouncementScope::AllSubscribers->value;
        }

        return $data;
    }
}
