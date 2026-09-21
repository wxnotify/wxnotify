<?php

namespace App\Models;

use App\Models\Concerns\BlockedDuringMaintenance;
use Database\Factories\TraccarServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['user_id', 'label', 'base_url', 'api_token'])]
class TraccarServer extends Model
{
    /** @use HasFactory<TraccarServerFactory> */
    use BlockedDuringMaintenance, HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        // Deliberately excludes api_token: it's an encrypted column carrying
        // a real Traccar credential, and activitylog logs the decrypted
        // attribute value into a plain-text properties column -- mirrors
        // NotificationChannel's identical treatment of apprise_url.
        return LogOptions::defaults()
            ->logOnly(['user_id', 'label', 'base_url'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'last_polled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
