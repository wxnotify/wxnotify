<?php

namespace App\Models;

use App\Support\VtecEvent;
use Database\Factories\IngestedProductSegmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One event of one section of a VTEC bulletin: a section with two VTEC lines gives two segments
 * that share the section's zones and polygon. Built by App\Actions\Pipeline\BuildBulletinSegments
 * and matched by MatchWeatherAlertRules, which is how a rule sees an event that is not the
 * bulletin's first section or first VTEC line.
 */
#[Fillable([
    'position', 'vtec_office', 'vtec_phenomenon', 'vtec_significance', 'vtec_event_number', 'vtec_year',
    'vtec_action', 'vtec_begin', 'vtec_end', 'product_id', 'ugc_codes', 'polygon',
])]
class IngestedProductSegment extends Model
{
    /** @use HasFactory<IngestedProductSegmentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'ugc_codes' => 'array',
            'polygon' => 'array',
            'vtec_begin' => 'datetime',
            'vtec_end' => 'datetime',
        ];
    }

    public function ingestedProduct(): BelongsTo
    {
        return $this->belongsTo(IngestedProduct::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function deliveryLogs(): BelongsToMany
    {
        return $this->belongsToMany(DeliveryLog::class, 'delivery_log_segment');
    }

    /**
     * What to call this event: its catalog product's name, else the NWS name its VTEC codes give
     * (the catalog is a curated subset), else a generic label.
     */
    public function eventName(): string
    {
        return $this->product?->name
            ?? VtecEvent::name((string) $this->vtec_phenomenon, (string) $this->vtec_significance)
            ?? 'Weather alert';
    }
}
