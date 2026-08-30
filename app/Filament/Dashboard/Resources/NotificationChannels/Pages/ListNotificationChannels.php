<?php

namespace App\Filament\Dashboard\Resources\NotificationChannels\Pages;

use App\Filament\Dashboard\Pages\ConnectChannel;
use App\Filament\Dashboard\Resources\NotificationChannels\NotificationChannelResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListNotificationChannels extends ListRecords
{
    protected static string $resource = NotificationChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connect')
                ->label('New channel')
                ->url(ConnectChannel::getUrl()),
        ];
    }
}
