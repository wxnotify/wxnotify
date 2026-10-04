<?php

namespace App\Actions\Weather;

use App\Enums\AlertCategory;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\Product;

/**
 * Renders a per-rule custom notification subject/title via plain
 * placeholder substitution -- logic-less strtr(), nothing executable,
 * since a subscriber-authored template runs on every dispatch of their
 * alerts. Priority: an attached AlertTemplate's subject_template first
 * (via effectiveAlertTemplate(), tier- and category-gated), then the
 * rule's own custom_subject_template (via
 * effectiveCustomSubjectTemplate()), then AlertRule::displayName() (the
 * existing admin/list-table label, unchanged and untouched by this class)
 * when neither is set.
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
     */
    public function __invoke(AlertRule $rule, ?Product $product = null, ?Location $location = null): string
    {
        $templateSubject = $rule->effectiveAlertTemplate()?->subject_template;
        $subjectTemplate = filled($templateSubject) ? $templateSubject : $rule->effectiveCustomSubjectTemplate();

        if (blank($subjectTemplate)) {
            return $this->defaultSubject($rule, $product);
        }

        $location ??= $rule->location;

        return strtr($subjectTemplate, [
            '{product}' => $product?->name ?? $rule->category->label(),
            '{location}' => $location?->label ?? ($rule->wfo !== null ? "WFO {$rule->wfo}" : 'your area'),
            '{category}' => $rule->category->label(),
        ]);
    }

    /**
     * Without a template, a Weather Alerts / SPC & WPC alert is titled with the
     * product that actually fired ("SPC Day 4-8 Convective Outlook"), not every product
     * the rule watches. A name the subscriber gave the rule still wins, and the
     * scheduled categories (no product) keep displayName().
     */
    private function defaultSubject(AlertRule $rule, ?Product $product): string
    {
        $isProductAlert = in_array($rule->category, [AlertCategory::WeatherAlerts, AlertCategory::SpcWpc], true);

        if (! $isProductAlert || filled($rule->name) || blank($product?->name)) {
            return $rule->displayName();
        }

        return $product->name;
    }
}
