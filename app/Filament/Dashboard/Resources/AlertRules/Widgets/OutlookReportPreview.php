<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Widgets;

use App\Actions\Weather\BuildOutlookReport;
use App\Actions\Weather\FetchSpcOutlooks;
use App\Actions\Weather\FindActivePwo;
use App\Actions\Weather\GetSpcOutlookGraphic;
use App\Models\IngestedProduct;
use App\Support\OutlookReportPresenter;
use App\Support\Timezones;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Today's Report" on the outlook report form: the Full report as the email would show it, built from
 * SPC's current outlooks for the locations ticked in the form, with SPC's own image URLs instead of
 * inline embeds. Embedded in the form's collapsed section through Filament's Livewire component; it asks
 * SPC nothing until the section is first opened (its view then calls load()), and its view reloads it
 * with the newly ticked locations while the section is open. FetchSpcOutlooks caches complete results
 * for 2 minutes. The ticked ids come from the browser, so only the signed-in subscriber's own locations
 * with a fix are used. A failure never throws: it shows a friendly message and logs the class only.
 */
class OutlookReportPreview extends Widget
{
    protected string $view = 'filament.dashboard.widgets.outlook-report-preview';

    protected static bool $isLazy = false;

    protected static bool $isDiscovered = false;

    /** Passed by Filament's Livewire schema component; the preview does not use it. */
    public ?Model $record = null;

    public bool $isLoaded = false;

    /** @var list<int> */
    public array $locationIds = [];

    /**
     * @param  array<int, int|string>  $locationIds
     */
    public function load(array $locationIds = []): void
    {
        $this->locationIds = array_values(array_unique(array_map(intval(...), array_filter($locationIds, is_numeric(...)))));
        $this->isLoaded = true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        if (! $this->isLoaded) {
            return ['status' => 'idle'];
        }

        try {
            return $this->report();
        } catch (Throwable $e) {
            Log::warning('Outlook report preview failed', ['exception' => $e::class]);

            return ['status' => 'unavailable'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function report(): array
    {
        $outlooks = app(FetchSpcOutlooks::class)();

        if (array_filter($outlooks) === []) {
            return ['status' => 'unavailable'];
        }

        $user = auth()->user();
        $locations = $user->locations()
            ->whereKey($this->locationIds)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderBy('label')
            ->get();

        $payload = app(BuildOutlookReport::class)(
            $outlooks,
            $locations,
            $this->activePwo($outlooks['day_1']['issued_at'] ?? null),
            Carbon::now(Timezones::forUserOrDefault($user))->toDateString(),
        );

        return [
            'status' => 'loaded',
            'r' => OutlookReportPresenter::present($payload),
            'imageSrc' => GetSpcOutlookGraphic::urls(),
        ];
    }

    private function activePwo(?string $day1IssuedAt): ?IngestedProduct
    {
        try {
            return app(FindActivePwo::class)($day1IssuedAt);
        } catch (Throwable $e) {
            Log::warning('Active PWO lookup failed', ['exception' => $e::class]);

            return null;
        }
    }
}
