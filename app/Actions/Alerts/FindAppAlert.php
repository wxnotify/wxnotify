<?php

namespace App\Actions\Alerts;

use App\Models\DeliveryLog;
use App\Models\User;
use App\Support\AppAlert;

/**
 * One of the subscriber's alerts for the mobile app, by the id the feed gave out, or null
 * when it is unknown, belongs to someone else or never reached a phone (no successful push
 * row in its group, so it is not in the feed either). Any row id of a group finds the whole group (the same three
 * cases as AppAlert::keyFor()), whose id is still its lowest row id.
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
                $anchor->ingested_product_id !== null,
                fn ($query) => $query
                    ->where('ingested_product_id', $anchor->ingested_product_id)
                    ->where('alert_rule_id', $anchor->alert_rule_id),
                fn ($query) => $anchor->batch_key !== null
                    ? $query->whereNull('ingested_product_id')->where('batch_key', $anchor->batch_key)
                    : $query->whereKey($anchor->id),
            )
            ->with(['alertRule.products', 'alertRule.location', 'notificationChannel', 'ingestedProduct'])
            ->orderBy('id')
            ->get();

        $alert = new AppAlert($rows);

        return $alert->pushRows()->isEmpty() ? null : $alert;
    }
}
