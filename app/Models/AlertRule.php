<?php

namespace App\Models;

use App\Enums\AlertCategory;
use App\Enums\MatchingMode;
use App\Enums\MessageBodyStyle;
use App\Enums\ThresholdDirection;
use App\Enums\ThresholdTimeframe;
use App\Enums\ThresholdVariable;
use Database\Factories\AlertRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

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
    'last_scheduled_run_on',
    'radius_miles',
    'lightning_last_alerted_at',
    'quiet_hours_start',
    'quiet_hours_end',
    'is_active',
    'custom_subject_template',
    'message_body_style',
])]
class AlertRule extends Model
{
    /** @use HasFactory<AlertRuleFactory> */
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }

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
            'last_scheduled_run_on' => 'date',
            'radius_miles' => 'integer',
            'lightning_last_alerted_at' => 'datetime',
            'is_active' => 'boolean',
            'message_body_style' => MessageBodyStyle::class,
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
