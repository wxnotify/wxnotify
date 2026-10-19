<?php

namespace App\Models;

use App\Enums\AlertCategory;
use App\Enums\MatchingMode;
use App\Enums\MessageBodyStyle;
use App\Enums\ThresholdDirection;
use App\Enums\ThresholdTimeframe;
use App\Enums\ThresholdVariable;
use App\Enums\Tier;
use App\Enums\UnitSystem;
use App\Models\Concerns\BlockedDuringMaintenance;
use App\Support\ProductMatchSettings;
use App\Support\SpcWpcRiskLevels;
use App\Support\Timezones;
use App\Support\Units;
use Database\Factories\AlertRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
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
    'alert_template_id',
])]
class AlertRule extends Model
{
    /** @use HasFactory<AlertRuleFactory> */
    use BlockedDuringMaintenance, HasFactory, LogsActivity;

    /**
     * DispatchScheduledAlerts delivers each scheduled rule at most once per
     * calendar day, tracked by last_scheduled_run_on. Changing delivery_time to
     * a moment that is still ahead of "now" in the rule's own timezone must let
     * the rule run again today at the new time, or a subscriber who reschedules
     * after the first delivery silently gets nothing until tomorrow.
     *
     * A new time that has already passed today is deliberately left alone:
     * clearing the stamp then would re-send immediately and retroactively.
     */
    protected static function booted(): void
    {
        static::updating(function (AlertRule $rule): void {
            if ($rule->last_scheduled_run_on === null
                || $rule->isDirty('last_scheduled_run_on')
                || ! $rule->deliveryTimeChanged()) {
                return;
            }

            $timezone = Timezones::forRule($rule);
            $now = Carbon::now($timezone);

            if ($now->lt($now->copy()->setTimeFromTimeString($rule->delivery_time))) {
                $rule->last_scheduled_run_on = null;
            }
        });
    }

    /**
     * Compares clock times, not strings: the column reads back as "08:40:00"
     * while a form submits "08:40", and that is not a change.
     */
    private function deliveryTimeChanged(): bool
    {
        if (! $this->isDirty('delivery_time') || blank($this->delivery_time)) {
            return false;
        }

        $original = $this->getOriginal('delivery_time');

        return blank($original)
            || Carbon::parse($original)->format('H:i') !== Carbon::parse($this->delivery_time)->format('H:i');
    }

    /**
     * The rule's own matching_mode, wfo and county_zone_codes are legacy columns: product-based rules keep
     * their matching per product (see settingsFor()), and SaveAlertRule empties these on the first save
     * after the per-product settings arrived, which would read as "matching cleared" in the history.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logExcept(['matching_mode', 'wfo', 'county_zone_codes'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
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

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class)->orderBy('locations.id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot(['matching_mode', 'wfo', 'county_zone_codes', 'risk_levels']);
    }

    /**
     * Each product's own saved locations (the pivot's product_id says which product).
     *
     * @return BelongsToMany<Location, $this>
     */
    public function productLocations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'alert_rule_product_location')->withPivot('product_id')->orderBy('locations.id');
    }

    /**
     * How this rule matches one of its products: the product's own settings, else (a pivot row with no
     * mode, i.e. a rule not yet saved through the per-product table) the rule's own columns and locations.
     */
    public function settingsFor(int $productId): ?ProductMatchSettings
    {
        $product = $this->products->firstWhere('id', $productId);

        if ($product === null) {
            return null;
        }

        if ($product->pivot->matching_mode === null) {
            return new ProductMatchSettings(
                $this->matching_mode,
                $this->wfo,
                filled($this->county_zone_codes) ? explode(',', $this->county_zone_codes) : [],
                $this->locations,
                true,
            );
        }

        return new ProductMatchSettings(
            MatchingMode::from($product->pivot->matching_mode),
            $product->pivot->wfo,
            filled($product->pivot->county_zone_codes) ? explode(',', $product->pivot->county_zone_codes) : [],
            $this->productLocations->filter(fn (Location $location): bool => (int) $location->pivot->product_id === $productId)->values(),
            false,
        );
    }

    /**
     * The risk levels this rule selected for one of its products, in that product's option order and limited
     * to the levels the product offers (an empty list means every level). They are the product's own
     * (`alert_rule_product.risk_levels`), else, for a pivot row that holds none (a rule from before
     * per-product levels, or attached the old way), the rule's own flat `risk_levels`.
     *
     * @return list<string>
     */
    public function riskLevelsFor(int $productId): array
    {
        $product = $this->products->firstWhere('id', $productId);

        if ($product === null) {
            return [];
        }

        $own = $product->pivot->risk_levels;
        $selected = $own === null ? ($this->risk_levels ?? []) : (is_string($own) ? json_decode($own, true) : $own);

        if (! is_array($selected)) {
            return [];
        }

        return SpcWpcRiskLevels::forProduct($product, $selected);
    }

    public function notificationChannels(): BelongsToMany
    {
        return $this->belongsToMany(NotificationChannel::class);
    }

    public function alertTemplate(): BelongsTo
    {
        return $this->belongsTo(AlertTemplate::class);
    }

    public function effectiveAlertTemplate(): ?AlertTemplate
    {
        if ($this->user->tier() === Tier::Free) {
            return null;
        }

        return $this->alertTemplate?->category === $this->category ? $this->alertTemplate : null;
    }

    public function effectiveCustomSubjectTemplate(): ?string
    {
        return $this->user->tier() !== Tier::Free ? $this->custom_subject_template : null;
    }

    public function effectiveMessageBodyStyle(): ?MessageBodyStyle
    {
        return $this->user->tier() !== Tier::Free ? $this->message_body_style : null;
    }

    public function displayName(?UnitSystem $units = null): string
    {
        if (filled($this->name)) {
            return $this->name;
        }

        return match ($this->category) {
            AlertCategory::WeatherAlerts, AlertCategory::SpcWpc => $this->products->pluck('name')->join(', ') ?: $this->category->label(),
            AlertCategory::DailySummary => "Daily summary for {$this->location?->label}",
            AlertCategory::Threshold => "{$this->variable?->label()} {$this->direction?->label()} {$this->variable?->format((float) $this->threshold_value, $units ?? Units::forRule($this)->system)}",
            AlertCategory::Lightning => 'Lightning within '.(new Units($units ?? Units::forRule($this)->system))->distance((float) $this->radius_miles, 0)." of {$this->location?->label}",
        };
    }
}
