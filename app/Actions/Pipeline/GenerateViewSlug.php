<?php

namespace App\Actions\Pipeline;

use App\Models\IngestedProduct;
use Carbon\CarbonImmutable;

class GenerateViewSlug
{
    public function __construct(private readonly ExtractWmoHeaderIdentity $extractWmoHeaderIdentity) {}

    public function __invoke(IngestedProduct $product): string
    {
        $slug = $this->headerSlug($product) ?? $this->fallbackSlug($product);

        $taken = IngestedProduct::query()
            ->where('view_slug', $slug)
            ->whereKeyNot($product->getKey())
            ->exists();

        return $taken ? "{$slug}-{$product->getKey()}" : $slug;
    }

    /**
     * The header's DDHHMM carries no month or year, so the full UTC issue time
     * is placed relative to received_at: the received month, or the previous
     * month when the header's day is later than the received day. Null when
     * the header time is missing or not a real calendar date/time.
     */
    public function issuedAt(IngestedProduct $product): ?CarbonImmutable
    {
        if (! preg_match('/^(\d{2})(\d{2})(\d{2})$/', (string) $product->wmo_ddhhmm, $matches)) {
            return null;
        }

        $day = (int) $matches[1];
        $hour = (int) $matches[2];
        $minute = (int) $matches[3];

        $month = $this->receivedAt($product)->startOfMonth();

        if ($day > $this->receivedAt($product)->day) {
            $month = $month->subMonthNoOverflow();
        }

        if ($hour > 23 || $minute > 59 || ! checkdate($month->month, $day, $month->year)) {
            return null;
        }

        return CarbonImmutable::create($month->year, $month->month, $day, $hour, $minute, 0, 'UTC');
    }

    private function headerSlug(IngestedProduct $product): ?string
    {
        if (blank($product->cccc) || blank($product->ttaaii)) {
            return null;
        }

        $issuedAt = $this->issuedAt($product);

        if ($issuedAt === null) {
            return null;
        }

        $bbb = ($this->extractWmoHeaderIdentity)((string) $product->text)['bbb'] ?? null;

        return $this->normalize(implode('-', array_filter([
            $product->cccc,
            $product->ttaaii,
            $product->awipsid,
            $issuedAt->format('YmdHi'),
            $bbb,
        ])));
    }

    private function fallbackSlug(IngestedProduct $product): string
    {
        return 'nws-'.$this->receivedAt($product)->format('YmdHis').'-'.$product->getKey();
    }

    private function receivedAt(IngestedProduct $product): CarbonImmutable
    {
        return CarbonImmutable::instance($product->received_at ?? now())->utc();
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');
    }
}
