<?php

namespace App\Filament\Admin\Resources\Announcements\Tables;

use App\Enums\AnnouncementScope;
use App\Models\Announcement;
use App\Models\User;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Marcelodelgado\Announcements\Enums\AnnouncementType;

class AnnouncementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (AnnouncementType $state): string => $state->label())
                    ->color(fn (AnnouncementType $state): string => match ($state) {
                        AnnouncementType::Danger => 'danger',
                        AnnouncementType::Warning => 'warning',
                        AnnouncementType::Info => 'info',
                        AnnouncementType::Success => 'success',
                    }),
                TextColumn::make('scope')
                    ->label('Audience')
                    ->badge()
                    ->formatStateUsing(fn (AnnouncementScope $state, Announcement $record): string => match ($state) {
                        AnnouncementScope::AllSubscribers => $state->label(),
                        AnnouncementScope::SpecificTiers => ! User::hasTiers()
                            ? 'Specific tiers (shown to everyone when self-hosted)'
                            : collect($record->tiers)->map(fn (string $tier) => ucfirst($tier))->implode(', '),
                        AnnouncementScope::SpecificUsers => $record->targetedUsers()->count().' subscriber(s)',
                    }),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('starts_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('expires_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('activate')
                        ->label('Activate')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => true]))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deactivate')
                        ->label('Deactivate')
                        ->icon(Heroicon::OutlinedXCircle)
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => false]))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
