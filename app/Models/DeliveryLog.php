<?php

namespace App\Models;

use Database\Factories\DeliveryLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['alert_rule_id', 'notification_channel_id', 'user_id', 'channel_type', 'status', 'error_detail', 'subject', 'ingested_product_id', 'dispatched_at'])]
class DeliveryLog extends Model
{
    /** @use HasFactory<DeliveryLogFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
        ];
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
}
