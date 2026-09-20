<?php

namespace App\Http\Controllers;

use App\Actions\Pipeline\GenerateViewSlug;
use App\Models\IngestedProduct;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Illuminate\Http\Response;

class ViewProductController extends Controller
{
    private const NOT_FOUND_CACHE = 'public, max-age=60';

    private const FOUND_CACHE = 'public, max-age=31536000, immutable';

    public function __invoke(string $slug, GenerateViewSlug $generateViewSlug): Response
    {
        // Registers the dashboard panel's colors, fonts and theme for the layout.
        Filament::setCurrentPanel('dashboard');
        Filament::bootCurrentPanel();

        $product = IngestedProduct::with('product')->where('view_slug', $slug)->first();

        if ($product === null || ($product->is_test && app()->isProduction())) {
            return response()->view('view-product.not-found', [], 404)
                ->header('Cache-Control', self::NOT_FOUND_CACHE);
        }

        $issuedAt = $generateViewSlug->issuedAt($product) ?? $product->received_at;
        $title = $this->title($product);
        $office = filled($product->cccc) ? strtoupper($product->cccc) : null;

        return response()->view('view-product.show', [
            'product' => $product,
            'title' => $title,
            'office' => $office,
            'description' => trim("{$title}".($office ? " issued by {$office}" : '').($issuedAt ? ' on '.$this->format($issuedAt) : '')).'.',
            'details' => $this->details($product, $office, $issuedAt),
            'loginUrl' => rtrim((string) config('app.url'), '/').route('filament.dashboard.auth.login', absolute: false),
        ])->header('Cache-Control', self::FOUND_CACHE);
    }

    private function title(IngestedProduct $product): string
    {
        return $product->product?->name
            ?? match (true) {
                filled($product->awipsid) => strtoupper($product->awipsid),
                filled($product->ttaaii) => strtoupper($product->ttaaii),
                default => 'Weather product',
            };
    }

    /**
     * @return array<string, string>
     */
    private function details(IngestedProduct $product, ?string $office, ?CarbonInterface $issuedAt): array
    {
        $details = array_filter([
            'Office' => $office,
            'Issued' => $issuedAt ? $this->format($issuedAt) : null,
            'WMO header' => filled($product->ttaaii) && filled($product->cccc) && filled($product->wmo_ddhhmm)
                ? strtoupper("{$product->ttaaii} {$product->cccc} {$product->wmo_ddhhmm}")
                : null,
            'AWIPS ID' => filled($product->awipsid) ? strtoupper($product->awipsid) : null,
            'Source' => match ($product->source) {
                'nwws' => 'NWWS',
                'emwin' => 'EMWIN',
                default => strtoupper((string) $product->source),
            },
        ]);

        if (filled($product->vtec_phenomenon)) {
            $details['VTEC'] = strtoupper(implode('.', array_filter([
                $product->vtec_action,
                $product->vtec_office,
                $product->vtec_phenomenon,
                $product->vtec_significance,
                $product->vtec_event_number,
            ]))).($product->vtec_year ? " ({$product->vtec_year})" : '');
        }

        if ($product->vtec_begin !== null) {
            $details['Valid from'] = $this->format($product->vtec_begin);
        }

        if ($product->vtec_end !== null) {
            $details['Valid until'] = $this->format($product->vtec_end);
        }

        return $details;
    }

    private function format(CarbonInterface $time): string
    {
        return $time->copy()->utc()->format('Y-m-d H:i').' UTC';
    }
}
