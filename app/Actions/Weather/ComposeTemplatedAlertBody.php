<?php

namespace App\Actions\Weather;

use App\Enums\AlertCategory;
use App\Enums\AlertTemplateSection;
use App\Enums\UnitSystem;
use App\Models\ActiveWwa;
use App\Models\AlertRule;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use App\Support\Units;

/**
 * Composes the labeled-lines message body for a rule with an attached
 * weather_alerts-category AlertTemplate -- "Hazard: ...\nSource: ...",
 * one line per checked section that actually has data for this specific
 * event, always in AlertTemplateSection's fixed declaration order
 * regardless of how `sections` happens to be stored. Returns null in
 * every case where there's nothing to compose (no template, an
 * spc_wpc-category template, a weather_alerts template with no sections
 * checked, or every checked section coming back null for this event) --
 * DeliverWeatherAlert treats null as "fall back to today's existing
 * message_body_style/raw-text behavior", never as an error.
 *
 * Expiration and Motion describe the matched segment's event when one is given (the first one a delivery matched), else the bulletin's first VTEC event; every other section is the bulletin's own narrative.
 */
class ComposeTemplatedAlertBody
{
    public function __invoke(AlertRule $rule, IngestedProduct $product, ?IngestedProductSegment $segment = null): ?string
    {
        $template = $rule->effectiveAlertTemplate();

        if ($template === null || $template->category !== AlertCategory::WeatherAlerts) {
            return null;
        }

        $checked = collect($template->sections ?? [])
            ->map(fn (string $value): ?AlertTemplateSection => AlertTemplateSection::tryFrom($value))
            ->filter()
            ->all();

        if ($checked === []) {
            return null;
        }

        $activeWwa = $this->findActiveWwa($product, $segment);
        $units = Units::forRule($rule)->system;

        $lines = collect(AlertTemplateSection::cases())
            ->filter(fn (AlertTemplateSection $section): bool => in_array($section, $checked, true))
            ->map(fn (AlertTemplateSection $section): ?string => $this->renderSection($section, $product, $activeWwa, $units))
            ->filter()
            ->values();

        return $lines->isEmpty() ? null : $lines->implode("\n");
    }

    private function renderSection(AlertTemplateSection $section, IngestedProduct $product, ?ActiveWwa $activeWwa, UnitSystem $units): ?string
    {
        $value = match ($section) {
            AlertTemplateSection::Hazard => $product->hazard,
            AlertTemplateSection::Source => $product->hazard_source,
            AlertTemplateSection::Impact => $product->impact,
            AlertTemplateSection::LocationsImpacted => $product->locations_impacted,
            AlertTemplateSection::Expiration => $activeWwa?->expires?->diffForHumans(),
            AlertTemplateSection::Motion => app(FormatMotionVector::class)($activeWwa, $units),
            AlertTemplateSection::CallToAction => $product->call_to_action,
        };

        return $value !== null ? "{$section->label()}: {$value}" : null;
    }

    /**
     * Correlates the event this delivery is about to its current-state ActiveWwa row via the VTEC
     * composite key: the matched segment's event when one is given (ActiveWwa::scopeForSegment()),
     * else the bulletin's first VTEC event (ActiveWwa::scopeForProduct(), the same lookup
     * MatchWeatherAlertRules::matchesActiveWwaPolygon() uses).
     */
    private function findActiveWwa(IngestedProduct $product, ?IngestedProductSegment $segment): ?ActiveWwa
    {
        if ($segment !== null) {
            return ActiveWwa::query()->forSegment($segment)->first();
        }

        if ($product->vtec_office === null) {
            return null;
        }

        return ActiveWwa::query()->forProduct($product)->first();
    }
}
