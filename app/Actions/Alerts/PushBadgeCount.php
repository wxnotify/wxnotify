<?php

namespace App\Actions\Alerts;

use App\Models\DeliveryLog;
use App\Models\User;

/**
 * The iOS icon number to send with a push: the subscriber's unread app alerts (CountUnreadAppAlerts), plus one
 * when the push is an alert whose group is not already unread. The delivery row of this push does not exist yet
 * (the jobs write it after the send), so the pushed alert is added here: it adds one unless the subscriber already
 * has an unread successful push in the same group (with a product: same rule, NULL-safe, and same product; without
 * one: same batch key, e.g. the push to the subscriber's other phone in the same run), i.e. a read group or a new
 * one counts. A push with neither (a job queued before batch keys) is always a new single-row group. A push that is not
 * an alert (the test push, which writes no delivery row) gets the plain unread count. Two near-simultaneous pushes
 * can be off by one; the app corrects the icon from the feed's `unread_count`.
 */
class PushBadgeCount
{
    public function __invoke(User $user, ?int $alertRuleId, ?int $ingestedProductId, bool $countsAsAlert, ?string $batchKey = null): int
    {
        $unread = (new CountUnreadAppAlerts)($user);

        if (! $countsAsAlert) {
            return $unread;
        }

        if ($ingestedProductId === null && $batchKey === null) {
            return $unread + 1;
        }

        $groupIsUnread = DeliveryLog::query()
            ->where('user_id', $user->id)
            ->when(
                $ingestedProductId !== null,
                fn ($query) => $query
                    ->where('ingested_product_id', $ingestedProductId)
                    ->when(
                        $alertRuleId === null,
                        fn ($query) => $query->whereNull('alert_rule_id'),
                        fn ($query) => $query->where('alert_rule_id', $alertRuleId),
                    ),
                fn ($query) => $query->whereNull('ingested_product_id')->where('batch_key', $batchKey),
            )
            ->pushedToPhone()
            ->unread()
            ->exists();

        return $groupIsUnread ? $unread : $unread + 1;
    }
}
