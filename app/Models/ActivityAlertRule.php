<?php

namespace App\Models;

use App\Enums\ActivityAlertScope;
use Database\Factories\ActivityAlertRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'scope', 'activity_types', 'apprise_target', 'is_active'])]
class ActivityAlertRule extends Model
{
    /** @use HasFactory<ActivityAlertRuleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'scope' => ActivityAlertScope::class,
            'activity_types' => 'array',
            'apprise_target' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
