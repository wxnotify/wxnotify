<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Pages;

use App\Filament\Dashboard\Resources\AlertRules\AlertRuleResource;
use App\Filament\Dashboard\Resources\AlertRules\Widgets\AlertStatsWidget;
use App\Filament\Dashboard\Resources\AlertRules\Widgets\NeedsAttentionWidget;
use App\Filament\Dashboard\Resources\Concerns\DescribesListPage;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAlertRules extends ListRecords
{
    use DescribesListPage;

    protected static string $resource = AlertRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New Alert'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            AlertStatsWidget::class,
            NeedsAttentionWidget::class,
        ];
    }

    protected function pageDescription(): string
    {
        return "Alerts you've set up to be notified about — severe weather warnings, forecast discussions, lightning strikes, and more. Create a new alert to start watching for a specific product, risk level, or threshold.";
    }
}
