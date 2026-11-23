<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Pages;

use App\Exceptions\AlertRuleLimitReached;
use App\Filament\Dashboard\Resources\AlertRules\AlertRuleResource;
use App\Filament\Dashboard\Resources\AlertRules\Widgets\ActiveNowWidget;
use App\Filament\Dashboard\Resources\AlertRules\Widgets\AlertStatsWidget;
use App\Filament\Dashboard\Resources\AlertRules\Widgets\NeedsAttentionWidget;
use App\Filament\Dashboard\Resources\Concerns\DescribesListPage;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;

class ListAlertRules extends ListRecords
{
    use DescribesListPage;

    protected static string $resource = AlertRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New Alert')
                ->disabled(fn (): bool => ! auth()->user()->canCreateAlertRule())
                ->tooltip(function (): ?string {
                    $user = auth()->user();

                    if ($user->canCreateAlertRule()) {
                        return null;
                    }

                    return AlertRuleLimitReached::limit($user->alertRuleLimit())->getMessage();
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            AlertStatsWidget::class,
            NeedsAttentionWidget::class,
            ActiveNowWidget::class,
        ];
    }

    protected function pageDescription(): string
    {
        $user = auth()->user();
        $count = $user->alertRules()->count();
        $limit = $user->alertRuleLimit();

        return "You're using {$count} of {$limit} ".Str::plural('alert', $limit).". Alerts you've set up to be notified about — severe weather warnings, forecast discussions, lightning strikes, and more. Create a new alert to start watching for a specific product, risk level, or threshold.";
    }
}
