<?php

namespace App\Models;

use App\Models\Concerns\BlockedDuringMaintenance;
use Database\Factories\NotificationChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The `unread_push_count` column is unused (the iOS badge is the unread alert count now); it stays only as
 * a deploy/rollback safety and is dropped in a later release. Nothing may read or write it.
 */
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

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
