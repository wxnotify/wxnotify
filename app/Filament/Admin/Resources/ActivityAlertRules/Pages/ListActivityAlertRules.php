<?php

namespace App\Filament\Admin\Resources\ActivityAlertRules\Pages;

use App\Filament\Admin\Resources\ActivityAlertRules\ActivityAlertRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

class ListActivityAlertRules extends ListRecords
{
    protected static string $resource = ActivityAlertRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('Real-time dispatch is live for most activity types below. Schedule changes, product list updates, settings changes, and Daily Forecast enablement are not yet enforced — those need features that don\'t exist in the app yet.')
                ->color('warning'),
            EmbeddedTable::make(),
        ]);
    }
}
