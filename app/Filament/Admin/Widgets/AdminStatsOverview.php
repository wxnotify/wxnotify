<?php

namespace App\Filament\Admin\Widgets;

use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AdminStatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Active Alert Rules', AlertRule::where('is_active', true)->count()),
            Stat::make('Subscribers', User::count()),
            Stat::make('Locations Watched', Location::count()),
            Stat::make('Verified Channels', NotificationChannel::whereNotNull('verified_at')->count()),
        ];
    }
}
