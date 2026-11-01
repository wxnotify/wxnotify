<?php

namespace App\Support\ProductStorage;

use App\Enums\StorageDimension;
use App\Models\ProductStorageScan;
use Filament\Support\RawJs;

/**
 * Shared data for the bucket doughnut charts: the latest complete scan's buckets, largest first, in GB.
 */
trait BucketChart
{
    abstract protected function dimension(): StorageDimension;

    /**
     * @return array{datasets: list<array{label: string, bytes: list<int>, data: list<float>, backgroundColor: list<string>}>, labels: list<string>}
     */
    protected function getData(): array
    {
        $buckets = ProductStorageScan::latestComplete()?->bucketsFor($this->dimension()) ?? collect();
        $palette = ['#2563eb', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16', '#f97316', '#64748b'];

        return [
            'datasets' => [[
                'label' => 'GB',
                'bytes' => $buckets->map(fn ($bucket): int => (int) $bucket->bytes)->values()->all(),
                'data' => $buckets->map(fn ($bucket): float => round($bucket->bytes / 1024 ** 3, 2))->values()->all(),
                'backgroundColor' => $buckets->keys()->map(fn (int $index): string => $palette[$index % count($palette)])->all(),
            ]],
            'labels' => $buckets->pluck('label')->values()->all(),
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
        {
            plugins: {
                tooltip: {
                    callbacks: {
                        label: (context) => context.label + ': ' + (function (b) {
                            if (b < 1024) { return b + ' B'; }
                            const units = ['KB', 'MB', 'GB', 'TB', 'PB'];
                            let v = b / 1024, i = 0;
                            while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
                            return v.toFixed(1) + ' ' + units[i];
                        })(context.dataset.bytes[context.dataIndex]),
                    },
                },
            },
        }
        JS);
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
