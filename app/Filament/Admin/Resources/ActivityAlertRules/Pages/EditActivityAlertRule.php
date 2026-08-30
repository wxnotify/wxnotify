<?php

namespace App\Filament\Admin\Resources\ActivityAlertRules\Pages;

use App\Filament\Admin\Resources\ActivityAlertRules\ActivityAlertRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditActivityAlertRule extends EditRecord
{
    protected static string $resource = ActivityAlertRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
