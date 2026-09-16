<?php

namespace App\Actions\Pipeline;

use Illuminate\Support\Carbon;

/**
 * Parses a text bulletin's own TIME...MOT...LOC line -- e.g.
 * "TIME...MOT...LOC 0052Z 278DEG 15KT 3656 9073" -- into the same
 * motion-vector shape SyncActiveWwa::parseMotion() already derives from
 * CAP's eventMotionDescription parameter. Closes the gap where
 * syncFromText() never wrote motion_direction_degrees/motion_speed_kt at
 * all (only syncFromCap() did): the reason the section-picker template
 * builder's Motion section was effectively unreachable in the common
 * dispatch path, since DeliverWeatherAlert usually fires off the text row
 * before CAP has a chance to arrive.
 *
 * $referenceDate supplies the calendar day for the bulletin's own HHMMz
 * time-of-day, which carries no date of its own -- the product's own
 * received_at, on the same narrow, documented same-day assumption this
 * codebase already makes elsewhere for other day/time-only fields (see
 * ParseVtecString's year-derivation docblock). Known, accepted limitation:
 * a bulletin timestamped just before UTC midnight while received_at has
 * already rolled to the next UTC day (or vice versa) puts
 * motion_observed_at a full day off. Not chased further because nothing
 * in this codebase currently reads or renders motion_observed_at anywhere
 * (FormatMotionVector only consumes motion_direction_degrees/motion_speed_kt)
 * -- revisit if a future consumer actually needs it to be exact.
 *
 * Takes only the FIRST lat/lon pair when a real bulletin lists more than
 * one on the same TIME...MOT...LOC line (confirmed in
 * database/product_examples/SPS.txt -- two endpoints of one storm line's
 * leading edge, not distinct storm cells) -- matches CAP's own
 * single-point eventMotionDescription shape.
 *
 * @return array{motion_observed_at?: Carbon, motion_direction_degrees?: int, motion_speed_kt?: int, motion_reference_lat?: float, motion_reference_lon?: float}
 */
class ParseTextMotionDescription
{
    public function __invoke(string $text, Carbon $referenceDate): array
    {
        if (! preg_match('/^TIME\.\.\.MOT\.\.\.LOC\s+(\d{4})Z\s+(\d{1,3})DEG\s+(\d{1,3})KT\s+(\d{4,5})\s+(\d{4,5})/m', $text, $m)) {
            return [];
        }

        [, $hhmm, $deg, $kt, $latRaw, $lonRaw] = $m;

        return [
            'motion_observed_at' => $referenceDate->copy()->setTime((int) substr($hhmm, 0, 2), (int) substr($hhmm, 2, 2), 0),
            'motion_direction_degrees' => (int) $deg,
            'motion_speed_kt' => (int) $kt,
            'motion_reference_lat' => self::decodeCoordinatePart($latRaw),
            'motion_reference_lon' => -self::decodeCoordinatePart($lonRaw),
        ];
    }

    /**
     * Mirrors ExtractProductMetadata::decodeCoordinatePart()'s decode math
     * exactly (that method is private to its own class, so this is a
     * small, deliberate duplication rather than a cross-class dependency
     * for 4 lines of pure arithmetic): a 4-digit value inserts the decimal
     * after 2 digits (e.g. "3656" -> 36.56), a 5-digit value after 3 (e.g.
     * "11195" -> 111.95, needed for longitudes >= 100 degrees).
     */
    private static function decodeCoordinatePart(string $raw): float
    {
        return strlen($raw) === 5
            ? (float) (substr($raw, 0, 3).'.'.substr($raw, 3))
            : (float) (substr($raw, 0, 2).'.'.substr($raw, 2));
    }
}
