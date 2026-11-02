<?php

namespace App\Actions\Weather;

use App\Enums\AlertCategory;
use App\Models\AlertRule;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use App\Models\Location;
use App\Models\Product;
use App\Support\Geography\StateTimezones;
use App\Support\IssuingOffice;
use App\Support\Timezones;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Renders a per-rule custom notification subject/title via plain
 * placeholder substitution -- logic-less strtr(), nothing executable,
 * since a subscriber-authored template runs on every dispatch of their
 * alerts. Priority: the matched product's own subject on the rule (via
 * AlertRule::customSubjectFor()), then an attached AlertTemplate's
 * subject_template (via effectiveAlertTemplate(), tier- and
 * category-gated), then the rule's own custom_subject_template (via
 * effectiveCustomSubjectTemplate()), then the default name (via
 * AlertRule::displayName(), the existing admin/list-table label,
 * unchanged and untouched by this class) when none is set.
 *
 * The product argument is either the one Product that fired or, for a
 * bulletin that matched several events (a Hurricane Watch and a Small Craft
 * Advisory in one message), the list of their names in bulletin order. Several
 * names read as "Hurricane Watch + Small Craft Advisory" everywhere a single
 * product name did.
 */
class RenderAlertSubject
{
    /**
     * $location is the specific location that matched THIS delivery for a
     * multi-location Weather Alerts/SPC & WPC rule (see
     * MatchWeatherAlertRules::matchesAnyLocation()) -- when omitted, falls
     * back to $rule->location, exactly this method's behavior before this
     * parameter existed, which is what every non-Weather-Alerts/SPC-WPC
     * caller (DeliverScheduledAlert) still relies on.
     *
     * @param  Product|list<string>|null  $product  the product that fired, or the names of the events that did
     * @param  Collection<int, IngestedProductSegment>|null  $segments  the matched segments of $bulletin
     */
    public function __invoke(AlertRule $rule, Product|array|null $product = null, ?Location $location = null, ?IngestedProduct $bulletin = null, ?Collection $segments = null): string
    {
        $eventNames = $this->eventNames($product);
        $templateSubject = $rule->effectiveAlertTemplate()?->subject_template;
        $segments ??= new Collection;
        $productId = $this->productId($bulletin, $segments);
        $productSubject = $productId === null ? null : $rule->customSubjectFor($productId);

        $subjectTemplate = match (true) {
            filled($productSubject) => $productSubject,
            filled($templateSubject) => $templateSubject,
            default => $rule->effectiveCustomSubjectTemplate(),
        };

        if (blank($subjectTemplate)) {
            return $this->defaultSubject($rule, $eventNames);
        }

        $location ??= $rule->location;

        $subject = strtr($subjectTemplate, [
            '{product}' => $eventNames === [] ? $rule->category->label() : implode(' + ', $eventNames),
            '{location}' => $location?->label ?? $this->officeOrArea($rule, $bulletin, $segments),
            '{category}' => $rule->category->label(),
            '{until}' => $this->until($bulletin, $segments, $location),
            '{states}' => $this->states($bulletin, $segments),
            '{wfo}' => IssuingOffice::code((string) $bulletin?->cccc) ?? '',
        ]);

        return trim((string) preg_replace('/ {2,}/', ' ', $subject));
    }

    /**
     * The product the subject is about: the first matched segment's, else the bulletin's own.
     *
     * @param  Collection<int, IngestedProductSegment>  $segments
     */
    private function productId(?IngestedProduct $bulletin, Collection $segments): ?int
    {
        $productId = $segments->first()?->product_id ?? $bulletin?->product_id;

        return $productId === null ? null : (int) $productId;
    }

    /**
     * "WFO OKX" for a rule that follows an office, else "your area". A rule saved per product holds
     * the office on the matched product's settings (the first matched segment's product, else the
     * bulletin's), not on the rule.
     *
     * @param  Collection<int, IngestedProductSegment>  $segments
     */
    private function officeOrArea(AlertRule $rule, ?IngestedProduct $bulletin, Collection $segments): string
    {
        $productId = $this->productId($bulletin, $segments);
        $wfo = $rule->wfo ?? ($productId === null ? null : $rule->settingsFor($productId)?->wfo);

        return $wfo !== null ? "WFO {$wfo}" : 'your area';
    }

    /**
     * "until 10:00pm MDT": the latest end among the matched segments (else the bulletin's own), in the
     * location's own timezone. Empty with no end time or no location timezone.
     *
     * @param  Collection<int, IngestedProductSegment>  $segments
     */
    private function until(?IngestedProduct $bulletin, Collection $segments, ?Location $location): string
    {
        $timezone = $location?->timezone;

        if (! Timezones::isValid($timezone)) {
            return '';
        }

        $ends = $segments->isNotEmpty() ? $segments->pluck('vtec_end') : collect([$bulletin?->vtec_end]);
        $end = $ends->filter()->sortByDesc(fn (CarbonInterface $end): int => $end->getTimestamp())->first();

        return $end === null ? '' : 'until '.$end->copy()->setTimezone($timezone)->format('g:ia T');
    }

    /**
     * The distinct states of the matched segments' zones (else the bulletin's own), "FL, GA". Marine and
     * offshore zones name no state and add nothing.
     *
     * @param  Collection<int, IngestedProductSegment>  $segments
     */
    private function states(?IngestedProduct $bulletin, Collection $segments): string
    {
        $codes = $segments->isNotEmpty() ? $segments->flatMap(fn (IngestedProductSegment $segment): array => $segment->ugc_codes ?? []) : collect($bulletin?->ugc_codes ?? []);

        return $codes
            ->map(fn (string $code): string => strtoupper(substr($code, 0, 2)))
            ->filter(fn (string $state): bool => StateTimezones::forState($state) !== null)
            ->unique()
            ->sort()
            ->implode(', ');
    }

    /**
     * @param  Product|list<string>|null  $product
     * @return list<string>
     */
    private function eventNames(Product|array|null $product): array
    {
        $names = is_array($product) ? $product : [$product?->name];

        return array_values(array_filter($names, 'filled'));
    }

    /**
     * Without a template, a Weather Alerts / SPC & WPC alert is titled with the
     * event(s) that actually fired ("SPC Day 4-8 Convective Outlook"), not every product
     * the rule watches. A name the subscriber gave the rule still wins, and the
     * scheduled categories (no product) keep displayName().
     *
     * @param  list<string>  $eventNames
     */
    private function defaultSubject(AlertRule $rule, array $eventNames): string
    {
        $isProductAlert = in_array($rule->category, [AlertCategory::WeatherAlerts, AlertCategory::SpcWpc], true);

        if (! $isProductAlert || filled($rule->name) || $eventNames === []) {
            return $rule->displayName();
        }

        return implode(' + ', $eventNames);
    }
}
