<?php

namespace App\Actions\Alerts;

use App\Models\DeliveryLog;
use App\Models\User;
use App\Support\AlertRef;
use App\Support\AppAlert;

/**
 * One of the subscriber's alerts for the mobile app, by the id the feed gave out, or null
 * when it is unknown, belongs to someone else or never reached a phone (no successful push
 * row in its group, so it is not in the feed either). Any row id of a group finds the whole group (the same three
 * cases as AppAlert::keyFor()), whose id is still its lowest row id.
 */
class FindAppAlert
{
    private const RELATIONS = ['alertRule.products', 'alertRule.location', 'notificationChannel', 'ingestedProduct'];

    public function __invoke(User $user, int $id): ?AppAlert
    {
        $anchor = DeliveryLog::query()->where('user_id', $user->id)->find($id);

        if ($anchor === null) {
            return null;
        }

        $rows = DeliveryLog::query()
            ->where('user_id', $user->id)
            ->when(
                $anchor->ingested_product_id !== null || $anchor->batch_key !== null,
                fn ($query) => $query->inAlertGroup($anchor->alert_rule_id, $anchor->ingested_product_id, $anchor->batch_key),
                fn ($query) => $query->whereKey($anchor->id),
            )
            ->with(self::RELATIONS)
            ->orderBy('id')
            ->get();

        $alert = new AppAlert($rows);

        return $alert->pushRows()->isEmpty() ? null : $alert;
    }

    /**
     * The alert a push's tap link names (AlertRef): the subscriber's own group for that reference, or null when the
     * reference is malformed, nothing is written for it yet (the push is tapped while its delivery row is being
     * written), it is someone else's, or it never reached a phone.
     */
    public function byRef(User $user, string $ref): ?AppAlert
    {
        $group = AlertRef::parse($ref);

        if ($group === null) {
            return null;
        }

        $rows = DeliveryLog::query()
            ->where('user_id', $user->id)
            ->inAlertGroup($group['alert_rule_id'], $group['ingested_product_id'], $group['batch_key'])
            ->with(self::RELATIONS)
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $alert = new AppAlert($rows);

        return $alert->pushRows()->isEmpty() ? null : $alert;
    }
}
