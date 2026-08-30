<?php

namespace App\Models;

use App\Enums\ProductFamily;
use App\Enums\ProductStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['code', 'name', 'family', 'has_polygon', 'risk_level_options', 'status', 'sort_order'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'family' => ProductFamily::class,
            'has_polygon' => 'boolean',
            'risk_level_options' => 'array',
            'status' => ProductStatus::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'family', 'status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function alertRules(): BelongsToMany
    {
        return $this->belongsToMany(AlertRule::class);
    }
}
