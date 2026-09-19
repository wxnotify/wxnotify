<?php

namespace App\Actions\Pipeline;

class ExtractWmoHeaderIdentity
{
    /**
     * Reads the WMO abbreviated heading ("TTAAII CCCC DDHHMM [BBB]") and the optional AWIPS
     * product-ID line from the top of a product, returning lowercased identity fields and a
     * normalized key shared by every feed carrying the same bulletin.
     *
     * @return ?array{cccc: string, ttaaii: string, wmo_ddhhmm: string, bbb: ?string, awipsid: ?string, key: string}
     */
    public function __invoke(string $text): ?array
    {
        $lines = [];
        foreach (array_slice(preg_split('/\r\n|\r|\n/', $text), 0, 12) as $line) {
            $line = trim(preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        foreach ($lines as $index => $line) {
            if (! preg_match('/^([A-Z]{4}\d{2})\s+([A-Z]{4})\s+(\d{6})(?:\s+([A-Z]{3}))?$/', $line, $m)) {
                continue;
            }

            $awips = null;
            if (isset($lines[$index + 1]) && preg_match('/^[A-Z0-9]{4,7}$/', $lines[$index + 1])) {
                $awips = strtolower($lines[$index + 1]);
            }

            $bbb = isset($m[4]) && $m[4] !== '' ? strtolower($m[4]) : null;
            $key = implode(' ', array_filter([strtolower($m[1]), strtolower($m[2]), $m[3], $bbb, $awips]));

            return [
                'cccc' => strtolower($m[2]),
                'ttaaii' => strtolower($m[1]),
                'wmo_ddhhmm' => $m[3],
                'bbb' => $bbb,
                'awipsid' => $awips,
                'key' => $key,
            ];
        }

        return null;
    }
}
