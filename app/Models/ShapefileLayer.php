<?php

namespace App\Models;

use Database\Factories\ShapefileLayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'name', 'source_url', 'last_seen_filename', 'last_seen_checksum', 'last_seen_effective_at', 'active_version_id'])]
class ShapefileLayer extends Model
{
    /** @use HasFactory<ShapefileLayerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'last_seen_effective_at' => 'date',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ShapefileLayerVersion::class);
    }

    public function recordOverrides(): HasMany
    {
        return $this->hasMany(ShapefileRecordOverride::class);
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(ShapefileLayerVersion::class, 'active_version_id');
    }
}
