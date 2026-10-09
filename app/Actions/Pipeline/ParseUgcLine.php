<?php

namespace App\Actions\Pipeline;

/**
 * Reads the first UGC block of a product (or of one section of it): the dash-separated zone and
 * county codes that sit just above the VTEC line and end in a 6-digit DDHHMM purge time, e.g.
 * "NYZ069-070>075-081200-".
 *
 * A bare 3-digit token continues the most recent state+type prefix; a range ("070>075") expands
 * to every code between its ends and may follow a bare number or a full code ("CAZ110>114"); the
 * prefix may change inside the line ("MSZ086-LAZ070-076-"); and a long list wraps onto further
 * lines, each ending in a dash, until the line that carries the purge time. A line that merely
 * looks like one ("Baldwin Coastal-", the zone names that follow) is never a zone line because it
 * contains lowercase letters or spaces.
 */
class ParseUgcLine
{
    private const BLOCK_START = '/^[A-Z]{2}[CZ]\d{3}(?:>\d{3})?-/';

    private const BLOCK_LINE = '/^[A-Z0-9>-]+-$/';

    private const BLOCK = '/^(?:[A-Z]{2}[CZ]\d{3}|\d{3})(?:>\d{3})?(?:-(?:[A-Z]{2}[CZ]\d{3}|\d{3})(?:>\d{3})?)*-\d{6}-$/';

    /**
     * @return ?list<string> each code once, in the order written; null when the text has no zone block
     */
    public function __invoke(string $text): ?array
    {
        $lines = array_map('trim', preg_split('/\r\n|\r|\n/', $text));

        foreach ($lines as $start => $line) {
            if (! preg_match(self::BLOCK_START, $line)) {
                continue;
            }

            $block = $this->collectBlock($lines, $start);

            if ($block !== null) {
                return $this->expand($block);
            }
        }

        return null;
    }

    /**
     * Joins the lines from $start up to the one carrying the purge time. Null when a line in
     * between is not a zone line, or the text ends first, or the joined block is not valid.
     *
     * @param  list<string>  $lines
     */
    private function collectBlock(array $lines, int $start): ?string
    {
        $block = '';

        for ($i = $start; $i < count($lines); $i++) {
            if (! preg_match(self::BLOCK_LINE, $lines[$i])) {
                return null;
            }

            $block .= $lines[$i];

            if (preg_match('/-\d{6}-$/', $block)) {
                return preg_match(self::BLOCK, $block) ? $block : null;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function expand(string $block): array
    {
        $codes = [];
        $prefix = null;

        foreach (explode('-', substr($block, 0, -8)) as $token) {
            if (! preg_match('/^(?:([A-Z]{2}[CZ])(\d{3})|(\d{3}))(?:>(\d{3}))?$/', $token, $m)) {
                continue;
            }

            if ($m[1] !== '') {
                $prefix = $m[1];
            }

            $first = (int) ($m[1] !== '' ? $m[2] : $m[3]);
            $last = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : $first;

            foreach (range($first, $last) as $number) {
                $codes[] = $prefix.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
            }
        }

        return array_values(array_unique($codes));
    }
}
