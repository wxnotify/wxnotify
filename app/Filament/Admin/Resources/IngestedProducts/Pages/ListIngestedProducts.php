<?php

namespace App\Filament\Admin\Resources\IngestedProducts\Pages;

use App\Filament\Admin\Resources\IngestedProducts\IngestedProductResource;
use Filament\Resources\Pages\ListRecords;

class ListIngestedProducts extends ListRecords
{
    protected static string $resource = IngestedProductResource::class;
}
