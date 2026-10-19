<?php

namespace App\Models;

use App\Models\Concerns\BlockedDuringMaintenance;
use Database\Factories\NotificationChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['user_id', 'channel_type', 'label', 'apprise_url', 'config', 'personal_access_token_id', 'device_token_hash'])]
class NotificationChannel extends Model
{
    /** @use HasFactory<NotificationChannelFactory> */
    use BlockedDuringMaintenance, HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        // Deliberately excludes apprise_url and config: they're encrypted columns
        // that can carry webhook secrets/tokens, and activitylog logs the decrypted
        // attribute value into a plain-text properties column.
        return LogOptions::defaults()
            ->logOnly(['user_id', 'channel_type', 'label', 'verified_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'apprise_url' => 'encrypted',
            'config' => 'encrypted:array',
            'verified_at' => 'datetime',
            'confirmation_code_expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Adds one to this phone's unread push count in a single atomic statement (no model save, so
     * no activity-log row and no lost update) and returns the new total, or null when the row is gone
     * (the channel was deleted after it was loaded).
     */
    public function incrementUnreadPushCount(): ?int
    {
        $row = DB::selectOne(
            'UPDATE notification_channels SET unread_push_count = unread_push_count + 1 WHERE id = ? RETURNING unread_push_count',
            [$this->getKey()],
        );

        if ($row === null) {
            return null;
        }

        $this->setRawAttributes(['unread_push_count' => $row->unread_push_count] + $this->attributes, true);

        return (int) $row->unread_push_count;
    }

    public function resetUnreadPushCount(): void
    {
        static::query()->whereKey($this->getKey())->toBase()->update(['unread_push_count' => 0]);

        $this->setRawAttributes(['unread_push_count' => 0] + $this->attributes, true);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
