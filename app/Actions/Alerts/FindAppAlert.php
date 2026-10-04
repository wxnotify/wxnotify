<?php

namespace App\Actions\Alerts;

use App\Models\DeliveryLog;
use App\Models\User;
use App\Support\AppAlert;

/**
 * One of the subscriber's alerts for the mobile app, by the id the feed gave out, or null
 * when it is unknown or belongs to someone else.
 */
class FindAppAlert
{
    public function __invoke(User $user, int $id): ?AppAlert
    {
        $anchor = DeliveryLog::query()->where('user_id', $user->id)->find($id);

        if ($anchor === null) {
            return null;
        }

        $rows = DeliveryLog::query()
            ->where('user_id', $user->id)
            ->when(
                $anchor->ingested_product_id === null,
                fn ($query) => $query->whereKey($anchor->id),
                fn ($query) => $query
                    ->where('ingested_product_id', $anchor->ingested_product_id)
                    ->where('alert_rule_id', $anchor->alert_rule_id),
            )
            ->with(['alertRule.products', 'alertRule.location', 'notificationChannel', 'ingestedProduct'])
            ->orderBy('id')
            ->get();

        return new AppAlert($rows);
    }
}
