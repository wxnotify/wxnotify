<?php

namespace App\Models;

use App\Enums\ShapefileVersionStatus;
use Database\Factories\ShapefileLayerVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shapefile_layer_id', 'filename', 'checksum', 'source_url', 'effective_at', 'status', 'record_count', 'diff_summary', 'activated_at'])]
class ShapefileLayerVersion extends Model
{
    /** @use HasFactory<ShapefileLayerVersionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'effective_at' => 'date',
            'status' => ShapefileVersionStatus::class,
            'diff_summary' => 'array',
            'activated_at' => 'datetime',
        ];
    }

    public function shapefileLayer(): BelongsTo
    {
        return $this->belongsTo(ShapefileLayer::class);
    }
}
