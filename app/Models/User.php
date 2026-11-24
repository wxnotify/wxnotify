<?php

namespace App\Models;

use App\Enums\AlertCategory;
use App\Enums\Tier;
use App\Enums\UnitSystem;
use App\Models\ChannelType as ChannelTypeModel;
use App\Settings\TierThresholdSettings;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Happenv\FilamentPasskeys\Concerns\InteractsWithPasskeysAuthentication;
use Happenv\FilamentPasskeys\Contracts\HasPasskeysAuthentication;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Marcelodelgado\Announcements\Traits\HasAnnouncements;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['name', 'email', 'password', 'is_test_account', 'unit_system', 'timezone'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasPasskeysAuthentication, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasAnnouncements, HasApiTokens, HasFactory, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, InteractsWithPasskeysAuthentication, LogsActivity, Notifiable;

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

    public function isPendingDeletion(): bool
    {
        return $this->deletion_requested_at !== null;
    }

    /**
     * When the daily purge will remove this account, or null when no deletion is pending.
     */
    public function deletionDate(): ?CarbonImmutable
    {
        return $this->deletion_requested_at?->toImmutable()->addDays(config('wxnotify.account_deletion_days'));
    }

    /**
     * Staff and owners are demoted first, so nobody deletes their way out of /admin by accident.
     */
    public function canRequestDeletion(): bool
    {
        return ! $this->is_staff && ! $this->is_owner;
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

    public function inviteCode(): BelongsTo
    {
        return $this->belongsTo(InviteCode::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /**
     * Maximum number of locations other than phone-app ones (saved places and Traccar devices)
     * this user's tier allows, or null for unlimited (always, when self-hosted).
     */
    public function locationLimit(): ?int
    {
        if (! static::hasTiers()) {
            return null;
        }

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

        return $limit === null || $this->locations()->notPhoneApp()->count() < $limit;
    }

    /**
     * Maximum number of alert rules, paused ones included, this user's tier allows, or null
     * for unlimited (always, when self-hosted).
     */
    public function alertRuleLimit(): ?int
    {
        if (! static::hasTiers()) {
            return null;
        }

        $limits = app(TierThresholdSettings::class);

        return match ($this->tier()) {
            Tier::Free => $limits->free_alerts,
            Tier::Plus => $limits->plus_alerts,
            Tier::Pro => $limits->pro_alerts,
        };
    }

    /**
     * False only when there is a limit, so a refusal built from alertRuleLimit() always has a number.
     */
    public function canCreateAlertRule(): bool
    {
        $limit = $this->alertRuleLimit();

        return $limit === null || $this->alertRules()->count() < $limit;
    }

    /**
     * Plus and Pro may have any number of Outlook Reports (within the alert limit);
     * Free may have one.
     */
    public function canCreateOutlookReport(): bool
    {
        if ($this->tier() !== Tier::Free) {
            return true;
        }

        return ! $this->alertRules()->where('category', AlertCategory::OutlookReport)->exists();
    }

    /**
     * Maximum number of phone-app locations this user's tier allows, or null for unlimited
     * (always, when self-hosted).
     */
    public function phoneLocationLimit(): ?int
    {
        if (! static::hasTiers()) {
            return null;
        }

        $limits = app(TierThresholdSettings::class);

        return match ($this->tier()) {
            Tier::Free => $limits->free_phone_locations,
            Tier::Plus => $limits->plus_phone_locations,
            Tier::Pro => null,
        };
    }

    public function canCreatePhoneLocation(): bool
    {
        $limit = $this->phoneLocationLimit();

        return $limit === null || $this->locations()->phoneApp()->count() < $limit;
    }

    public function notificationChannels(): HasMany
    {
        return $this->hasMany(NotificationChannel::class);
    }

    /**
     * Whether this subscriber's tier reaches the catalog row's `min_tier` for the type.
     * A type with no catalog row counts as free, so a missing row never cuts anyone off.
     */
    public function meetsChannelTier(string $channelTypeSlug): bool
    {
        $minimum = ChannelTypeModel::query()->where('slug', $channelTypeSlug)->first()?->min_tier;

        return $this->tier()->meets($minimum ?? Tier::Free);
    }

    public function canAddChannel(string $channelTypeSlug): bool
    {
        $registry = app(ChannelTypeRegistry::class);
        $type = $registry->find($channelTypeSlug);

        if (! $type || ! $this->meetsChannelTier($channelTypeSlug)) {
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
     * Maximum number of Traccar servers this user's tier allows: a concrete number for every
     * tier, or null for unlimited when self-hosted.
     */
    public function traccarServerLimit(): ?int
    {
        if (! static::hasTiers()) {
            return null;
        }

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
     * Maximum number of devices allowed per Traccar server this user's tier allows: a concrete
     * number for every tier, or null for unlimited when self-hosted.
     */
    public function traccarDeviceLimitPerServer(): ?int
    {
        if (! static::hasTiers()) {
            return null;
        }

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
            'deletion_requested_at' => 'datetime',
            'is_test_account' => 'boolean',
            'tier_override' => Tier::class,
            'unit_system' => UnitSystem::class,
        ];
    }

    /**
     * Whether plans apply at all. A self-hosted install has no tiers: everyone is Pro and
     * every limit is unlimited, while `tier_override` is kept for a later switch back to cloud.
     */
    public static function hasTiers(): bool
    {
        return config('wxnotify.mode') !== 'selfhost';
    }

    public function tier(): Tier
    {
        if (! static::hasTiers()) {
            return Tier::Pro;
        }

        return $this->tier_override ?? Tier::Free;
    }
}
