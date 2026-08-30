<?php

namespace App\Filament\Admin\Resources\SystemNotifications\Tables;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

class SystemNotificationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => class_basename($state)),
                TextColumn::make('notifiable')
                    ->getStateUsing(fn (DatabaseNotification $record): string => $record->notifiable?->email ?? $record->notifiable?->name ?? '—')
                    ->url(fn (DatabaseNotification $record): ?string => $record->notifiable instanceof User
                        ? UserResource::getUrl('edit', ['record' => $record->notifiable])
                        : null),
                TextColumn::make('data')
                    ->label('Message')
                    ->getStateUsing(fn (DatabaseNotification $record): string => Str::limit($record->data['message'] ?? '', 60)),
                IconColumn::make('read_at')
                    ->label('Read')
                    ->boolean()
                    ->getStateUsing(fn (DatabaseNotification $record): bool => $record->read()),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('toggleRead')
                    ->label(fn (DatabaseNotification $record): string => $record->read() ? 'Mark unread' : 'Mark read')
                    ->icon('heroicon-o-envelope')
                    ->action(fn (DatabaseNotification $record) => $record->read() ? $record->markAsUnread() : $record->markAsRead()),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
