<?php

namespace App\Filament\Admin\Resources\ActivityAlertRules\Pages;

use App\Filament\Admin\Resources\ActivityAlertRules\ActivityAlertRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListActivityAlertRules extends ListRecords
{
    protected static string $resource = ActivityAlertRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
