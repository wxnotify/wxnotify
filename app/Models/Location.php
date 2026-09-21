<?php

namespace App\Models;

use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Models\Concerns\BlockedDuringMaintenance;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'user_id', 'label', 'type', 'address', 'lat', 'lng', 'timezone',
    'mobile_source', 'mobile_update_interval_minutes',
    'traccar_server_id', 'traccar_device_id', 'traccar_device_name',
])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use BlockedDuringMaintenance, HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        // logAll() preserves original behavior for every real field (including
        // lat/lng, whether a stationary pin is repositioned or a mobile fix is
        // manually corrected). Only mobile_position_updated_at is excluded: it's
        // a bookkeeping timestamp that ticks every 5-30 minutes forever purely
        // from Traccar polling, not user-meaningful data, and would otherwise
        // drown out everything else about this model in noise.
        return LogOptions::defaults()
            ->logAll()
            ->logExcept(['mobile_position_updated_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'mobile_source' => MobileSource::class,
            'mobile_position_updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function traccarServer(): BelongsTo
    {
        return $this->belongsTo(TraccarServer::class);
    }

    /**
     * A mobile location has no coordinates until its first successful
     * Traccar poll. Every call site that dereferences lat/lng for matching
     * must check this first -- a plain (float) cast on null silently
     * becomes 0.0 ("Null Island"), not a safe "no data" signal.
     */
    public function hasFix(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }
}
