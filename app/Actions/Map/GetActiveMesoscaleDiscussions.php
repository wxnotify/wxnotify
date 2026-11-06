<?php

namespace App\Actions\Map;

use App\Models\IngestedProduct;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The SPC Mesoscale Discussions in effect, for the Live map: live (not test, not a replay copy)
 * ingested products of the catalog product SPC.MD received in the last 12 hours that carry a
 * drawable polygon, newest first. Current SPC text is mixed case ("Mesoscale Discussion 2441",
 * "Areas affected...", "Valid ...") and older or EMWIN text upper case, so every pattern ignores
 * case. Each discussion ends at the end of its "Valid ddhhmmZ - ddhhmmZ" line, else three hours
 * after it arrived; ended ones are left out. The text is outside input, so
 * a piece that cannot be read is null (or the default end), never an exception.
 */
class GetActiveMesoscaleDiscussions
{
    public const PRODUCT_CODE = 'SPC.MD';

    private const LOOKBACK_HOURS = 12;

    private const DEFAULT_HOURS = 3;

    private const AREAS_MAX_LENGTH = 160;

    /**
     * A VALID end this much before arrival is a late product that has already ended, not an end
     * in the next month.
     */
    private const LATE_ARRIVAL_HOURS = 24;

    /**
     * @return list<array{id: int, number: ?string, areas: ?string, expires: string, view_url: ?string, geometry: array{type: string, coordinates: list<list<array{0: float, 1: float}>>}}>
     */
    public function __invoke(): array
    {
        $now = CarbonImmutable::now();

        return IngestedProduct::query()
            ->whereHas('product', fn ($query) => $query->where('code', self::PRODUCT_CODE))
            ->where('is_test', false)
            ->whereNull('replay_of_id')
            ->whereNotNull('polygon')
            ->where('received_at', '>=', $now->subHours(self::LOOKBACK_HOURS))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->get(['id', 'text', 'polygon', 'received_at', 'view_slug'])
            ->map(fn (IngestedProduct $product): ?array => $this->discussion($product, $now))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, number: ?string, areas: ?string, expires: string, view_url: ?string, geometry: array{type: string, coordinates: list<list<array{0: float, 1: float}>>}}|null
     */
    private function discussion(IngestedProduct $product, CarbonImmutable $now): ?array
    {
        $ring = $this->ring($product->polygon);
        $text = (string) $product->text;
        $receivedAt = CarbonImmutable::parse($product->received_at)->utc();
        $expires = $this->validEnd($text, $receivedAt) ?? $receivedAt->addHours(self::DEFAULT_HOURS);

        if ($ring === null || $expires->lessThanOrEqualTo($now)) {
            return null;
        }

        return [
            'id' => $product->id,
            'number' => preg_match('/^\s*MESOSCALE DISCUSSION\s+(\d{1,5})\b/im', $text, $match) === 1 ? $match[1] : null,
            'areas' => $this->areas($text),
            'expires' => $expires->toIso8601String(),
            'view_url' => $product->viewUrl(),
            'geometry' => ['type' => 'Polygon', 'coordinates' => [$ring]],
        ];
    }

    /**
     * The "Areas affected..." text up to the next blank line (SPC wraps it), its whitespace
     * collapsed to single spaces, cut to AREAS_MAX_LENGTH.
     */
    private function areas(string $text): ?string
    {
        if (preg_match('/^\h*AREAS AFFECTED\.\.\.(.*?)(?:\R\h*\R|\z)/ims', $text, $match) !== 1) {
            return null;
        }

        $areas = trim((string) preg_replace('/\s+/', ' ', $match[1]));

        return $areas === '' ? null : mb_substr($areas, 0, self::AREAS_MAX_LENGTH);
    }

    /**
     * The end of the VALID line, its day of month taken in the month the product arrived, or the
     * next month when that day has already passed (a discussion issued on the 31st valid to the 1st).
     */
    private function validEnd(string $text, CarbonImmutable $receivedAt): ?CarbonInterface
    {
        if (preg_match('/^\s*VALID\s+\d{6}Z\s*-\s*(\d{2})(\d{2})(\d{2})Z/im', $text, $match) !== 1) {
            return null;
        }

        [$day, $hour, $minute] = [(int) $match[1], (int) $match[2], (int) $match[3]];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        foreach ([0, 1] as $monthsAhead) {
            $month = $receivedAt->startOfMonth()->addMonths($monthsAhead);

            if (! checkdate($month->month, $day, $month->year)) {
                continue;
            }

            $end = $month->setDate($month->year, $month->month, $day)->setTime($hour, $minute);

            if ($end->greaterThanOrEqualTo($receivedAt->subHours(self::LATE_ARRIVAL_HOURS))) {
                return $end;
            }
        }

        return null;
    }

    /**
     * The polygon's points as a closed GeoJSON ring (longitude, latitude), or null when a point is
     * unreadable or the closed ring has fewer than four points (three corners and the closing one).
     *
     * @return list<array{0: float, 1: float}>|null
     */
    private function ring(mixed $points): ?array
    {
        if (! is_array($points)) {
            return null;
        }

        $ring = [];

        foreach ($points as $point) {
            $lat = is_array($point) ? ($point['lat'] ?? null) : null;
            $lon = is_array($point) ? ($point['lon'] ?? null) : null;

            if (! is_numeric($lat) || ! is_numeric($lon) || abs((float) $lat) > 90 || abs((float) $lon) > 180) {
                return null;
            }

            $ring[] = [(float) $lon, (float) $lat];
        }

        if ($ring !== [] && $ring[0] !== $ring[count($ring) - 1]) {
            $ring[] = $ring[0];
        }

        return count($ring) >= 4 ? $ring : null;
    }
}
