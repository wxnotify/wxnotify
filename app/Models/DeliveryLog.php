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
 * `read_at` is the mobile app's read state on a row in the app's feed; it is set only by server code
 * (forceFill or a query update), never mass-assigned from input. `in_app` marks a `mobile_push` row that was held
 * (it stays `suppressed` everywhere else) but is shown in the app; it means nothing on another channel's row.
 */
#[Fillable(['alert_rule_id', 'notification_channel_id', 'user_id', 'channel_type', 'status', 'category', 'error_detail', 'subject', 'ingested_product_id', 'batch_key', 'dispatched_at', 'in_app'])]
class DeliveryLog extends Model
{
    /** @use HasFactory<DeliveryLogFactory> */
    use HasFactory;

    /**
     * The one definition of a row that is in the mobile app's feed: a phone push that was sent, or one marked to
     * be shown without having been sent. isInAppFeed() is the same rule in PHP, and the partial index
     * `delivery_logs_app_unread_index` repeats it (its migration spells it out), so change them together.
     */
    public const string IN_APP_FEED_SQL = "channel_type = 'mobile_push' AND (status = 'success' OR in_app)";

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'read_at' => 'datetime',
            'in_app' => 'boolean',
        ];
    }

    /**
     * Rows in the mobile app's feed (IN_APP_FEED_SQL): these put an alert there. The SQL is literal, not bound,
     * so PostgreSQL can match it to the partial unread index.
     */
    public function scopeInAppFeed(Builder $query): Builder
    {
        return $query->whereRaw('('.self::IN_APP_FEED_SQL.')');
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

    public function isInAppFeed(): bool
    {
        return $this->channel_type === 'mobile_push' && ($this->status === 'success' || $this->in_app);
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
