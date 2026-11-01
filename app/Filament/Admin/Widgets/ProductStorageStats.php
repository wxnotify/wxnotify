<?php

namespace App\Filament\Admin\Widgets;

use App\Models\ProductStorageScan;
use App\Support\ProductStorage\Bytes;
use App\Support\Timezones;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProductStorageStats extends BaseWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $scan = ProductStorageScan::latestComplete();

        if ($scan === null) {
            return [
                Stat::make('Product files', '—'),
                Stat::make('Files', '—'),
                Stat::make('Oldest file', '—'),
                Stat::make('Last scan', '—'),
            ];
        }

        $timezone = Timezones::forUserOrDefault(auth()->user());

        return [
            Stat::make('Product files', Bytes::format($scan->totalBytes())),
            Stat::make('Files', number_format((int) $scan->file_count)),
            Stat::make('Oldest file', $scan->oldest_file_at?->setTimezone($timezone)->diffForHumans(syntax: true) ?? '—'),
            Stat::make('Last scan', $scan->finished_at?->setTimezone($timezone)->diffForHumans() ?? '—'),
        ];
    }
}
