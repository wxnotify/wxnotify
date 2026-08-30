<?php

namespace App\Actions\Catalog;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Notifications\AlertRuleDisabledNotification;

class RetireProduct
{
    public function __invoke(Product $product): int
    {
        $product->update(['status' => ProductStatus::Retired]);

        $affectedRules = $product->alertRules()->where('is_active', true)->get();

        foreach ($affectedRules as $rule) {
            $rule->update(['is_active' => false]);
            $rule->user->notify(new AlertRuleDisabledNotification($product, $rule));
        }

        return $affectedRules->count();
    }
}
