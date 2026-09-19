<?php

namespace App\Actions\Testing;

use App\Actions\Pipeline\IngestProductText;
use App\Enums\ProductFamily;
use App\Models\ActiveWwa;
use App\Models\IngestedProduct;
use App\Models\Product;
use App\Models\TestProductScenario;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Orchestrates one "Generate" click: renders the scenario's stored input
 * into raw text via RenderTestProductText, then feeds it into the exact
 * same IngestProductText action a real product uses -- tagged is_test and
 * linked back to the scenario that produced it.
 */
class GenerateTestProduct
{
    /**
     * Bounds the reserved-event-number collision retry loop (see
     * nextReservedEventNumber()) so an exhausted pool fails fast with a
     * clear error instead of spinning forever.
     */
    private const MAX_EVENT_NUMBER_ATTEMPTS = 50;

    public function __construct(
        private readonly RenderTestProductText $renderTestProductText,
        private readonly IngestProductText $ingestProductText,
    ) {}

    public function __invoke(TestProductScenario $scenario): ?IngestedProduct
    {
        $product = $scenario->product;

        if ($product === null) {
            Log::warning('Test product generation skipped: the scenario\'s product no longer exists.', ['scenario_id' => $scenario->id]);

            return null;
        }

        if ($product->has_vtec && ! self::hasVtecPairCode($product)) {
            Log::warning('Test product generation skipped: a VTEC product needs a PP.S code to render a parseable VTEC line.', [
                'scenario_id' => $scenario->id,
                'product_code' => $product->code,
            ]);

            return null;
        }

        $input = $scenario->input;
        $wfo = $input['wfo'];

        if ($scenario->continues_scenario_id === null && $product->has_vtec && blank($input['vtec_event_number'] ?? null)) {
            $input['vtec_event_number'] = (string) $this->nextReservedEventNumber($product, $wfo);
            $input['vtec_action'] = 'NEW';
        }

        $text = ($this->renderTestProductText)($product, $input);

        $filePath = "test-generated/{$scenario->id}/".Str::uuid()->toString().'.txt';

        $identity = [
            'cccc' => 'k'.strtolower($wfo),
            'ttaaii' => match (true) {
                $product->family === ProductFamily::SpcWpc => 'acus11',
                $product->has_vtec => 'wuus53',
                default => 'fxus61',
            },
            'awipsid' => $this->synthesizeAwipsId($product, $wfo),
            'wmo_ddhhmm' => now()->format('dHi'),
            'nwws_id' => 'TEST-'.Str::random(8),
        ];

        return ($this->ingestProductText)($text, $filePath, $identity, false, [
            'is_test' => true,
            'test_product_scenario_id' => $scenario->id,
        ]);
    }

    /**
     * Draws a 4-digit VTEC event number from a reserved high range never
     * used by real NWS sequential numbering (see the design doc), so a
     * synthetic event's VTEC key can never collide with a real active one.
     *
     * Collisions are checked against the full VTEC composite key (office,
     * phenomenon, significance, event number, year -- see
     * ActiveWwa::vtecKeyFor() and its unique index), not the event number
     * alone, so unrelated offices/phenomena can freely reuse the same
     * reserved number instead of needlessly competing for the shared
     * 1000-number pool.
     *
     * The retry loop is bounded: once the pool for this specific VTEC key
     * is exhausted (nothing in this app ever expires an is_test
     * active_wwas row on a timer -- see the continue-existing-event flow,
     * a later task), looping forever would hang the request, so this fails
     * fast with a clear error instead.
     */
    private function nextReservedEventNumber(Product $product, string $wfo): int
    {
        $codeParts = explode('.', $product->code);
        $vtecKey = [
            'vtec_office' => 'K'.$wfo,
            'vtec_phenomenon' => $codeParts[0],
            'vtec_significance' => $codeParts[1] ?? 'W',
            'vtec_year' => (int) now()->year,
        ];

        for ($attempt = 0; $attempt < self::MAX_EVENT_NUMBER_ATTEMPTS; $attempt++) {
            $candidate = random_int(9000, 9999);

            if (! $this->reservedEventNumberIsTaken($vtecKey, $candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to find an unused reserved VTEC event number in the 9000-9999 range — too many open synthetic events. Close some out via the continue-existing-event flow.');
    }

    /**
     * @param  array{vtec_office: string, vtec_phenomenon: string, vtec_significance: string, vtec_year: int}  $vtecKey
     */
    protected function reservedEventNumberIsTaken(array $vtecKey, int $candidate): bool
    {
        return ActiveWwa::where([
            ...$vtecKey,
            'vtec_event_number' => (string) $candidate,
        ])->exists();
    }

    /**
     * True when the product code is a real VTEC phenomenon.significance
     * pair (e.g. "TO.W"); legacy dotless AWIPS abbreviations (FAW, SEW...)
     * can't produce a line ParseVtecString accepts.
     */
    public static function hasVtecPairCode(Product $product): bool
    {
        return preg_match('/^[A-Z]{2}\.[A-Z]$/', $product->code) === 1;
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
