<?php

namespace App\Models;

use Database\Factories\ShapefileRecordOverrideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shapefile_layer_id', 'record_key', 'field', 'value', 'created_by'])]
class ShapefileRecordOverride extends Model
{
    /** @use HasFactory<ShapefileRecordOverrideFactory> */
    use HasFactory;

    public function shapefileLayer(): BelongsTo
    {
        return $this->belongsTo(ShapefileLayer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
