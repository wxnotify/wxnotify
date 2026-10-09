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
     */
    public function __invoke(AlertRule $rule, Product|array|null $product = null, ?Location $location = null): string
    {
        $eventNames = $this->eventNames($product);
        $templateSubject = $rule->effectiveAlertTemplate()?->subject_template;
        $subjectTemplate = filled($templateSubject) ? $templateSubject : $rule->effectiveCustomSubjectTemplate();

        if (blank($subjectTemplate)) {
            return $this->defaultSubject($rule, $eventNames);
        }

        $location ??= $rule->location;

        return strtr($subjectTemplate, [
            '{product}' => $eventNames === [] ? $rule->category->label() : implode(' + ', $eventNames),
            '{location}' => $location?->label ?? ($rule->wfo !== null ? "WFO {$rule->wfo}" : 'your area'),
            '{category}' => $rule->category->label(),
        ]);
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
