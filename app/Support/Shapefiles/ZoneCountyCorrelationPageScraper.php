<?php

namespace App\Support\Shapefiles;

use Illuminate\Support\Carbon;
use Symfony\Component\DomCrawler\Crawler;

class ZoneCountyCorrelationPageScraper
{
    /**
     * @return array<int, array{description: string, versions: array<int, array{effective_at: Carbon, filename: string, url: string, record_count: null, checksum: null}>}>
     */
    public function scan(string $html): array
    {
        $table = (new Crawler($html))->filter('table')->first();

        $groups = [];
        $currentDescription = null;

        $table->filter('tbody tr')->each(function (Crawler $row) use (&$groups, &$currentDescription): void {
            $cells = $row->filter('td');
            $cellCount = $cells->count();

            if ($cellCount > 0 && trim($cells->eq(0)->text()) === 'Description') {
                return;
            }

            if ($cellCount === 3) {
                $currentDescription = trim($cells->eq(0)->text());
                [$dateCell, $linkCell] = [$cells->eq(1), $cells->eq(2)];
            } elseif ($cellCount === 2 && $currentDescription !== null) {
                [$dateCell, $linkCell] = [$cells->eq(0), $cells->eq(1)];
            } else {
                return;
            }

            $link = $linkCell->filter('a');

            if ($link->count() === 0) {
                return;
            }

            $href = (string) $link->attr('href');
            $dateText = explode("\n", str_replace(["\r"], '', $dateCell->text()))[0];
            $dateText = trim(preg_replace('/Date last upload.*/i', '', $dateText));

            $groups[$currentDescription][] = [
                'effective_at' => Carbon::parse($dateText),
                'filename' => trim($link->text()),
                'url' => str_starts_with($href, 'http') ? $href : 'https://www.weather.gov'.$href,
                'record_count' => null,
                'checksum' => null,
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
