<?php

namespace App\Models;

use App\Enums\InviteCodeStatus;
use Database\Factories\InviteCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['code', 'note', 'max_uses', 'expires_at', 'email'])]
class InviteCode extends Model
{
    /** Upper-case letters and digits without 0, O, 1, I and L, which are easy to misread. */
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const GENERATED_LENGTH = 8;

    /** @use HasFactory<InviteCodeFactory> */
    use HasFactory, LogsActivity;

    /**
     * Upper-case a typed code and drop everything outside A-Z and 0-9, so " ab-cd 12 " matches ABCD12.
     */
    public static function normalise(?string $code): string
    {
        return preg_replace('/[^A-Z0-9]/', '', Str::upper((string) $code));
    }

    public static function generateCode(): string
    {
        $code = '';

        for ($i = 0; $i < self::GENERATED_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    /**
     * A generated code is shown in two groups of four (ABCD-EFGH); a custom code as it is.
     */
    public function displayCode(): string
    {
        if (strlen($this->code) === self::GENERATED_LENGTH) {
            return substr($this->code, 0, 4).'-'.substr($this->code, 4);
        }

        return $this->code;
    }

    /**
     * Revoked wins over expired, which wins over used up.
     */
    public function status(): InviteCodeStatus
    {
        if ($this->revoked_at !== null) {
            return InviteCodeStatus::Revoked;
        }

        if ($this->expires_at !== null && $this->expires_at->lte(now())) {
            return InviteCodeStatus::Expired;
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return InviteCodeStatus::UsedUp;
        }

        return InviteCodeStatus::Active;
    }

    public function scopeRevoked(Builder $query): Builder
    {
        return $query->whereNotNull('revoked_at');
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    public function scopeUsedUp(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereNotNull('max_uses')
            ->whereColumn('used_count', '>=', 'max_uses');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn (Builder $query) => $query->whereNull('max_uses')->orWhereColumn('used_count', '<', 'max_uses'));
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['note', 'max_uses', 'expires_at', 'email', 'revoked_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
