<?php

namespace App\Filament\Admin\Resources\Users\Tables;

use App\Enums\Tier;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use STS\FilamentImpersonate\Actions\Impersonate;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('inviteCode'))
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('tier')
                    ->badge()
                    ->getStateUsing(fn (User $record): string => ucfirst($record->tier()->value)),
                IconColumn::make('is_staff')
                    ->boolean(),
                IconColumn::make('is_owner')
                    ->label('Owner')
                    ->boolean(),
                IconColumn::make('is_test_account')
                    ->label('Fixture')
                    ->boolean(),
                TextColumn::make('locations_count')
                    ->label('Locations')
                    ->counts('locations'),
                TextColumn::make('alert_rules_count')
                    ->label('Alerts')
                    ->counts('alertRules'),
                TextColumn::make('notification_channels_count')
                    ->label('Channels')
                    ->counts('notificationChannels'),
                TextColumn::make('joined_with')
                    ->label('Joined with')
                    ->state(fn (User $record): ?string => $record->inviteCode?->displayCode())
                    ->fontFamily('mono')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('tier_override')
                    ->label('Tier override')
                    ->options(collect(Tier::cases())->mapWithKeys(fn (Tier $tier) => [$tier->value => ucfirst($tier->value)])),
                TernaryFilter::make('is_staff'),
            ])
            ->recordActions([
                EditAction::make(),
                Impersonate::make()->redirectTo('/dashboard'),
                // A staff member can't delete their own account — self-delete
                // would leave them locked out of /admin (canAccessPanel gates
                // on is_staff) with no in-app path back in.
                DeleteAction::make()
                    ->visible(fn (User $record): bool => $record->isNot(auth()->user())),
            ]);
    }
}
