<?php

namespace App\Filament\Admin\Resources\ActivityLogs\Tables;

use App\Filament\Admin\Resources\Products\ProductResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

class ActivityLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('log_name')
                    ->badge(),
                TextColumn::make('event')
                    ->badge(),
                TextColumn::make('description')
                    ->wrap(),
                TextColumn::make('causer')
                    ->getStateUsing(fn (Activity $record): string => $record->causer?->name ?? 'System')
                    ->url(fn (Activity $record): ?string => $record->causer instanceof User
                        ? UserResource::getUrl('edit', ['record' => $record->causer])
                        : null),
                TextColumn::make('subject')
                    ->getStateUsing(fn (Activity $record): string => $record->subject_type
                        ? Str::afterLast($record->subject_type, '\\').' #'.$record->subject_id
                        : '—')
                    ->url(fn (Activity $record): ?string => $record->subject_type === Product::class
                        ? ProductResource::getUrl('edit', ['record' => $record->subject_id])
                        : null),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('log_name')
                    ->options(fn (): array => Activity::query()->distinct()->pluck('log_name', 'log_name')->filter()->all()),
                SelectFilter::make('event')
                    ->options(fn (): array => Activity::query()->distinct()->pluck('event', 'event')->filter()->all()),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
