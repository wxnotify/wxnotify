<?php

namespace App\Models;

use App\Enums\ProductFamily;
use App\Enums\ProductStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    protected function casts(): array
    {
        return [
            'family' => ProductFamily::class,
            'has_polygon' => 'boolean',
            'risk_level_options' => 'array',
            'status' => ProductStatus::class,
        ];
    }

    public function alertRules(): BelongsToMany
    {
        return $this->belongsToMany(AlertRule::class);
    }
}
