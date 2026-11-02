<?php

namespace App\Models;

use Database\Factories\OutlookReportRunFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivered Severe Weather Outlook report: its payload and the stored copies of the SPC maps.
 */
#[UseFactory(OutlookReportRunFactory::class)]
class OutlookReportRun extends Model
{
    /** @use HasFactory<OutlookReportRunFactory> */
    use HasFactory;

    public const MAP_DAYS = 30;

    protected $fillable = ['user_id', 'alert_rule_id', 'batch_key', 'payload', 'maps'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'maps' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<AlertRule, $this>
     */
    public function alertRule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class);
    }

    public function mapsExpired(): bool
    {
        return $this->created_at->lte(now()->subDays(self::MAP_DAYS));
    }

    /**
     * @return array{path: string, mime: string}|null
     */
    public function mapPath(string $key): ?array
    {
        $map = $this->maps[$key] ?? null;

        return is_array($map) && isset($map['path'], $map['mime']) ? $map : null;
    }
}
