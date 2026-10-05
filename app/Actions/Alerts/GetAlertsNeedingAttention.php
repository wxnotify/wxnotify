<?php

namespace App\Actions\Alerts;

use App\Enums\AlertCategory;
use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Things that stop (or may stop) a subscriber's alerts from doing their job,
 * as an ordered list of rows the My Alerts "Needs attention" widget renders.
 * Database only, scoped to the given user. Rows hold plain text (the view
 * escapes it) and never an error detail, destination, URL or token.
 *
 * Order: failing channels, unverified channels, active alerts with no channel,
 * mobile locations whose device is gone, mobile locations with no position,
 * inactive alerts -- each group by name. A channel appears once
 * (failing wins over unverified); an inactive alert appears only as inactive,
 * and the channel/position/verification checks look at active alerts only.
 */
class GetAlertsNeedingAttention
{
    private const WINDOW_DAYS = 7;

    private const SCHEDULED = [AlertCategory::DailySummary, AlertCategory::Threshold, AlertCategory::Lightning];

    /**
     * @return list<array{kind: string, severity: string, subject: string, detail: string, target: array{type: string, id: ?int}}>
     */
    public function __invoke(User $user): array
    {
        $failing = $this->failingChannels($user);

        return [
            ...$failing->values()->all(),
            ...$this->unverifiedChannels($user, $failing->keys()->all()),
            ...$this->activeAlertsWithoutChannel($user),
            ...$this->mobileLocationsWithMissingDevice($user),
            ...$this->mobileLocationsWithoutPosition($user),
            ...$this->inactiveAlerts($user),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>> keyed by channel id
     */
    private function failingChannels(User $user): Collection
    {
        $since = now()->subDays(self::WINDOW_DAYS);

        $attempts = fn () => DeliveryLog::query()
            ->where('user_id', $user->id)
            ->whereNotNull('notification_channel_id')
            ->whereIn('status', ['success', 'failure'])
            ->where('dispatched_at', '>=', $since);

        $latest = $attempts()
            ->select(['notification_channel_id', 'status', 'dispatched_at'])
            ->distinct('notification_channel_id')
            ->orderBy('notification_channel_id')->orderByDesc('dispatched_at')->orderByDesc('id')
            ->get()
            ->filter(fn (DeliveryLog $log): bool => $log->status === 'failure')
            ->keyBy('notification_channel_id');

        if ($latest->isEmpty()) {
            return collect();
        }

        $failureCounts = $attempts()
            ->where('status', 'failure')
            ->whereIn('notification_channel_id', $latest->keys())
            ->groupBy('notification_channel_id')
            ->selectRaw('notification_channel_id, count(*) as failures')
            ->pluck('failures', 'notification_channel_id');

        return NotificationChannel::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $latest->keys())
            ->get()
            ->sortBy(fn (NotificationChannel $channel): string => mb_strtolower((string) $channel->label))
            ->mapWithKeys(function (NotificationChannel $channel) use ($latest, $failureCounts): array {
                $failures = (int) $failureCounts[$channel->id];

                return [$channel->id => [
                    'kind' => 'channel_failing',
                    'severity' => 'danger',
                    'subject' => (string) $channel->label,
                    'detail' => 'its last delivery failed '.$latest[$channel->id]->dispatched_at->diffForHumans()
                        .' ('.$failures.' '.($failures === 1 ? 'failure' : 'failures').' in '.self::WINDOW_DAYS.' days)',
                    'target' => ['type' => 'channels', 'id' => null],
                ]];
            });
    }

    /**
     * @param  array<int, int>  $excludeChannelIds
     * @return list<array<string, mixed>>
     */
    private function unverifiedChannels(User $user, array $excludeChannelIds): array
    {
        $unverified = fn ($query) => $query
            ->where('notification_channels.user_id', $user->id)
            ->whereNull('notification_channels.verified_at')
            ->whereNotIn('notification_channels.id', $excludeChannelIds);

        $activeAlerts = AlertRule::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereHas('notificationChannels', $unverified)
            ->with(['notificationChannels' => $unverified, 'location', 'products', 'user'])
            ->orderBy('id')
            ->get();

        $alertsByChannel = [];
        $channels = [];

        foreach ($activeAlerts as $alert) {
            foreach ($alert->notificationChannels as $channel) {
                $channels[$channel->id] = $channel;
                $alertsByChannel[$channel->id][] = $alert->displayName();
            }
        }

        return collect($channels)
            ->sortBy(fn (NotificationChannel $channel): string => mb_strtolower((string) $channel->label))
            ->map(function (NotificationChannel $channel) use ($alertsByChannel): array {
                $names = $alertsByChannel[$channel->id];
                $listed = implode(', ', array_slice($names, 0, 3));
                $rest = count($names) - 3;

                return [
                    'kind' => 'channel_unverified',
                    'severity' => 'warning',
                    'subject' => (string) $channel->label,
                    'detail' => "isn't verified yet — confirm it so alerts using it are sure to reach you (".$listed.($rest > 0 ? " and {$rest} more" : '').')',
                    'target' => ['type' => 'channels', 'id' => null],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Active alerts that have no notification channel at all, so they can never
     * deliver. Typically a rule whose only channel was a phone that signed out,
     * was revoked or was pruned: the database cascade removes that link silently.
     *
     * @return list<array<string, mixed>>
     */
    private function activeAlertsWithoutChannel(User $user): array
    {
        return AlertRule::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereDoesntHave('notificationChannels')
            ->with(['location', 'products', 'user'])
            ->get()
            ->sortBy(fn (AlertRule $alert): string => mb_strtolower($alert->displayName()))
            ->map(fn (AlertRule $alert): array => [
                'kind' => 'alert_no_channel',
                'severity' => 'danger',
                'subject' => $alert->displayName(),
                'detail' => "has no notification channel, so it can't deliver — add a channel to it",
                'target' => ['type' => 'alert', 'id' => $alert->id],
            ])
            ->values()
            ->all();
    }

    /**
     * Active scheduled alerts on a mobile location that lost its source: its Traccar
     * device no longer exists on the server (see PollTraccarPositions), or its app
     * location has no phone linked any more. It is never updated again until the
     * subscriber picks its device again or a phone shares it, whether or not it ever
     * had a position.
     *
     * @return list<array<string, mixed>>
     */
    private function mobileLocationsWithMissingDevice(User $user): array
    {
        return AlertRule::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereIn('category', array_map(fn (AlertCategory $category): string => $category->value, self::SCHEDULED))
            ->whereHas('location', fn ($query) => $query->where('type', LocationType::Mobile->value)->where(fn ($q) => $q
                ->whereNotNull('traccar_device_missing_at')
                ->orWhere(fn ($app) => $app->where('mobile_source', MobileSource::App->value)->whereNull('app_token_id'))))
            ->with(['location', 'products', 'user'])
            ->get()
            ->sortBy(fn (AlertRule $alert): string => mb_strtolower($alert->displayName()))
            ->map(fn (AlertRule $alert): array => [
                'kind' => 'location_device_missing',
                'severity' => 'warning',
                'subject' => $alert->displayName(),
                'detail' => "can't follow ".$alert->location->label.($alert->location->mobile_source === MobileSource::App
                    ? ': no phone is sharing its location any more'
                    : ': its Traccar device no longer exists on the server'),
                'target' => ['type' => 'location', 'id' => $alert->location->id],
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mobileLocationsWithoutPosition(User $user): array
    {
        return AlertRule::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereIn('category', array_map(fn (AlertCategory $category): string => $category->value, self::SCHEDULED))
            ->whereHas('location', fn ($query) => $query->where('type', LocationType::Mobile->value)->whereNull('traccar_device_missing_at')
                ->where(fn ($q) => $q->whereNull('mobile_source')->orWhere('mobile_source', '!=', MobileSource::App->value)->orWhereNotNull('app_token_id'))
                ->where(fn ($q) => $q->whereNull('lat')->orWhereNull('lng')))
            ->with(['location', 'products', 'user'])
            ->get()
            ->sortBy(fn (AlertRule $alert): string => mb_strtolower($alert->displayName()))
            ->map(fn (AlertRule $alert): array => [
                'kind' => 'location_no_position',
                'severity' => 'warning',
                'subject' => $alert->displayName(),
                'detail' => 'is waiting for '.$alert->location->label.' to report a position',
                'target' => ['type' => 'location', 'id' => $alert->location->id],
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inactiveAlerts(User $user): array
    {
        return AlertRule::query()
            ->where('user_id', $user->id)
            ->where('is_active', false)
            ->with(['location', 'products', 'user'])
            ->get()
            ->sortBy(fn (AlertRule $alert): string => mb_strtolower($alert->displayName()))
            ->map(fn (AlertRule $alert): array => [
                'kind' => 'alert_inactive',
                'severity' => 'gray',
                'subject' => $alert->displayName(),
                'detail' => 'is turned off',
                'target' => ['type' => 'alert', 'id' => $alert->id],
            ])
            ->values()
            ->all();
    }
}
