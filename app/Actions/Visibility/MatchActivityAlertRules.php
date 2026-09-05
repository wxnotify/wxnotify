<?php

namespace App\Actions\Visibility;

use App\Enums\ActivityAlertScope;
use App\Enums\ActivityAlertType;
use App\Jobs\SendActivityAlert;
use App\Models\ActivityAlertRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class MatchActivityAlertRules
{
    public function __invoke(User $user, ActivityAlertType $type): void
    {
        $occurredAt = now();

        try {
            $rules = ActivityAlertRule::query()
                ->where('is_active', true)
                ->whereJsonContains('activity_types', $type->value)
                ->where(function (Builder $query) use ($user) {
                    $query->where('scope', ActivityAlertScope::AllSubscribers)
                        ->orWhere(function (Builder $query) use ($user) {
                            $query->where('scope', ActivityAlertScope::SpecificUsers)
                                ->whereHas('users', fn (Builder $q) => $q->whereKey($user->getKey()));
                        });
                })
                ->get();
        } catch (Throwable $e) {
            logger()->error('Failed to query matching ActivityAlertRules', [
                'type' => $type->value,
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        $rules->each(function (ActivityAlertRule $rule) use ($type, $user, $occurredAt) {
            try {
                SendActivityAlert::dispatch($rule->id, $type, $user->id, $occurredAt);
            } catch (Throwable $e) {
                logger()->error('Failed to dispatch SendActivityAlert', [
                    'rule_id' => $rule->id,
                    'type' => $type->value,
                    'user_id' => $user->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        });
    }
}
