<?php

namespace App\Actions\Pipeline;

use App\Models\IngestedProduct;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Parses a product's raw text and writes the resulting IngestedProduct row
 * -- the single shared code path between the real NWWS intake controller
 * (IngestNwwsProductController) and the Test Product Generator, so a
 * synthetic product is parsed by the exact same code a real one is, not
 * merely similarly-shaped code.
 */
class IngestProductText
{
    public function __construct(
        private readonly ExtractProductMetadata $extractProductMetadata,
        private readonly ExtractCapMetadata $extractCapMetadata,
    ) {}

    /**
     * @param  array{cccc: ?string, ttaaii: ?string, awipsid: ?string, wmo_ddhhmm: ?string, nwws_id: ?string}  $identity
     * @param  array<string, mixed>  $extra  Additional columns to merge into the row (e.g. is_test, test_product_scenario_id).
     */
    public function __invoke(string $text, string $filePath, array $identity, bool $isCap = false, array $extra = []): ?IngestedProduct
    {
        if ($isCap) {
            try {
                $metadata = ($this->extractCapMetadata)($text);
            } catch (Throwable $e) {
                Log::warning('Failed to parse a CAP product; skipping ingestion', [
                    'path' => $filePath,
                    'exception' => $e->getMessage(),
                ]);

                return null;
            }
        } else {
            $metadata = ($this->extractProductMetadata)($text, $identity['awipsid']);
        }

        return IngestedProduct::updateOrCreate(
            ['file_path' => $filePath],
            [...$identity, ...$metadata, ...$extra, 'text' => $text, 'received_at' => now()],
        );
    }
}
