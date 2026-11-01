<?php

namespace App\Support\ProductStorage;

use App\Enums\StorageDimension;
use App\Models\ProductStorageScan;

/**
 * Shared data for the bucket doughnut charts: the latest complete scan's buckets, largest first, in GB.
 */
trait BucketChart
{
    abstract protected function dimension(): StorageDimension;

    /**
     * @return array{datasets: list<array{label: string, data: list<float>, backgroundColor: list<string>}>, labels: list<string>}
     */
    protected function getData(): array
    {
        $buckets = ProductStorageScan::latestComplete()?->bucketsFor($this->dimension()) ?? collect();
        $palette = ['#2563eb', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899', '#84cc16', '#f97316', '#64748b'];

        return [
            'datasets' => [[
                'label' => 'GB',
                'data' => $buckets->map(fn ($bucket): float => round($bucket->bytes / 1024 ** 3, 2))->values()->all(),
                'backgroundColor' => $buckets->keys()->map(fn (int $index): string => $palette[$index % count($palette)])->all(),
            ]],
            'labels' => $buckets->pluck('label')->values()->all(),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
