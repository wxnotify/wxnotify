<?php

namespace App\Models;

use Database\Factories\ActiveWwaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id', 'vtec_office', 'vtec_phenomenon', 'vtec_significance', 'vtec_event_number', 'vtec_year',
    'status', 'is_test', 'ugc_codes', 'effective', 'expires', 'headline',
    'motion_direction_degrees', 'motion_speed_kt', 'motion_reference_lat', 'motion_reference_lon', 'motion_observed_at',
    'source', 'latest_ingested_product_id',
])]
class ActiveWwa extends Model
{
    /** @use HasFactory<ActiveWwaFactory> */
    use HasFactory;

    /**
     * `polygon` is deliberately not fillable or cast here -- it's a native
     * PostGIS geometry column, set/read via raw SQL (see SyncActiveWwa and
     * MatchWeatherAlertRules), never materialized into a PHP value.
     */
    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'ugc_codes' => 'array',
            'effective' => 'datetime',
            'expires' => 'datetime',
            'motion_observed_at' => 'datetime',
            'motion_reference_lat' => 'float',
            'motion_reference_lon' => 'float',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function latestIngestedProduct(): BelongsTo
    {
        return $this->belongsTo(IngestedProduct::class, 'latest_ingested_product_id');
    }

    /**
     * Scopes to the row(s) sharing an IngestedProduct's VTEC composite key
     * (office, phenomenon, significance, event number, year) -- the same
     * correlation SyncActiveWwa uses to upsert the current-state row for an
     * event, and that callers use to look it back up.
     */
    public function scopeForProduct(Builder $query, IngestedProduct $product): Builder
    {
        return $query->where(static::vtecKeyFor($product))->where('is_test', (bool) $product->is_test);
    }

    /**
     * The VTEC composite key of an IngestedProduct or an ActiveWwa row (both carry the five vtec_* columns).
     *
     * @return array{vtec_office: ?string, vtec_phenomenon: ?string, vtec_significance: ?string, vtec_event_number: ?string, vtec_year: ?int}
     */
    public static function vtecKeyFor(IngestedProduct|self $product): array
    {
        return [
            'vtec_office' => $product->vtec_office,
            'vtec_phenomenon' => $product->vtec_phenomenon,
            'vtec_significance' => $product->vtec_significance,
            'vtec_event_number' => $product->vtec_event_number,
            'vtec_year' => $product->vtec_year,
        ];
    }
}
