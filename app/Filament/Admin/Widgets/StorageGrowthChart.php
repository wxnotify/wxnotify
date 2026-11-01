<?php

namespace App\Filament\Admin\Widgets;

use App\Models\ProductStorageScan;
use App\Support\Timezones;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class StorageGrowthChart extends ChartWidget
{
    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Growth, last 30 days';

    /**
     * @return array{datasets: list<array<string, mixed>>, labels: list<string>}
     */
    protected function getData(): array
    {
        $timezone = Timezones::forUserOrDefault(auth()->user());
        $gb = fn (mixed $bytes): float => round((int) $bytes / 1024 ** 3, 2);

        $scans = ProductStorageScan::query()
            ->complete()
            ->where('finished_at', '>=', now()->subDays(30))
            ->orderBy('finished_at')
            ->orderBy('id')
            ->get();

        return [
            'datasets' => [
                ['label' => 'NWWS (GB)', 'bytes' => $scans->map(fn ($scan): int => (int) $scan->nwws_bytes)->all(), 'data' => $scans->map(fn ($scan): float => $gb($scan->nwws_bytes))->all(), 'borderColor' => '#2563eb', 'yAxisID' => 'y'],
                ['label' => 'EMWIN Byteblaster (GB)', 'bytes' => $scans->map(fn ($scan): int => (int) $scan->emwin_byteblaster_bytes)->all(), 'data' => $scans->map(fn ($scan): float => $gb($scan->emwin_byteblaster_bytes))->all(), 'borderColor' => '#f59e0b', 'yAxisID' => 'y'],
                ['label' => 'EMWIN HTTP (GB)', 'bytes' => $scans->map(fn ($scan): int => (int) $scan->emwin_http_bytes)->all(), 'data' => $scans->map(fn ($scan): float => $gb($scan->emwin_http_bytes))->all(), 'borderColor' => '#10b981', 'yAxisID' => 'y'],
                ['label' => 'Disk used (%)', 'data' => $scans->map(fn ($scan): ?float => $this->diskPercent($scan))->all(), 'borderColor' => '#ef4444', 'borderDash' => [6, 4], 'yAxisID' => 'y1'],
            ],
            'labels' => $scans->map(fn ($scan): string => $scan->finished_at->copy()->setTimezone($timezone)->format('M j g:i A'))->all(),
        ];
    }

    private function diskPercent(ProductStorageScan $scan): ?float
    {
        $disk = $scan->disks[0] ?? null;
        $used = $disk['used_bytes'] ?? null;
        $total = $disk['total_bytes'] ?? null;

        if ($used === null || $total === null || (int) $total <= 0) {
            return null;
        }

        return round($used / $total * 100, 1);
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
        {
            scales: {
                y: { position: 'left', title: { display: true, text: 'GB' } },
                y1: { position: 'right', min: 0, max: 100, grid: { drawOnChartArea: false }, title: { display: true, text: '% used' } },
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: (context) => {
                            const bytes = context.dataset.bytes;

                            if (! bytes) {
                                return context.dataset.label + ': ' + context.parsed.y + '%';
                            }

                            const b = bytes[context.dataIndex];
                            if (b < 1024) { return context.dataset.label + ': ' + b + ' B'; }
                            const units = ['KB', 'MB', 'GB', 'TB', 'PB'];
                            let v = b / 1024, i = 0;
                            while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
                            return context.dataset.label + ': ' + v.toFixed(1) + ' ' + units[i];
                        },
                    },
                },
            },
        }
        JS);
    }

    protected function getType(): string
    {
        return 'line';
    }
}
