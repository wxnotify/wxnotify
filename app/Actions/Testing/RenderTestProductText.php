<?php

namespace App\Actions\Testing;

use App\Enums\ProductFamily;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Renders one of three Blade templates -- warning, discussion, plain-text --
 * into realistic raw NWS product text from a scenario's stored wizard input
 * plus its Product. Shape is derived from the product's own has_vtec/family
 * flags (see Product Catalog's generator metadata columns), not chosen
 * directly by the admin.
 *
 * @param  array<string, mixed>  $input
 */
class RenderTestProductText
{
    public function __invoke(Product $product, array $input): string
    {
        // Honors an explicit input.issued_at when the caller set one (e.g. a
        // hand-built test fixture); otherwise defaults to now -- matches the
        // wizard's own DateTimePicker default(now()) on Create, and
        // EditTestProductScenario::mutateFormDataBeforeFill() (a later task)
        // resets this field to now every time an existing scenario is
        // reopened, so replaying/editing a scenario always looks
        // freshly issued rather than replaying a stale original timestamp.
        $issuedAt = isset($input['issued_at']) ? Carbon::parse($input['issued_at']) : Carbon::now();

        return match (true) {
            $product->family === ProductFamily::SpcWpc => $this->renderDiscussion($product, $input, $issuedAt),
            $product->has_vtec => $this->renderWarning($product, $input, $issuedAt),
            default => $this->renderPlainText($product, $input, $issuedAt),
        };
    }

    private function renderWarning(Product $product, array $input, Carbon $issuedAt): string
    {
        $vtecBegin = $issuedAt->copy();
        $vtecEnd = $issuedAt->copy()->addMinutes((int) ($input['expires_minutes'] ?? 60));

        $polygonPoints = $this->resolvePolygon($input);
        $ugcCodes = $input['ugc_codes'] ?? [];
        $codeParts = explode('.', $product->code);

        return $this->clean(view('filament.admin.test-products.warning', [
            'wfo' => $input['wfo'],
            'ttaaii' => 'wuus53',
            'productAwipsAbbr' => strtolower(str_replace('.', '', $product->code)),
            'issuedAt' => $issuedAt,
            'vtecAction' => $input['vtec_action'] ?? 'NEW',
            'vtecOffice' => 'K'.$input['wfo'],
            'vtecPhenomenon' => $codeParts[0],
            'vtecSignificance' => $codeParts[1] ?? 'W',
            'vtecEventNumber' => $input['vtec_event_number'] ?? '9000',
            'vtecBegin' => $vtecBegin,
            'vtecEnd' => $vtecEnd,
            'productName' => $product->name,
            'officeCity' => $this->officeCityFor($input['wfo']),
            'ugcLine' => $this->buildUgcLine($ugcCodes, $vtecEnd),
            'hazard' => $input['hazard'] ?? '',
            'hazardSource' => $input['hazard_source'] ?? '',
            'impact' => $input['impact'] ?? '',
            'locationsImpacted' => $input['locations_impacted'] ?? null,
            'callToAction' => $input['call_to_action'] ?? '',
            'polygonLine' => $this->buildPolygonLine($polygonPoints),
            'motionLine' => $this->buildMotionLine($input, $issuedAt, $polygonPoints),
            'damageTagLines' => $input['damage_tags'] ?? [],
        ])->render());
    }

    private function renderDiscussion(Product $product, array $input, Carbon $issuedAt): string
    {
        $polygonPoints = $this->resolvePolygon($input);

        return $this->clean(view('filament.admin.test-products.discussion', [
            'ttaaii' => 'acus11',
            'wfo' => $input['wfo'],
            'issuedAt' => $issuedAt,
            'discussionNumber' => $input['discussion_number'] ?? '9001',
            'productLabel' => $product->code === 'WPC.MPD' ? 'MESOSCALE PRECIPITATION DISCUSSION' : 'MESOSCALE DISCUSSION',
            'officeName' => $product->code === 'WPC.MPD' ? 'NWS WEATHER PREDICTION CENTER COLLEGE PARK MD' : 'NWS STORM PREDICTION CENTER NORMAN OK',
            'areasAffected' => $input['areas_affected'] ?? '',
            'ugcLine' => $this->buildUgcLine($input['ugc_codes'] ?? [], $issuedAt->copy()->addHours(2)),
            'discussionText' => $input['discussion_text'] ?? '',
            'concerning' => $input['concerning'] ?? '',
            'polygonLine' => $this->buildCombinedPolygonLine($polygonPoints),
            'attnWfos' => $input['attn_wfos'] ?? [$input['wfo']],
        ])->render());
    }

    private function renderPlainText(Product $product, array $input, Carbon $issuedAt): string
    {
        $ugcCodes = $input['ugc_codes'] ?? [];

        return $this->clean(view('filament.admin.test-products.plain-text', [
            'ttaaii' => 'fxus61',
            'wfo' => $input['wfo'],
            'issuedAt' => $issuedAt,
            'productName' => $product->name,
            'officeCity' => $this->officeCityFor($input['wfo']),
            'ugcLine' => $ugcCodes !== [] ? $this->buildUgcLine($ugcCodes, $issuedAt->copy()->addHours(2)) : null,
            'bodyText' => $input['body_text'] ?? '',
        ])->render());
    }

    /**
     * @return ?array<int, array{lat: float, lon: float}>
     */
    private function resolvePolygon(array $input): ?array
    {
        // The wizard's Geography step (a later task) writes a boolean
        // input.polygon_advanced (the "Advanced: paste raw coordinates"
        // toggle); input.polygon_mode === 'raw' is also accepted so a
        // caller building $input by hand (as this file's own tests, and
        // any orchestrator/end-to-end test, do) can use whichever is more
        // convenient -- both must keep working, not just one.
        $usesRawCoordinates = ($input['polygon_advanced'] ?? false) || (($input['polygon_mode'] ?? null) === 'raw');

        if ($usesRawCoordinates) {
            return app(GenerateTestProductPolygon::class)->fromRawText($input['polygon_raw'] ?? '');
        }

        if (! isset($input['polygon_center_lat'], $input['polygon_center_lon'], $input['polygon_radius_miles'])) {
            return null;
        }

        return app(GenerateTestProductPolygon::class)->fromPointAndRadius(
            (float) $input['polygon_center_lat'],
            (float) $input['polygon_center_lon'],
            (float) $input['polygon_radius_miles'],
        );
    }

    private function buildUgcLine(array $codes, Carbon $purgeTime): string
    {
        if ($codes === []) {
            return '';
        }

        return implode('-', $codes).'-'.$purgeTime->format('dHi').'-';
    }

    /**
     * Warning-family format: separate space-delimited 4-5 digit tokens per
     * value (see ExtractProductMetadata::parsePolygon()).
     */
    private function buildPolygonLine(?array $points): string
    {
        if ($points === null || $points === []) {
            return '';
        }

        $tokens = collect($points)->flatMap(fn (array $p): array => [
            $this->encodeCoordinatePart(abs($p['lat'])),
            $this->encodeCoordinatePart(abs($p['lon'])),
        ]);

        return 'LAT...LON '.$tokens->implode(' ');
    }

    /**
     * SPC MD/WPC MPD format: one concatenated 8-9 digit token per pair (see
     * ExtractProductMetadata::decodeCombinedToken()) -- longitudes here are
     * always >= 100 degrees (SPC/WPC's CONUS coverage), so the leading "1"
     * is always dropped to match that method's decode convention.
     */
    private function buildCombinedPolygonLine(?array $points): string
    {
        if ($points === null || $points === []) {
            return '';
        }

        $tokens = collect($points)->map(function (array $p): string {
            $latToken = $this->encodeCoordinatePart(abs($p['lat']));
            $lonToken = $this->encodeCoordinatePart(abs($p['lon']));

            return $latToken.substr($lonToken, 1);
        });

        return 'LAT...LON   '.$tokens->implode(' ');
    }

    /**
     * Encodes a coordinate magnitude into the fixed-width digit string
     * ExtractProductMetadata::decodeCoordinatePart() expects: a value under
     * 100 degrees becomes exactly 4 digits (2 integer + 2 fraction, decimal
     * inserted after the 2nd digit on decode), a value of 100 or more
     * becomes exactly 5 digits (3 integer + 2 fraction, decimal inserted
     * after the 3rd digit on decode). number_format() alone isn't enough
     * here -- it always emits exactly 2 decimal places regardless of the
     * integer part's width, so e.g. 5.0 would produce "500" (3 digits, not
     * 4) once the decimal point is stripped. Padding the integer part
     * explicitly guarantees the correct digit count in every case.
     */
    private function encodeCoordinatePart(float $value): string
    {
        $integerDigits = $value >= 100 ? 3 : 2;
        $integerPart = (int) floor($value);
        $fraction = (int) round(($value - $integerPart) * 100);

        if ($fraction >= 100) {
            $integerPart++;
            $fraction = 0;
        }

        return str_pad((string) $integerPart, $integerDigits, '0', STR_PAD_LEFT).str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }

    private function buildMotionLine(array $input, Carbon $issuedAt, ?array $polygonPoints): ?string
    {
        if (! isset($input['motion_direction_degrees'], $input['motion_speed_kt']) || (float) $input['motion_speed_kt'] <= 0) {
            return null;
        }

        if (isset($input['polygon_center_lat'], $input['polygon_center_lon'])) {
            $lat = (float) $input['polygon_center_lat'];
            $lon = abs((float) $input['polygon_center_lon']);
        } elseif ($polygonPoints !== null && $polygonPoints !== []) {
            $lat = collect($polygonPoints)->avg('lat');
            $lon = abs(collect($polygonPoints)->avg('lon'));
        } else {
            return null;
        }

        return sprintf(
            'TIME...MOT...LOC %s %dDEG %dKT %s %s',
            $issuedAt->format('Hi\Z'),
            $input['motion_direction_degrees'],
            $input['motion_speed_kt'],
            $this->encodeCoordinatePart(abs($lat)),
            $this->encodeCoordinatePart($lon),
        );
    }

    /**
     * Livewire's Blade precompiler injects `<!--[if BLOCK]><![endif]-->`
     * morph markers around every @if/@foreach in any compiled view, which
     * would otherwise leak into the raw product text.
     */
    private function clean(string $text): string
    {
        $text = preg_replace('/<!--\[if [A-Z]+\]><!\[endif\]-->/', '', $text);
        $text = preg_replace('/[ \t]+$/m', '', $text);

        return preg_replace('/\n{3,}/', "\n\n", $text);
    }

    private function officeCityFor(string $wfo): string
    {
        $row = DB::table('cwa_boundaries')->where('cwa', $wfo)->first();

        return $row?->citystate ?? $wfo;
    }
}
