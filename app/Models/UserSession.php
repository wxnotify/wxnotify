<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of Laravel's own `sessions` table (SESSION_DRIVER=database): one signed-in web browser.
 * Read and delete only; the framework writes these rows.
 */
class UserSession extends Model
{
    /** @use HasFactory<UserSessionFactory> */
    use HasFactory;

    protected $table = 'sessions';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }

    public function isCurrent(): bool
    {
        return $this->getKey() === session()->getId();
    }

    public function lastActiveAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp($this->last_activity);
    }
}
