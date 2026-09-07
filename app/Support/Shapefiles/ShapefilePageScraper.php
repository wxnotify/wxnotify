<?php

namespace App\Support\Shapefiles;

use Illuminate\Support\Carbon;
use Symfony\Component\DomCrawler\Crawler;

class ShapefilePageScraper
{
    /**
     * @return array<int, array{description: string, versions: array<int, array{effective_at: Carbon, filename: string, url: string, record_count: int, checksum: ?string}>}>
     */
    public function scan(string $html): array
    {
        $table = (new Crawler($html))->filter('table')->first();

        $groups = [];
        $currentDescription = null;

        $table->filter('tbody tr')->each(function (Crawler $row) use (&$groups, &$currentDescription): void {
            $cells = $row->filter('td');
            $cellCount = $cells->count();

            if ($cellCount >= 5) {
                $currentDescription = trim($cells->eq(0)->text());
                [$dateCell, $linkCell, $recordsCell, $checksumCell] = [
                    $cells->eq(1), $cells->eq(2), $cells->eq(3), $cells->eq(4),
                ];
            } elseif ($cellCount === 4 && $currentDescription !== null) {
                [$dateCell, $linkCell, $recordsCell, $checksumCell] = [
                    $cells->eq(0), $cells->eq(1), $cells->eq(2), $cells->eq(3),
                ];
            } else {
                return;
            }

            $link = $linkCell->filter('a');

            if ($link->count() === 0) {
                return;
            }

            $href = (string) $link->attr('href');
            $checksumText = $checksumCell->text();
            $checksum = preg_match('/[a-f0-9]{32}/i', $checksumText, $m) === 1 ? strtolower($m[0]) : null;

            $groups[$currentDescription][] = [
                'effective_at' => Carbon::parse(trim($dateCell->text())),
                'filename' => trim($link->text()),
                'url' => str_starts_with($href, 'http') ? $href : 'https://www.weather.gov'.$href,
                'record_count' => (int) trim($recordsCell->text()),
                'checksum' => $checksum,
            ];
        });

        return collect($groups)
            ->map(fn (array $versions, string $description): array => [
                'description' => $description,
                'versions' => $versions,
            ])
            ->values()
            ->all();
    }
}
