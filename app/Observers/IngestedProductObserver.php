<?php

namespace App\Observers;

use App\Actions\Weather\MatchWeatherAlertRules;
use App\Models\IngestedProduct;

class IngestedProductObserver
{
    public function created(IngestedProduct $product): void
    {
        app(MatchWeatherAlertRules::class)($product);
    }
}
