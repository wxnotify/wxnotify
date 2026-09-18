<?php

namespace App\Models;

use App\Enums\ProductCategory;
use App\Enums\ProductFamily;
use App\Enums\ProductStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['code', 'name', 'family', 'category', 'has_polygon', 'has_vtec', 'has_ugc', 'risk_level_options', 'damage_tag_options', 'status', 'sort_order', 'description', 'example', 'wea_warning'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'family' => ProductFamily::class,
            'category' => ProductCategory::class,
            'has_polygon' => 'boolean',
            'has_vtec' => 'boolean',
            'has_ugc' => 'boolean',
            'risk_level_options' => 'array',
            'damage_tag_options' => 'array',
            'status' => ProductStatus::class,
            'wea_warning' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'family', 'category', 'status', 'wea_warning'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function alertRules(): BelongsToMany
    {
        return $this->belongsToMany(AlertRule::class);
    }
}
