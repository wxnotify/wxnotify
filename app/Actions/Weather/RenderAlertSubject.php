<?php

namespace App\Actions\Weather;

use App\Models\AlertRule;
use App\Models\Product;

/**
 * Renders a per-rule custom notification subject/title via plain
 * placeholder substitution -- logic-less strtr(), nothing executable,
 * since a subscriber-authored template runs on every dispatch of their
 * alerts. Priority: an attached AlertTemplate's subject_template first,
 * then the rule's own custom_subject_template (via
 * effectiveCustomSubjectTemplate()), then AlertRule::displayName() (the
 * existing admin/list-table label, unchanged and untouched by this class)
 * when neither is set.
 */
class RenderAlertSubject
{
    public function __invoke(AlertRule $rule, ?Product $product = null): string
    {
        $templateSubject = $rule->alertTemplate?->subject_template;
        $subjectTemplate = filled($templateSubject) ? $templateSubject : $rule->effectiveCustomSubjectTemplate();

        if (blank($subjectTemplate)) {
            return $rule->displayName();
        }

        return strtr($subjectTemplate, [
            '{product}' => $product?->name ?? $rule->category->label(),
            '{location}' => $rule->location?->label ?? ($rule->wfo !== null ? "WFO {$rule->wfo}" : 'your area'),
            '{category}' => $rule->category->label(),
        ]);
    }
}
