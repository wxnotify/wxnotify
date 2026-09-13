<?php

namespace App\Models;

use Database\Factories\DidYouKnowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['body', 'is_active', 'sort_order'])]
class DidYouKnow extends Model
{
    /** @use HasFactory<DidYouKnowFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Deliberately no ->orderBy('sort_order') here: this scope's only
     * consumer (the dashboard sidebar's render hook) chains ->inRandomOrder()
     * onto it, and an ORDER BY already applied by the scope takes priority
     * over one appended afterwards — with every row's sort_order distinct,
     * that made the random order a no-op tiebreaker that never won,
     * confirmed directly: repeated calls returned the same row every time.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
