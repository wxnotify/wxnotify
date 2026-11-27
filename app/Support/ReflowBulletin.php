<?php

namespace App\Support;

use Throwable;

/**
 * Joins the hard wraps of an NWS text product back into paragraphs for a phone screen ("reading text").
 *
 * The rules err towards leaving a line as sent: a line is joined to the one before it only when the break
 * looks like a wrap, neither line is layout (codes, headers, tables, data rows, aviation groups) and the
 * line does not start something new. Lines that are not joined are kept byte for byte. Afterwards the
 * words of the input and output are compared in order; on any difference, or any error, the input is
 * returned with only its line endings normalised. Aviation products are excluded by the caller.
 */
class ReflowBulletin
{
    /** NWS wraps at about 66-69 columns; a break is taken as a wrap only when the next word would have passed this. */
    private const WRAP_WIDTH = 60;

    /** The product header (title, office, issuance time) is layout when its issuance time is within this many lines. */
    private const HEADER_LINES = 15;

    /** Words that only prose uses: a line containing any of them is never a row of data (see isDataRow). */
    private const PROSE_WORDS = [
        'a', 'above', 'an', 'and', 'are', 'around', 'as', 'at', 'be', 'below', 'but', 'by', 'can', 'could',
        'for', 'from', 'has', 'have', 'in', 'including', 'into', 'is', 'it', 'may', 'near', 'not', 'of', 'on',
        'or', 'over', 'should', 'than', 'that', 'the', 'then', 'this', 'through', 'to', 'until', 'was',
        'were', 'will', 'with',
    ];

