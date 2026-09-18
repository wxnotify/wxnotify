<?php

namespace App\Actions\Testing;

use App\Actions\Pipeline\IngestProductText;
use App\Models\ActiveWwa;
use App\Models\IngestedProduct;
use App\Models\Product;
use App\Models\TestProductScenario;
use Illuminate\Support\Str;

/**
 * Orchestrates one "Generate" click: renders the scenario's stored input
 * into raw text via RenderTestProductText, then feeds it into the exact
 * same IngestProductText action a real product uses -- tagged is_test and
 * linked back to the scenario that produced it.
 */
class GenerateTestProduct
{
    public function __construct(
        private readonly RenderTestProductText $renderTestProductText,
        private readonly IngestProductText $ingestProductText,
    ) {}

    public function __invoke(TestProductScenario $scenario): ?IngestedProduct
    {
        $product = $scenario->product;
        $input = $scenario->input;

        if (($input['mode'] ?? 'new') === 'new' && $product->has_vtec && blank($input['vtec_event_number'] ?? null)) {
            $input['vtec_event_number'] = (string) $this->nextReservedEventNumber();
            $input['vtec_action'] = 'NEW';
        }

        $text = ($this->renderTestProductText)($product, $input);

        $wfo = $input['wfo'];
        $isCap = false;
        $filePath = "test-generated/{$scenario->id}/".Str::uuid()->toString().'.txt';

        $identity = [
            'cccc' => 'k'.strtolower($wfo),
            'ttaaii' => match (true) {
                $product->family->value === 'spc_wpc' => 'acus11',
                $product->has_vtec => 'wuus53',
                default => 'fxus61',
            },
            'awipsid' => $this->synthesizeAwipsId($product, $wfo),
            'wmo_ddhhmm' => now()->format('dHi'),
            'nwws_id' => 'TEST-'.Str::random(8),
        ];

        return ($this->ingestProductText)($text, $filePath, $identity, $isCap, [
            'is_test' => true,
            'test_product_scenario_id' => $scenario->id,
        ]);
    }

    /**
     * Draws a 4-digit VTEC event number from a reserved high range never
     * used by real NWS sequential numbering (see the design doc), so a
     * synthetic event's VTEC key can never collide with a real active one.
     */
    private function nextReservedEventNumber(): int
    {
        do {
            $candidate = random_int(9000, 9999);
        } while (ActiveWwa::where('vtec_event_number', (string) $candidate)->where('status', 'Active')->exists());

        return $candidate;
    }

    /**
     * Matches ExtractProductMetadata's real AWIPS crosswalk exactly for the
     * non-VTEC products that depend on it to resolve product_id (SPC MD,
     * WPC MPD, AFD) -- for a has_vtec product, the value here is never
     * actually consulted by the parser (VTEC-line resolution short-circuits
     * first), so it only needs to look plausible.
     */
    private function synthesizeAwipsId(Product $product, string $wfo): string
    {
        return match ($product->code) {
            'SPC.MD' => 'swomcd',
            'WPC.MPD' => 'ffgmpd',
            'AFD' => 'afd'.strtolower($wfo),
            default => strtolower(str_replace('.', '', $product->code)).'test',
        };
    }
}
