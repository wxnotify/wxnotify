<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\ProductStorageStats;
use App\Filament\Admin\Widgets\StorageByCatalogGroupChart;
use App\Filament\Admin\Widgets\StorageByFileKindChart;
use App\Filament\Admin\Widgets\StorageBySourceChart;
use App\Filament\Admin\Widgets\StorageGrowthChart;
use App\Jobs\ScanProductStorageJob;
use App\Models\ProductStorageScan;
use App\Support\ProductStorage\Bytes;
use App\Support\ProductStorage\DiskGauge;
use App\Support\ProductStorage\DiskReading;
use App\Support\Timezones;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

class ProductStorage extends Page
{
    /**
     * A scan row left 'running' longer than this is treated as interrupted (the job's own timeout is 15 minutes).
     */
    private const RUNNING_MINUTES = 20;

    private const STALE_HOURS = 3;

    protected string $view = 'filament.admin.pages.product-storage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Product Storage';

    protected static ?string $title = 'Product Storage';

    public static function canAccess(): bool
    {
        return auth()->user()?->is_staff === true;
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [ProductStorageStats::class];
    }

    /**
     * @return array<class-string>
     */
    protected function getFooterWidgets(): array
    {
        return [StorageBySourceChart::class, StorageByFileKindChart::class, StorageByCatalogGroupChart::class, StorageGrowthChart::class];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 2;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('scanNow')
                ->label('Scan now')
                ->icon(Heroicon::OutlinedArrowPath)
                ->disabled(fn (): bool => $this->scanIsRunning())
                ->tooltip(fn (): ?string => $this->scanIsRunning() ? 'A scan is already running' : null)
                ->action(function (): void {
                    if ($this->scanIsRunning()) {
                        Notification::make()->title('A scan is already running')->warning()->send();

                        return;
                    }

                    ScanProductStorageJob::dispatch();

                    Notification::make()->title('Scan queued')->success()->send();
                }),
        ];
    }

    public function scanIsRunning(): bool
    {
        $latest = ProductStorageScan::latest();

        return $latest !== null
            && $latest->status === ProductStorageScan::STATUS_RUNNING
            && $latest->started_at->gt(now()->subMinutes(self::RUNNING_MINUTES));
    }

    /**
     * @return array{text: string, tone: 'normal'|'warning'|'critical'}
     */
    public function statusLine(): array
    {
        $latest = ProductStorageScan::latest();
        $complete = ProductStorageScan::latestComplete();

        if ($latest === null) {
            return ['text' => 'No scan has run yet. Use Scan now.', 'tone' => 'warning'];
        }

        $lastGood = $complete !== null ? ' Showing the last successful scan from '.$this->time($complete->finished_at).'.' : '';

        if ($latest->status === ProductStorageScan::STATUS_FAILED) {
            return ['text' => 'Last scan failed: '.($latest->error ?: 'unknown error').'.'.$lastGood, 'tone' => 'critical'];
        }

        if ($latest->status === ProductStorageScan::STATUS_RUNNING) {
            if ($this->scanIsRunning()) {
                return ['text' => 'Scan in progress.'.$lastGood, 'tone' => 'normal'];
            }

            return ['text' => 'Last scan did not finish.'.$lastGood, 'tone' => 'warning'];
        }

        if ($latest->finished_at->lt(now()->subHours(self::STALE_HOURS))) {
            return ['text' => 'Snapshot is stale: last scan '.$latest->finished_at->diffForHumans().'.', 'tone' => 'warning'];
        }

        return ['text' => 'As of '.$this->time($latest->finished_at).'.', 'tone' => 'normal'];
    }

    /**
     * One entry per disk reading, ready for the view.
     *
     * @return list<array{label: string, path: string, available: bool, percent: ?float, band: string, usage: ?string, split: ?string}>
     */
    public function gauges(): array
    {
        $scan = ProductStorageScan::latestComplete();
        $scanDisks = collect($scan?->disks ?? [])->keyBy('label');

        return collect(app(DiskGauge::class)->readings())
            ->map(function (DiskReading $reading) use ($scan, $scanDisks): array {
                $available = $reading->isAvailable();
                $split = null;

                if ($available && $scan !== null && $scanDisks->has($reading->label)) {
                    $product = (int) $scanDisks[$reading->label]['product_bytes'];
                    $split = 'Product files: '.Bytes::format($product)
                        .' · Everything else: '.Bytes::format(max(0, $reading->usedBytes - $product));
                }

                return [
                    'label' => $reading->label,
                    'path' => $reading->path,
                    'available' => $available,
                    'percent' => $reading->percent(),
                    'band' => $reading->band(),
                    'usage' => $available
                        ? Bytes::format($reading->usedBytes).' used of '.Bytes::format($reading->totalBytes)
                            .' ('.rtrim(rtrim(number_format($reading->percent(), 1), '0'), '.').'% full)'
                        : null,
                    'split' => $split,
                ];
            })
            ->values()
            ->all();
    }

    private function time(Carbon $time): string
    {
        return $time->copy()->setTimezone(Timezones::forUserOrDefault(auth()->user()))->format('M j, Y g:i A T');
    }
}
