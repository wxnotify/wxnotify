<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * User deliberately excludes is_staff/tier_override/tier_override_reason
     * from its #[Fillable] list — a subscriber must never be able to grant
     * themselves a tier via mass assignment. This admin form is the one
     * legitimate, staff-only path for setting them, so it bypasses that
     * guard with forceFill() instead of the default mass-assigned update().
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $record->forceFill($data)->save();

        return $record;
    }
}
