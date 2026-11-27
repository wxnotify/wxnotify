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

    /** Lines longer than this were not wrapped by a formatter, so they do not count towards a paragraph's width. */
    private const LONGEST_WRAPPED_LINE = 72;

    /** A whole line shorter than this that ends in "..." or ":" introduces what follows ("Locations impacted include..."). */
    private const SHORT_INTRODUCTION = 40;

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
        $paragraphWidths = self::paragraphWidths($lines, $isLayout);
        $output = [];
        $blockFirstLine = '';
        $hangingIndent = null;

        foreach ($lines as $index => $line) {
            $last = array_key_last($output);

            if ($last !== null
                && ! $isLayout[$index - 1]
                && ! $isLayout[$index]
                && self::continuesBlock($lines[$index - 1], $line, $blockFirstLine, $output[$last], $paragraphWidths[$index], $hangingIndent)) {
                $output[$last] = rtrim($output[$last]).' '.trim($line);

                continue;
            }

            $hangingIndent = self::hangingIndentOf($line, $index > 0 ? $lines[$index - 1] : '', $hangingIndent);
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

    /**
     * The width each line's paragraph (its run of non-blank lines) was wrapped at, at least: its longest
     * prose line. CAP text has no blank lines, so there the whole field is one paragraph.
     *
     * @param  list<string>  $lines
     * @param  list<bool>  $isLayout
     * @return list<int>
     */
    private static function paragraphWidths(array $lines, array $isLayout): array
    {
        $widths = array_fill(0, count($lines), 0);
        $start = 0;

        foreach ([...$lines, ''] as $index => $line) {
            if (trim($line) !== '') {
                continue;
            }

            $width = 0;

            for ($i = $start; $i < $index; $i++) {
                $length = mb_strlen(rtrim($lines[$i]));

                if (! $isLayout[$i] && $length <= self::LONGEST_WRAPPED_LINE) {
                    $width = max($width, $length);
                }
            }

            for ($i = $start; $i <= $index && $i < count($lines); $i++) {
                $widths[$i] = $width;
            }

            $start = $index + 1;
        }

        return $widths;
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
            || str_starts_with($trimmed, ':')                                                    // SHEF comment lines
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
            || preg_match('/\b\d{1,2}\.\d[NS]\s+\d{1,3}\.\d[EW]\b/', $trimmed) === 1                  // tropical cyclone position
            || preg_match('/\b\d+ [NESW]{1,3} [A-Z0-9]{3}\b/', $trimmed) === 1                          // SPC outlook points (90 SSW PNS)
            || self::endsWithShortValue($trimmed)
            || str_contains($trimmed, '....')                                                    // dot leaders
            || preg_match('/^[=\-_*]{4,}$/', $trimmed) === 1                                     // rule lines
            || self::isDataRow($trimmed);
    }

    private static function isUgcLine(string $line): bool
    {
        return preg_match('/^[A-Z]{2}[CZ](\d{3}|ALL)([->](\d{3}|[A-Z]{2}[CZ]\d{3}|ALL))*-(\d{6}-)?$/', trim($line)) === 1;
    }

    private static function isIssuanceTime(string $line): bool
    {
        return preg_match('/^(Issued at )?\d{3,4} ((AM|PM) [A-Z]{3,4}|UTC) [A-Z]{3} [A-Z]{3} \d{1,2} \d{4}$/i', trim($line)) === 1;
    }

    /**
     * A labelled value row: a short value with a number after the last "...", as in storm surge rows
     * ("Bonita Beach to Chokoloskee...8-12 ft") and advisory summaries ("MAXIMUM SUSTAINED WINDS...90 MPH").
     */
    private static function endsWithShortValue(string $trimmed): bool
    {
        $lastEllipsis = strrpos($trimmed, '...');

        if ($lastEllipsis === false) {
            return false;
        }

        $value = trim(substr($trimmed, $lastEllipsis + 3));

        return $value !== '' && strlen($value) <= 15 && preg_match('/\d/', $value) === 1;
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

    /**
     * @param  array{from: int, to: int, label: bool}|null  $hangingIndent
     */
    private static function continuesBlock(string $previous, string $next, string $blockFirstLine, string $block, int $paragraphWidth, ?array $hangingIndent): bool
    {
        if (trim($previous) === '' || trim($next) === '') {
            return false;
        }

        $inOpenHeadline = self::isOpenHeadline($blockFirstLine, $block);

        if (self::startsSomethingNew($next) && ! (self::isLabel($next) && $inOpenHeadline)) {
            return false;
        }

        if (! $inOpenHeadline && (self::isClosedHeadline($blockFirstLine, $block) || self::isShortIntroduction($next))) {
            return false;
        }

        if ($block === $previous && self::endsAnIntroduction($previous) && preg_match('/^[A-Z0-9(]/', ltrim($next)) === 1) {
            return false;
        }

        if (str_ends_with(rtrim($previous), '.') && self::startsAListRow($next, $blockFirstLine)) {
            return false;
        }

        $nextIndent = self::indentOf($next);

        if ($nextIndent === 0 ? self::indentOf($previous) !== 0 : ! self::continuesHangingIndent($hangingIndent, $nextIndent, $previous, $next)) {
            return false;
        }

        return mb_strlen(rtrim($previous)) + 1 + mb_strlen(self::firstUnbreakable($next)) > self::wrapWidthFor($next, $paragraphWidth);
    }

    /**
     * A wrapped line seldom starts with a lower-case word on purpose, so those are measured against the plain
     * NWS width. Any other line (a capital, a number, a bracket) may be a new sentence put on its own line
     * ("The next statement will be issued..." in a CAP instruction), so it counts as a wrap only if the word
     * would not have fitted within the paragraph's own width (less two columns for the indent CAP strips
     * from bullets).
     */
    private static function wrapWidthFor(string $next, int $paragraphWidth): int
    {
        return preg_match('/^[a-z]/', ltrim($next)) === 1 ? self::WRAP_WIDTH : max(self::WRAP_WIDTH, $paragraphWidth - 2);
    }

    private static function startsWithCapital(string $line): bool
    {
        return preg_match('/^[A-Z]/', ltrim($line)) === 1;
    }

    /**
     * After a line that ends a sentence, the next row of a list: a period label naming a day or part of one
     * ("Friday Night: Mainly IFR", but not "Wave Detail: North 3 feet"),
     * a row starting with the same word as the one before ("Interstate 80 ..." after "Interstate 74 ..."),
     * a numbered row after another ("25-60 hours before landfall..." after "0-24 hours ..."), a bracketed note,
     * or a river row ("Alafia River At Lithia affecting Hillsborough County.").
     */
    private static function startsAListRow(string $next, string $blockFirstLine): bool
    {
        $trimmed = trim($next);
        $firstWord = strtok($trimmed, ' ');

        return (preg_match('/^[A-Z][A-Za-z ]{0,20}:\s/', $trimmed) === 1
                && preg_match('/^[A-Za-z ]*\b(Today|Tonight|Overnight|Morning|Afternoon|Evening|Night|Day|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\b[A-Za-z ]*:/', $trimmed) === 1)
            || ($firstWord !== false && $firstWord === strtok(trim($blockFirstLine), ' '))
            || (preg_match('/^\d/', $trimmed) === 1 && preg_match('/^\d/', trim($blockFirstLine)) === 1)
            || str_starts_with($trimmed, '(')
            || preg_match('/ affecting .+ Count(y|ies)\.$/', $trimmed) === 1;
    }

    /** A `...HEADLINE...` that has been closed: nothing joins after it. */
    private static function isClosedHeadline(string $blockFirstLine, string $block): bool
    {
        return str_starts_with(ltrim($blockFirstLine), '...') && str_ends_with(rtrim($block), '...');
    }

    /** "Locations impacted include...", "FORECAST CONFIDENCE & DEVIATIONS:": a whole short line introducing what follows. */
    private static function isShortIntroduction(string $line): bool
    {
        return mb_strlen(trim($line)) < self::SHORT_INTRODUCTION && self::endsAnIntroduction($line);
    }

    /**
     * Bullets and numbered items, `.SECTION...` and `...HEADLINE...` lines, `LABEL...` lines, `* ` items,
     * `**HEADLINE**` lines, and web addresses (put on their own line on purpose, and long enough to pass the
     * wrap test after any line).
     */
    private static function startsSomethingNew(string $line): bool
    {
        $trimmed = ltrim($line);

        return preg_match('/^(?:[-*]|•|\d{1,2}[.)])\s/', $trimmed) === 1
            || str_starts_with($trimmed, '.')
            || self::isLabel($trimmed)
            || str_starts_with($trimmed, 'Issued at')
            || str_starts_with($trimmed, '**')
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
     * The indents that may continue a block starting with this line: a bullet's text column (or up to two
     * further in), or exactly the text column of a `LABEL...` line. An indented line that is the next item
     * under a label ("     Wind Advisory from 4 PM..." under "VT...Winter Weather Advisory ...") keeps the
     * label's column.
     *
     * @param  array{from: int, to: int, label: bool}|null  $previousBlockIndent
     * @return array{from: int, to: int, label: bool}|null
     */
    private static function hangingIndentOf(string $firstLine, string $previous, ?array $previousBlockIndent): ?array
    {
        if (preg_match('/^(\s*(?:[-*]|•|\d{1,2}[.)])\s+)\S/u', $firstLine, $bullet) === 1) {
            $textColumn = mb_strlen($bullet[1]);

            return ['from' => $textColumn, 'to' => $textColumn + 2, 'label' => false];
        }

        if (preg_match('/^(\s*[A-Z][A-Z0-9 \/&-]{1,30}\.\.\.)\S/', $firstLine, $label) === 1) {
            return ['from' => strlen($label[1]), 'to' => strlen($label[1]), 'label' => true];
        }

        if ($previousBlockIndent !== null && $previousBlockIndent['label'] && trim($previous) !== ''
            && self::indentOf($firstLine) === $previousBlockIndent['from']) {
            return $previousBlockIndent;
        }

        return null;
    }

    /**
     * Indented text continues only a bullet or a label at its hanging indent; under a label, a capital after a
     * full stop is the label's next item ("Wind Advisory from 4 PM..." after "... for VTZ003.").
     *
     * @param  array{from: int, to: int, label: bool}|null  $hangingIndent
     */
    private static function continuesHangingIndent(?array $hangingIndent, int $indent, string $previous, string $next): bool
    {
        if ($hangingIndent === null || $indent < $hangingIndent['from'] || $indent > $hangingIndent['to']) {
            return false;
        }

        return ! ($hangingIndent['label'] && str_ends_with(rtrim($previous), '.') && self::startsWithCapital($next));
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
