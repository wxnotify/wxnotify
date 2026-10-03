<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Widgets;

use App\Actions\Alerts\GetAlertsNeedingAttention;
use App\Filament\Dashboard\Resources\AlertRules\AlertRuleResource;
use App\Filament\Dashboard\Resources\Locations\LocationResource;
use App\Filament\Dashboard\Resources\NotificationChannels\NotificationChannelResource;
use Filament\Widgets\Widget;

/**
 * "Needs attention" above the My Alerts table: alerts and channels that can't
 * currently do their job, each with a link to the fix. Database only; scoped to
 * auth()->user() inside GetAlertsNeedingAttention (canView() mirrors the
 * resource gate, which allows everyone, so that scoping is the protection).
 */
class NeedsAttentionWidget extends Widget
{
    protected string $view = 'filament.dashboard.widgets.needs-attention';

    protected int|string|array $columnSpan = 1;

    protected static bool $isLazy = false;

    private const MAX_ROWS = 8;

    public static function canView(): bool
    {
        return AlertRuleResource::canAccess();
    }

    /**
     * @return array{rows: list<array<string, mixed>>, more: int, count: int}
     */
    protected function getViewData(): array
    {
        $all = app(GetAlertsNeedingAttention::class)(auth()->user());

        return [
            'rows' => array_map(fn (array $row): array => [...$row, 'url' => $this->urlFor($row['target']), ...$this->iconFor($row['severity'])], array_slice($all, 0, self::MAX_ROWS)),
            'more' => max(0, count($all) - self::MAX_ROWS),
            'count' => count($all),
        ];
    }

    /**
     * @param  array{type: string, id: ?int}  $target
     */
    private function urlFor(array $target): string
    {
        return match ($target['type']) {
            'channels' => NotificationChannelResource::getUrl('index'),
            'location' => LocationResource::getUrl('edit', ['record' => $target['id']]),
            default => AlertRuleResource::getUrl('edit', ['record' => $target['id']]),
        };
    }

    /**
     * @return array{icon: string, icon_class: string}
     */
    private function iconFor(string $severity): array
    {
        return match ($severity) {
            'danger' => ['icon' => 'heroicon-o-x-circle', 'icon_class' => 'text-danger-600 dark:text-danger-400'],
            'warning' => ['icon' => 'heroicon-o-exclamation-triangle', 'icon_class' => 'text-warning-600 dark:text-warning-400'],
            default => ['icon' => 'heroicon-o-pause-circle', 'icon_class' => 'text-gray-500 dark:text-gray-400'],
        };
    }
}
