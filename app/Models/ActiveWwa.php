<?php

namespace App\Models;

use App\Support\VtecEvent;
use Database\Factories\ActiveWwaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /**
     * What to call this event: its catalog product's name, else the NWS name its VTEC codes give
     * (the catalog is a curated subset, so Storm Surge, Rip Current, Gale and others have no row),
     * else a generic label.
     */
    public function eventName(): string
    {
        return $this->product?->name
            ?? VtecEvent::name((string) $this->vtec_phenomenon, (string) $this->vtec_significance)
            ?? 'Weather alert';
    }

    /**
     * The family (warning, watch, advisory, statement...) the dashboard colours and sorts by: the
     * catalog product's, else the one its VTEC significance letter gives.
     */
    public function eventFamily(): ?string
    {
        return $this->product?->family?->value ?? VtecEvent::family((string) $this->vtec_significance);
    }

    public function latestIngestedProduct(): BelongsTo
    {
        return $this->belongsTo(IngestedProduct::class, 'latest_ingested_product_id');
    }

    /**
     * The zones this event is (or was) in effect in. Coverage is read from here, not from this row's
     * own ugc_codes/polygon, which only describe the latest bulletin.
     */
    public function areas(): HasMany
    {
        return $this->hasMany(ActiveWwaArea::class);
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
     * Scopes to the event a segment names (its VTEC key), within the is_test flag of the segment's
     * bulletin, exactly as scopeForProduct() does for a bulletin: a synthetic event never resolves
     * for a real segment or the reverse.
     */
    public function scopeForSegment(Builder $query, IngestedProductSegment $segment): Builder
    {
        return $query->where(static::vtecKeyFor($segment))->where('is_test', (bool) $segment->ingestedProduct->is_test);
    }

    /**
     * The VTEC composite key of an IngestedProduct, an IngestedProductSegment or an ActiveWwa row (all carry the five vtec_* columns).
     *
     * @return array{vtec_office: ?string, vtec_phenomenon: ?string, vtec_significance: ?string, vtec_event_number: ?string, vtec_year: ?int}
     */
    public static function vtecKeyFor(IngestedProduct|IngestedProductSegment|self $product): array
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
