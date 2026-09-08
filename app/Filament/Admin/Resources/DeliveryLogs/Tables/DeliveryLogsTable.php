<?php

namespace App\Filament\Admin\Resources\DeliveryLogs\Tables;

use App\Models\DeliveryLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DeliveryLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('dispatched_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('user.email')
                    ->label('Subscriber'),
                TextColumn::make('channel_type')
                    ->badge(),
                TextColumn::make('alertRule.displayName')
                    ->label('Alert')
                    ->getStateUsing(fn (DeliveryLog $record): ?string => $record->alertRule?->displayName()),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'success' => 'success',
                        'failure' => 'danger',
                        'suppressed', 'skipped' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('error_detail')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('dispatched_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'success' => 'Success',
                        'failure' => 'Failure',
                        'suppressed' => 'Suppressed (quiet hours)',
                        'skipped' => 'Skipped',
                    ]),
                SelectFilter::make('channel_type')
                    ->options(fn (): array => DeliveryLog::query()->distinct()->pluck('channel_type', 'channel_type')->all()),
            ])
            ->emptyStateHeading('No delivery attempts yet')
            ->emptyStateDescription('Populates once the Alert Engine starts logging dispatch attempts.');
    }
}
