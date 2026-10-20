<?php

namespace App\Support;

use App\Models\DeliveryLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * One alert as the app sees it: every delivery row of one subscriber for one rule and one
 * product; without a product, every row of one batch (one scheduled or lightning run of a rule,
 * sent to each of its channels), and a row with neither is an alert on its own. The id is the lowest row id, so it
 * stays the same when a later delivery joins the group.
 */
final class AppAlert
{
    /**
     * @param  Collection<int, DeliveryLog>  $rows  ordered by id, never empty
     */
    public function __construct(public readonly Collection $rows) {}

    public function id(): int
    {
        return $this->rows->first()->id;
    }

    public function first(): DeliveryLog
    {
        return $this->rows->first();
    }

    public function latestAt(): CarbonInterface
    {
        return $this->rows->sortBy('dispatched_at')->last()->dispatched_at;
    }

    /**
     * The rows that delivered this alert to one of the subscriber's phones (DeliveryLog::scopePushedToPhone()).
     *
     * @return Collection<int, DeliveryLog>
     */
    public function pushRows(): Collection
    {
        return $this->rows->filter(fn (DeliveryLog $row): bool => $row->isPushedToPhone())->values();
    }

    /**
     * Unread while any push row is unread, so a new push to an alert already read makes it unread again.
     */
    public function isUnread(): bool
    {
        return $this->pushRows()->contains(fn (DeliveryLog $row): bool => $row->read_at === null);
    }

    /**
     * The grouping rule, mirrored in SQL by ListAppAlerts::GROUP_KEY.
     */
    public static function keyFor(DeliveryLog $row): string
    {
        if ($row->ingested_product_id !== null) {
            return ($row->alert_rule_id ?? 'none').'-'.$row->ingested_product_id;
        }

        return $row->batch_key !== null ? 'batch-'.$row->batch_key : 'row-'.$row->id;
    }
}
