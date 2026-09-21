<?php

namespace App\Models;

use App\Enums\AlertCategory;
use App\Models\Concerns\BlockedDuringMaintenance;
use Database\Factories\AlertTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'name', 'category', 'subject_template', 'sections'])]
class AlertTemplate extends Model
{
    /** @use HasFactory<AlertTemplateFactory> */
    use BlockedDuringMaintenance, HasFactory;

    protected function casts(): array
    {
        return [
            'category' => AlertCategory::class,
            'sections' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function alertRules(): HasMany
    {
        return $this->hasMany(AlertRule::class);
    }
}
