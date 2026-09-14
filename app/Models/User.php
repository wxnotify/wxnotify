<?php

namespace App\Models;

use App\Enums\Tier;
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
use Marcelodelgado\Announcements\Traits\HasAnnouncements;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasAnnouncements, HasFactory, InteractsWithAppAuthentication, LogsActivity, Notifiable;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['is_staff', 'tier_override', 'tier_override_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
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
        return match ($this->tier()) {
            Tier::Free => 1,
            Tier::Plus => 5,
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

        return ! $this->notificationChannels
            ->contains(fn (NotificationChannel $channel) => $registry->find($channel->channel_type)?->countsTowardFreeSlot() ?? false);
    }

    public function traccarServers(): HasMany
    {
        return $this->hasMany(TraccarServer::class);
    }

    /**
     * Maximum number of Traccar servers this user's tier allows, or null for unlimited.
     */
    public function traccarServerLimit(): ?int
    {
        return match ($this->tier()) {
            Tier::Free => 0,
            Tier::Plus => 1,
            Tier::Pro => 3,
        };
    }

    public function canCreateTraccarServer(): bool
    {
        $limit = $this->traccarServerLimit();

        return $limit === null || $this->traccarServers()->count() < $limit;
    }

    /**
     * Maximum number of devices allowed per Traccar server this user's tier allows, or null for unlimited.
     */
    public function traccarDeviceLimitPerServer(): ?int
    {
        return match ($this->tier()) {
            Tier::Free => 0,
            Tier::Plus => 5,
            Tier::Pro => 10,
        };
    }

    public function canAddTraccarDevice(int $traccarServerId): bool
    {
        $limit = $this->traccarDeviceLimitPerServer();

        return $limit === null || $this->locations()->where('traccar_server_id', $traccarServerId)->count() < $limit;
    }

    public function alertRules(): HasMany
    {
        return $this->hasMany(AlertRule::class);
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
            'tier_override' => Tier::class,
        ];
    }

    public function tier(): Tier
    {
        return $this->tier_override ?? Tier::Free;
    }
}
