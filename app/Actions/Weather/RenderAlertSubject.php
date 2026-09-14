<?php

namespace App\Actions\Weather;

use App\Models\AlertRule;
use App\Models\Product;

/**
 * Renders a per-rule custom notification subject/title via plain
 * placeholder substitution -- logic-less strtr(), nothing executable,
 * since a subscriber-authored template runs on every dispatch of their
 * alerts. Falls back to AlertRule::displayName() (the existing admin/
 * list-table label, unchanged and untouched by this class) when no
 * custom_subject_template is set.
 */
class RenderAlertSubject
{
    public function __invoke(AlertRule $rule, ?Product $product = null): string
    {
        if (blank($rule->effectiveCustomSubjectTemplate())) {
            return $rule->displayName();
        }

        return strtr($rule->effectiveCustomSubjectTemplate(), [
            '{product}' => $product?->name ?? $rule->category->label(),
            '{location}' => $rule->location?->label ?? ($rule->wfo !== null ? "WFO {$rule->wfo}" : 'your area'),
            '{category}' => $rule->category->label(),
        ]);
    }
}
