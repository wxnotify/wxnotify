<?php

namespace App\Filament\Admin\Resources\DidYouKnows\Pages;

use App\Filament\Admin\Resources\DidYouKnows\DidYouKnowResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDidYouKnows extends ListRecords
{
    protected static string $resource = DidYouKnowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
