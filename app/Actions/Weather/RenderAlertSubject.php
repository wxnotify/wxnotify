<?php

namespace App\Actions\Weather;

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
            return $rule->displayName();
        }

        $location ??= $rule->location;

        return strtr($subjectTemplate, [
            '{product}' => $product?->name ?? $rule->category->label(),
            '{location}' => $location?->label ?? ($rule->wfo !== null ? "WFO {$rule->wfo}" : 'your area'),
            '{category}' => $rule->category->label(),
        ]);
    }
}
