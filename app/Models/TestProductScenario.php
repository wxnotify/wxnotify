<?php

namespace App\Models;

use Database\Factories\TestProductScenarioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'product_id', 'continues_scenario_id', 'input'])]
class TestProductScenario extends Model
{
    /** @use HasFactory<TestProductScenarioFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'input' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function continuesScenario(): BelongsTo
    {
        return $this->belongsTo(self::class, 'continues_scenario_id');
    }

    public function ingestedProducts(): HasMany
    {
        return $this->hasMany(IngestedProduct::class);
    }
}
