<?php

namespace App\Models;

use Database\Factories\DeliveryLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * `read_at` is the mobile app's read state on a successful `mobile_push` row; it is set only by server code
 * (forceFill or a query update), never mass-assigned from input.
 */
#[Fillable(['alert_rule_id', 'notification_channel_id', 'user_id', 'channel_type', 'status', 'category', 'error_detail', 'subject', 'ingested_product_id', 'batch_key', 'dispatched_at'])]
class DeliveryLog extends Model
{
    /** @use HasFactory<DeliveryLogFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    /**
     * Rows that delivered to one of the subscriber's phones: these put an alert in the app's feed.
     */
    public function scopePushedToPhone(Builder $query): Builder
    {
        return $query->where('channel_type', 'mobile_push')->where('status', 'success');
    }

    /**
     * The rows of one app alert group named by its identity (AppAlert::keyFor()): with a product, the same rule
     * (NULL-safe) and product; without one, the same batch key. A legacy row with neither is a group of one that
     * no identity names, so that case matches nothing.
     */
    public function scopeInAlertGroup(Builder $query, ?int $alertRuleId, ?int $ingestedProductId, ?string $batchKey): Builder
    {
        if ($ingestedProductId !== null) {
            return $query->where('ingested_product_id', $ingestedProductId)->when(
                $alertRuleId === null,
                fn (Builder $query): Builder => $query->whereNull('alert_rule_id'),
                fn (Builder $query): Builder => $query->where('alert_rule_id', $alertRuleId),
            );
        }

        if ($batchKey !== null) {
            return $query->whereNull('ingested_product_id')->where('batch_key', $batchKey);
        }

        return $query->whereRaw('false');
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function isPushedToPhone(): bool
    {
        return $this->channel_type === 'mobile_push' && $this->status === 'success';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function alertRule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class);
    }

    public function notificationChannel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class);
    }

    /**
     * Null for Threshold Alerts / Daily Weather Summary (DeliverScheduledAlert
     * has no product behind them at all) and for a "rule/channel/product
     * gone" skip logged before a product was ever resolved.
     */
    public function ingestedProduct(): BelongsTo
    {
        return $this->belongsTo(IngestedProduct::class);
    }

    /**
     * The events of the bulletin this delivery covered (empty for deliveries logged before
     * segments existed and for non-VTEC products).
     */
    public function segments(): BelongsToMany
    {
        return $this->belongsToMany(IngestedProductSegment::class, 'delivery_log_segment');
    }
}
