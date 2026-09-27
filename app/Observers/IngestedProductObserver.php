<?php

namespace App\Observers;

use App\Actions\Pipeline\SyncActiveWwa;
use App\Actions\Weather\MatchWeatherAlertRules;
use App\Models\IngestedProduct;

class IngestedProductObserver
{
    public function created(IngestedProduct $product): void
    {
        if ($product->replay_of_id !== null) {
            // A replay only exercises alert delivery, so it must not write active_wwas.
            app(MatchWeatherAlertRules::class)($product);

            return;
        }

        if ($product->vtec_office !== null) {
            app(SyncActiveWwa::class)($product);

            return;
        }

        app(MatchWeatherAlertRules::class)($product);
    }
}
