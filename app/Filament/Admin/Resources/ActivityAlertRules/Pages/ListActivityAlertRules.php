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
            Text::make('Rules are configured but not yet enforced — real-time dispatch requires a separate activity-observer engine, planned separately.')
                ->color('warning'),
            EmbeddedTable::make(),
        ]);
    }
}
