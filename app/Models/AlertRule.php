<?php

namespace App\Models;

use App\Enums\AlertCategory;
use App\Enums\MatchingMode;
use App\Enums\ThresholdDirection;
use App\Enums\ThresholdTimeframe;
use App\Enums\ThresholdVariable;
use Database\Factories\AlertRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'user_id',
    'category',
    'name',
    'matching_mode',
    'location_id',
    'wfo',
    'county_zone_codes',
    'risk_levels',
    'variable',
    'direction',
    'threshold_value',
    'timeframe',
    'delivery_time',
    'radius_miles',
    'quiet_hours_start',
    'quiet_hours_end',
    'is_active',
])]
class AlertRule extends Model
{
    /** @use HasFactory<AlertRuleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'category' => AlertCategory::class,
            'matching_mode' => MatchingMode::class,
            'risk_levels' => 'array',
            'variable' => ThresholdVariable::class,
            'direction' => ThresholdDirection::class,
            'threshold_value' => 'decimal:1',
            'timeframe' => ThresholdTimeframe::class,
            'radius_miles' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    public function notificationChannels(): BelongsToMany
    {
        return $this->belongsToMany(NotificationChannel::class);
    }

    public function displayName(): string
    {
        if (filled($this->name)) {
            return $this->name;
        }

        return match ($this->category) {
            AlertCategory::WeatherAlerts, AlertCategory::SpcWpc => $this->products->pluck('name')->join(', ') ?: $this->category->label(),
            AlertCategory::DailySummary => "Daily summary for {$this->location?->label}",
            AlertCategory::Threshold => "{$this->variable?->label()} {$this->direction?->label()} {$this->threshold_value}{$this->variable?->unit()}",
            AlertCategory::Lightning => "Lightning within {$this->radius_miles} mi of {$this->location?->label}",
            AlertCategory::ForecastDiscussion => "Forecast discussion — {$this->wfo}",
        };
    }
}
