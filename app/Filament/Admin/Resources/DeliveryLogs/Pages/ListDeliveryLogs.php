<?php

namespace App\Filament\Admin\Resources\DeliveryLogs\Pages;

use App\Filament\Admin\Resources\DeliveryLogs\DeliveryLogResource;
use Filament\Resources\Pages\ListRecords;

class ListDeliveryLogs extends ListRecords
{
    protected static string $resource = DeliveryLogResource::class;
}
