<?php

namespace App\Actions\Products;

use App\Actions\Pipeline\GenerateViewSlug;
use App\Models\IngestedProduct;
use Carbon\CarbonInterface;

/**
 * The labelled "Product details" lines of a product, shared by the public View Product page and the app's Product
 * screen. Lines with no value are left out; times are shown in UTC.
 */
class GetProductDetails
{
    public function __construct(private GenerateViewSlug $generateViewSlug) {}

    /**
     * @return array<string, string>
     */
    public function __invoke(IngestedProduct $product): array
    {
        $issuedAt = $this->generateViewSlug->issuedAt($product) ?? $product->received_at;

        $details = array_filter([
            'Office' => filled($product->cccc) ? strtoupper($product->cccc) : null,
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
