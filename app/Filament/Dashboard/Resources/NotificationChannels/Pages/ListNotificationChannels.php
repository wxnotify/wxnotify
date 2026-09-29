<?php

namespace App\Filament\Dashboard\Resources\NotificationChannels\Pages;

use App\Filament\Dashboard\Pages\ConnectChannel;
use App\Filament\Dashboard\Resources\Concerns\DescribesListPage;
use App\Filament\Dashboard\Resources\NotificationChannels\NotificationChannelResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListNotificationChannels extends ListRecords
{
    use DescribesListPage;

    protected static string $resource = NotificationChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connect')
                ->label('New channel')
                ->url(ConnectChannel::getUrl()),
        ];
    }

    protected function pageDescription(): string
    {
        return 'Where your alerts actually get delivered — email, Discord, SMS, and more. Add and verify a channel here, then choose it when creating an alert.';
    }
}
