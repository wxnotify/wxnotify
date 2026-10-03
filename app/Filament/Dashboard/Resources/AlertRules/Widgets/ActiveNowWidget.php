<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Widgets;

use App\Actions\Alerts\GetActiveNowAtMyLocations;
use App\Filament\Dashboard\Resources\AlertRules\AlertRuleResource;
use App\Filament\Dashboard\Resources\AlertRules\Tables\AlertRulesTable;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;

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
     * Harmless re-render when the table changes an alert (the numbers here do
     * not depend on alerts; the action's 60 s cache still applies).
     */
    #[On(AlertRulesTable::CHANGED_EVENT)]
    public function refreshActiveNow(): void {}

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $result = app(GetActiveNowAtMyLocations::class)(auth()->user());

        $result['events'] = array_map(fn (array $event): array => [
            ...$event,
            'expires_label' => $event['expires'] ? self::timeLabel($event['expires']) : null,
            'alerted_at_label' => $event['alerted_at'] ? self::timeLabel($event['alerted_at']) : null,
        ], $result['events']);

        return $result;
    }

    /**
     * "4:15 PM" for a time today, "Sat 4:15 PM" otherwise -- judged in the
     * value's own offset (the location's timezone), not the server's.
     */
    private static function timeLabel(string $iso): string
    {
        $time = Carbon::parse($iso);

        return $time->format($time->isSameDay(Carbon::now($time->getTimezone())) ? 'g:i A' : 'D g:i A');
    }
}
