<?php

namespace App\Models;

use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Models\Concerns\BlockedDuringMaintenance;
use App\Support\Geography\GreatCircle;
use Carbon\CarbonInterface;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'user_id', 'label', 'type', 'address', 'lat', 'lng', 'timezone',
    'mobile_source', 'mobile_update_interval_minutes',
    'traccar_server_id', 'traccar_device_id', 'traccar_device_name',
])]
class Location extends Model
{
    /** How far a mobile location moves before its address is looked up again. */
    public const ADDRESS_RELOOKUP_METERS = 100;

    /** @use HasFactory<LocationFactory> */
    use BlockedDuringMaintenance, HasFactory, LogsActivity;

    /**
     * A stationary location carries no Traccar link: the form hides the mobile
     * fields once the type is Stationary, so they would otherwise stay set on
     * the row after a Mobile -> Stationary edit and keep a (now stationary)
     * location tied to a device. Enforced here rather than on one Filament page
     * so every write path keeps the invariant; the poller also filters on type
     * for rows that predate it.
     */
    protected static function booted(): void
    {
        static::saving(function (Location $location): void {
            if ($location->type === LocationType::Stationary) {
                $location->forceFill([
                    'mobile_source' => null,
                    'traccar_server_id' => null,
                    'traccar_device_id' => null,
                    'traccar_device_name' => null,
                    'app_token_id' => null,
                    'mobile_update_interval_minutes' => null,
                    'mobile_position_updated_at' => null,
                    'mobile_position_fixed_at' => null,
                    'traccar_device_missing_at' => null,
                    'address_geocoded_lat' => null,
                    'address_geocoded_lng' => null,
                ]);
            }

            // A different device is a fresh start: whatever was noticed about the old one no longer applies.
            if ($location->isDirty('traccar_device_id') && ! $location->isDirty('traccar_device_missing_at')) {
                $location->forceFill(['traccar_device_missing_at' => null]);
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        // logAll() preserves original behavior for every real field (including
        // lat/lng, whether a stationary pin is repositioned or a mobile fix is
        // manually corrected). Only mobile_position_updated_at, mobile_position_fixed_at and
        // traccar_device_missing_at are excluded: they're bookkeeping timestamps that tick every 5-30 minutes forever purely
        // from Traccar polling, not user-meaningful data, and would otherwise
        // drown out everything else about this model in noise.
        return LogOptions::defaults()
            ->logAll()
            ->logExcept(['mobile_position_updated_at', 'mobile_position_fixed_at', 'traccar_device_missing_at', 'address_geocoded_lat', 'address_geocoded_lng'])
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
            'mobile_position_fixed_at' => 'datetime',
            'traccar_device_missing_at' => 'datetime',
            'address_geocoded_lat' => 'decimal:7',
            'address_geocoded_lng' => 'decimal:7',
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

    public function appToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'app_token_id');
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

    /**
     * When this location's position was last established: a mobile location's is
     * the time Traccar says its device was last located (null until the first fix
     * arrives), a stationary location's is when it was created.
     */
    /**
     * Whether a mobile location's address should be looked up (again): it has a position
     * and either was never resolved, or has moved ADDRESS_RELOOKUP_METERS or more from the
     * position its address was resolved for.
     */
    public function needsAddressLookup(): bool
    {
        if ($this->type !== LocationType::Mobile || ! $this->hasFix()) {
            return false;
        }

        if ($this->address_geocoded_lat === null || $this->address_geocoded_lng === null) {
            return true;
        }

        return GreatCircle::meters((float) $this->address_geocoded_lat, (float) $this->address_geocoded_lng, (float) $this->lat, (float) $this->lng) >= self::ADDRESS_RELOOKUP_METERS;
    }

    /**
     * Whether this mobile location has lost the device that keeps it current: a Traccar
     * device deleted from the server, or an app location whose phone signed out, was
     * revoked or stopped sharing. Its last position is kept either way.
     */
    public function hasMissingDevice(): bool
    {
        if ($this->type !== LocationType::Mobile) {
            return false;
        }

        return $this->mobile_source === MobileSource::App
            ? $this->app_token_id === null
            : $this->traccar_device_missing_at !== null;
    }

    public function positionLocatedAt(): ?CarbonInterface
    {
        return $this->type === LocationType::Mobile ? $this->mobile_position_fixed_at : $this->created_at;
    }
}
