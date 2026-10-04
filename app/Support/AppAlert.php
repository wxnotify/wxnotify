<?php

namespace App\Support;

use App\Models\DeliveryLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * One alert as the app sees it: every delivery row of one subscriber for one rule and one
 * product (a row with no product is an alert on its own). The id is the lowest row id, so it
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

    public function hasFailure(): bool
    {
        return $this->rows->contains('status', 'failure');
    }

    /**
     * The grouping rule, mirrored in SQL by ListAppAlerts::GROUP_KEY.
     */
    public static function keyFor(DeliveryLog $row): string
    {
        return $row->ingested_product_id === null
            ? 'row-'.$row->id
            : ($row->alert_rule_id ?? 'none').'-'.$row->ingested_product_id;
    }
}
