<?php

namespace App\Observers;

use App\Actions\Visibility\MatchActivityAlertRules;
use App\Enums\ActivityAlertType;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Arr;
use Spatie\Activitylog\Models\Activity;

class ActivityLogObserver
{
    /**
     * @var array<class-string, array<string, ActivityAlertType>>
     */
    private const TYPE_MAP = [
        AlertRule::class => [
            'created' => ActivityAlertType::AlertCreated,
            'updated' => ActivityAlertType::AlertUpdated,
            'deleted' => ActivityAlertType::AlertDeleted,
        ],
        Location::class => [
            'created' => ActivityAlertType::LocationCreated,
            'updated' => ActivityAlertType::LocationUpdated,
            'deleted' => ActivityAlertType::LocationDeleted,
        ],
        NotificationChannel::class => [
            'created' => ActivityAlertType::ContactCreated,
            'updated' => ActivityAlertType::ContactUpdated,
            'deleted' => ActivityAlertType::ContactDeleted,
        ],
    ];

    public function created(Activity $activity): void
    {
        $type = self::TYPE_MAP[$activity->subject_type][$activity->event] ?? null;

        if ($type === null) {
            return;
        }

        // subject() is a live morphTo lookup — fine for created/updated, but
        // returns null after a hard delete, so 'deleted' has to read the
        // owning user id out of Spatie's own before-deletion snapshot
        // instead. Note: the installed spatie/laravel-activitylog (5.1.0)
        // writes change data to a separate `attribute_changes` column, not
        // into `properties` — Activity::getProperty() only reads
        // `properties`, so it can't be used here. A deleted event's
        // attribute_changes is ['old' => <full attributesToBeLogged()
        // snapshot>], no 'attributes' key (confirmed by inspecting a real
        // row's raw attribute_changes column after triggering a delete).
        $userId = $activity->event === 'deleted'
            ? Arr::get($activity->attribute_changes?->toArray() ?? [], 'old.user_id')
            : $activity->subject?->user_id;

        if ($userId === null) {
            return;
        }

        $user = User::find($userId);

        if ($user === null) {
            return;
        }

        app(MatchActivityAlertRules::class)($user, $type);
    }
}
