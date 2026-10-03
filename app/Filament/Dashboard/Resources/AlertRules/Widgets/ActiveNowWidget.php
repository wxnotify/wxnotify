<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Widgets;

use App\Actions\Alerts\GetActiveNowAtMyLocations;
use App\Filament\Dashboard\Resources\AlertRules\AlertRuleResource;
use Filament\Widgets\Widget;

/**
 * "Active now at your locations" above the My Alerts table. Reads only our own
 * active_wwas and delivery_logs (no external call), scoped to auth()->user()
 * inside the action; the view polls every 60 s and the action caches for 60 s.
 */
class ActiveNowWidget extends Widget
{
    protected string $view = 'filament.dashboard.widgets.active-now';

    protected int|string|array $columnSpan = 1;

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return AlertRuleResource::canAccess();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return app(GetActiveNowAtMyLocations::class)(auth()->user());
    }
}