    public static function reflow(string $text): string
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $text);

        try {
            $reflowed = static::joinWrappedLines($normalised);
        } catch (Throwable) {
            return $normalised;
        }

        return self::sameWords($normalised, $reflowed) ? $reflowed : $normalised;
    }

    /**
     * The safety net: true when both texts have exactly the same words in the same order, whatever the spacing.
     */
    public static function sameWords(string $a, string $b): bool
    {
        $wordsOfA = preg_split('/\s+/', $a, -1, PREG_SPLIT_NO_EMPTY);
        $wordsOfB = preg_split('/\s+/', $b, -1, PREG_SPLIT_NO_EMPTY);

        return $wordsOfA !== false && $wordsOfA === $wordsOfB;
    }

    /**
     * Applies the join rules to text whose line endings are already "\n".
     */
    protected static function joinWrappedLines(string $text): string
    {
        $lines = explode("\n", $text);
        $isLayout = self::layoutLines($lines);
        $output = [];
        $blockFirstLine = '';

        foreach ($lines as $index => $line) {
            $last = array_key_last($output);

            if ($last !== null
                && ! $isLayout[$index - 1]
                && ! $isLayout[$index]
                && self::continuesBlock($lines[$index - 1], $line, $blockFirstLine, $output[$last])) {
                $output[$last] = rtrim($output[$last]).' '.trim($line);

                continue;
            }

            $output[] = $line;
            $blockFirstLine = $line;
        }

        return implode("\n", $output);
    }

    /**
     * Marks every line whose layout carries meaning: the product header, each UGC header block (zone codes,
     * VTEC, zone and county names, "Including the cities of", issuance time) up to its blank line, and any
     * line that is layout on its own.
     *
     * @param  list<string>  $lines
     * @return list<bool>
     */
    private static function layoutLines(array $lines): array
    {
        $isLayout = array_map(self::isLayoutLine(...), $lines);

        foreach (array_slice($lines, 0, self::HEADER_LINES) as $index => $line) {
            if (self::isIssuanceTime($line)) {
                array_splice($isLayout, 0, $index + 1, array_fill(0, $index + 1, true));

                break;
            }
        }

        $inUgcBlock = false;

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                $inUgcBlock = false;
            } elseif (self::isUgcLine($line)) {
                $inUgcBlock = true;
            }

            if ($inUgcBlock) {
                $isLayout[$index] = true;
            }
        }

        return $isLayout;
    }

    private static function isLayoutLine(string $line): bool
    {
        $trimmed = trim($line);

        if ($trimmed === '') {
            return false;
        }

        return preg_match('/\S {3,}\S/', $trimmed) === 1                                       // column spacing
            || str_contains($line, "\t")
            || preg_match('/^\d{3}$/', $trimmed) === 1                                           // sequence number
            || preg_match('/^[A-Z]{4}\d{2} [A-Z]{4} \d{6}/', $trimmed) === 1                     // WMO header
            || preg_match('/^[A-Z0-9]{4,6}$/', $trimmed) === 1                                   // AWIPS id
            || $trimmed === '&&'
            || $trimmed === '$$'
            || self::isUgcLine($trimmed)
            || preg_match('/^(\d{3}[->])+(\d{6}-)?$/', $trimmed) === 1                           // UGC continuation
            || preg_match('/^\d{6}-$/', $trimmed) === 1                                          // UGC expiry
            || preg_match('/^\/[OTEX]\.[A-Z]{3}\./', $trimmed) === 1                             // VTEC
            || preg_match('/^\/[A-Z0-9]{5}\.[0-3N]\./', $trimmed) === 1                          // H-VTEC
            || preg_match('/^(LAT\.\.\.LON|TIME\.\.\.MOT)/', $trimmed) === 1
            || preg_match('/^(\d{4,8}\s+)*\d{4,8}$/', $trimmed) === 1                            // coordinate rows
            || self::isIssuanceTime($trimmed)
            || preg_match('/^(TAF\b|[A-Z]{4} \d{6}Z|FM\d{6}|TEMPO |PROB\d{2} |BECMG )/', $trimmed) === 1
            || str_contains($trimmed, '....')                                                    // dot leaders
            || preg_match('/^[=\-_*]{4,}$/', $trimmed) === 1                                     // rule lines
            || self::isDataRow($trimmed);
    }

    private static function isUgcLine(string $line): bool
    {
        return preg_match('/^[A-Z]{2}[CZ](\d{3}|ALL)[->]/', trim($line)) === 1;
    }

    private static function isIssuanceTime(string $line): bool
    {
        return preg_match('/^(Issued at )?\d{3,4} (AM|PM) [A-Z]{3,4} [A-Z]{3} [A-Z]{3} \d{1,2} \d{4}$/i', trim($line)) === 1;
    }

    /**
     * A row of data, such as "MLLW Categories - Minor 5.5 ft, Moderate 6.8 ft, Major 10.0 ft": three or more
     * numbers, no word that only prose uses, and either a " - " label separator or numbers making up at least
     * a third of the words. A sentence that mentions several numbers ("Highs 50 to 55") always has one of
     * those prose words, and a wrapped list of roads ("Interstate 80, State Route 4, State Route 108, ...")
     * is mostly names, so both are still joined.
     */
    private static function isDataRow(string $trimmed): bool
    {
        $words = preg_split('/\s+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
        $numbers = 0;

        foreach ($words as $word) {
            if (in_array(strtolower(trim($word, '.,;:()')), self::PROSE_WORDS, true)) {
                return false;
            }

            if (preg_match('/\d/', $word) === 1) {
                $numbers++;
            }
        }

        return $numbers >= 3 && (str_contains($trimmed, ' - ') || $numbers * 3 >= count($words));
    }

    private static function continuesBlock(string $previous, string $next, string $blockFirstLine, string $block): bool
    {
        if (trim($previous) === '' || trim($next) === '') {
            return false;
        }

        if (self::startsSomethingNew($next) && ! (self::isLabel($next) && self::isOpenHeadline($blockFirstLine, $block))) {
            return false;
        }

        if ($block === $previous && self::endsAnIntroduction($previous) && preg_match('/^[A-Z]/', ltrim($next)) === 1) {
            return false;
        }

        $nextIndent = self::indentOf($next);

        if ($nextIndent === 0 ? self::indentOf($previous) !== 0 : ! self::isHangingIndentOf($blockFirstLine, $nextIndent)) {
            return false;
        }

        return mb_strlen(rtrim($previous)) + 1 + mb_strlen(self::firstUnbreakable($next)) > self::WRAP_WIDTH;
    }

    /**
     * Bullets and numbered items, `.SECTION...` and `...HEADLINE...` lines, `LABEL...` lines, `* ` items, and
     * web addresses (put on their own line on purpose, and long enough to pass the wrap test after any line).
     */
    private static function startsSomethingNew(string $line): bool
    {
        $trimmed = ltrim($line);

        return preg_match('/^(?:[-*]|•|\d{1,2}[.)])\s/', $trimmed) === 1
            || str_starts_with($trimmed, '.')
            || self::isLabel($trimmed)
            || str_starts_with($trimmed, 'Issued at')
            || preg_match('/^(https?:|www\.)/i', $trimmed) === 1;
    }

    /**
     * A line of its own ending in "..." or ":" introduces what follows ("...Eastern North Carolina..." above
     * its text, "Southern Craven County in eastern North Carolina..." in a list, "Winter System):" above a
     * paragraph) when the next line starts with a capital. Inside a paragraph ("Wave Detail:" then "South 7
     * feet") or before lower case ("moves away..." then "expect") it is an ordinary wrap.
     */
    private static function endsAnIntroduction(string $line): bool
    {
        $trimmed = rtrim($line);

        return str_ends_with($trimmed, '...') || str_ends_with($trimmed, ':');
    }

    private static function isLabel(string $line): bool
    {
        return preg_match('/^[A-Z][A-Z0-9 \/&-]{1,30}\.\.\./', ltrim($line)) === 1;
    }

    /** A `...HEADLINE` still being built: its wrapped lines ("TONIGHT...") are not labels. */
    private static function isOpenHeadline(string $blockFirstLine, string $block): bool
    {
        return str_starts_with(ltrim($blockFirstLine), '...') && ! str_ends_with(rtrim($block), '...');
    }

    /**
     * Indented text continues only a bullet (at its text column, or up to two further in) or a `LABEL...`
     * line whose text it lines up with exactly.
     */
    private static function isHangingIndentOf(string $blockFirstLine, int $indent): bool
    {
        if (preg_match('/^(\s*(?:[-*]|•|\d{1,2}[.)])\s+)\S/u', $blockFirstLine, $bullet) === 1) {
            $textColumn = mb_strlen($bullet[1]);

            return $indent >= $textColumn && $indent <= $textColumn + 2;
        }

        if (preg_match('/^(\s*[A-Z][A-Z0-9 \/&-]{1,30}\.\.\.)\S/', $blockFirstLine, $label) === 1) {
            return $indent === strlen($label[1]);
        }

        return false;
    }

    private static function indentOf(string $line): int
    {
        return strlen($line) - strlen(ltrim($line));
    }

    /**
     * The first word of a line, or its first two when it starts with a number: NWS formatters keep a number
     * with its unit ("25 knots"), so the pair is what would not have fitted.
     */
    private static function firstUnbreakable(string $line): string
    {
        $words = preg_split('/\s+/', trim($line), 3, PREG_SPLIT_NO_EMPTY);

        if (count($words) > 1 && preg_match('/^[\d.\/-]+$/', $words[0]) === 1) {
            return $words[0].' '.$words[1];
        }

        return $words[0];
    }
}
