<?php

namespace App\Models;

use Database\Factories\ActiveWwaAreaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One zone of an active event, with its own status, times and optional polygon. This, not the
 * event row's ugc_codes/polygon, is what says where an event is in effect. `polygon` is a native
 * PostGIS geometry column, deliberately not fillable or cast: it is written and read with raw SQL
 * (see ApplySegmentToCoverage and GetActiveAlertsForLocation).
 */
#[Fillable(['active_wwa_id', 'ugc_code', 'status', 'effective', 'expires', 'latest_segment_id', 'applied_at'])]
class ActiveWwaArea extends Model
{
    /** @use HasFactory<ActiveWwaAreaFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'effective' => 'datetime',
            'expires' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function activeWwa(): BelongsTo
    {
        return $this->belongsTo(ActiveWwa::class);
    }

    public function latestSegment(): BelongsTo
    {
        return $this->belongsTo(IngestedProductSegment::class, 'latest_segment_id');
    }
}
