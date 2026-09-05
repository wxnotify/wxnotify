<?php

namespace App\Actions\Visibility;

use App\Enums\ActivityAlertScope;
use App\Enums\ActivityAlertType;
use App\Jobs\SendActivityAlert;
use App\Models\ActivityAlertRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class MatchActivityAlertRules
{
    public function __invoke(User $user, ActivityAlertType $type): void
    {
        $occurredAt = now();

        ActivityAlertRule::query()
            ->where('is_active', true)
            ->whereJsonContains('activity_types', $type->value)
            ->where(function (Builder $query) use ($user) {
                $query->where('scope', ActivityAlertScope::AllSubscribers)
                    ->orWhere(function (Builder $query) use ($user) {
                        $query->where('scope', ActivityAlertScope::SpecificUsers)
                            ->whereHas('users', fn (Builder $q) => $q->whereKey($user->getKey()));
                    });
            })
            ->get()
            ->each(fn (ActivityAlertRule $rule) => SendActivityAlert::dispatch(
                $rule->id,
                $type,
                $user->id,
                $occurredAt,
            ));
    }
}
