<?php

namespace App\Models;

use App\Enums\Tier;
use App\Enums\UnitSystem;
use App\Settings\TierThresholdSettings;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Marcelodelgado\Announcements\Traits\HasAnnouncements;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['name', 'email', 'password', 'is_test_account', 'unit_system', 'timezone'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasAnnouncements, HasApiTokens, HasFactory, InteractsWithAppAuthentication, LogsActivity, Notifiable;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['is_staff', 'is_owner', 'tier_override', 'tier_override_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->is_owner && ! $user->is_staff) {
                $user->is_staff = true;
            }
        });
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->is_staff,
            default => true,
        };
    }

    public function canBeImpersonated(): bool
    {
        return ! $this->is_staff;
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /**
     * Maximum number of locations this user's tier allows, or null for unlimited.
     */
    public function locationLimit(): ?int
    {
        $limits = app(TierThresholdSettings::class);

        return match ($this->tier()) {
            Tier::Free => $limits->free_locations,
            Tier::Plus => $limits->plus_locations,
            Tier::Pro => null,
        };
    }

    public function canCreateLocation(): bool
    {
        $limit = $this->locationLimit();

        return $limit === null || $this->locations()->count() < $limit;
    }

    public function notificationChannels(): HasMany
    {
        return $this->hasMany(NotificationChannel::class);
    }

    public function canAddChannel(string $channelTypeSlug): bool
    {
        $registry = app(ChannelTypeRegistry::class);
        $type = $registry->find($channelTypeSlug);

        if (! $type) {
            return false;
        }

        if ($this->tier() !== Tier::Free || ! $type->countsTowardFreeSlot()) {
            return true;
        }

        $used = $this->notificationChannels
            ->filter(fn (NotificationChannel $channel) => $registry->find($channel->channel_type)?->countsTowardFreeSlot() ?? false)
            ->count();

        return $used < app(TierThresholdSettings::class)->free_no_cost_channels;
    }

    public function traccarServers(): HasMany
    {
        return $this->hasMany(TraccarServer::class);
    }

    /**
     * Maximum number of Traccar servers this user's tier allows. Currently
     * always a concrete number for every tier -- ?int/null-for-unlimited is
     * kept for consistency with locationLimit()'s pattern in case a future
     * tier ever becomes unlimited here too.
     */
    public function traccarServerLimit(): ?int
    {
        $limits = app(TierThresholdSettings::class);

        return match ($this->tier()) {
            Tier::Free => $limits->free_traccar_servers,
            Tier::Plus => $limits->plus_traccar_servers,
            Tier::Pro => $limits->pro_traccar_servers,
        };
    }

    public function canCreateTraccarServer(): bool
    {
        $limit = $this->traccarServerLimit();

        return $limit === null || $this->traccarServers()->count() < $limit;
    }

    /**
     * Maximum number of devices allowed per Traccar server this user's tier
     * allows. Currently always a concrete number for every tier --
     * ?int/null-for-unlimited is kept for consistency with
     * locationLimit()'s pattern in case a future tier ever becomes
     * unlimited here too.
     */
    public function traccarDeviceLimitPerServer(): ?int
    {
        $limits = app(TierThresholdSettings::class);

        return match ($this->tier()) {
            Tier::Free => $limits->free_traccar_devices_per_server,
            Tier::Plus => $limits->plus_traccar_devices_per_server,
            Tier::Pro => $limits->pro_traccar_devices_per_server,
        };
    }

    public function canAddTraccarDevice(int $traccarServerId): bool
    {
        if (! $this->traccarServers()->whereKey($traccarServerId)->exists()) {
            return false;
        }

        $limit = $this->traccarDeviceLimitPerServer();

        return $limit === null || $this->locations()->where('traccar_server_id', $traccarServerId)->count() < $limit;
    }

    public function alertRules(): HasMany
    {
        return $this->hasMany(AlertRule::class);
    }

    public function alertTemplates(): HasMany
    {
        return $this->hasMany(AlertTemplate::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_staff' => 'boolean',
            'is_owner' => 'boolean',
            'is_test_account' => 'boolean',
            'tier_override' => Tier::class,
            'unit_system' => UnitSystem::class,
        ];
    }

    public function tier(): Tier
    {
        return $this->tier_override ?? Tier::Free;
    }
}
