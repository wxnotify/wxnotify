<?php

namespace App\Services;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\LocationLimitReached;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Unauthenticated;
use App\Support\LocationSharingState;
use App\Support\TokenStore;
use Native\Mobile\Facades\Geolocation;
use Throwable;

/**
 * Everything the app does about sharing this phone's position: asking the server for the phone's
 * location and a narrow beacon token, running the plugin's native beacon with them (it keeps
 * posting while the app is closed), and re-arming or stopping it. The native watch is the source
 * of truth for "is it running"; the wanted flag is what the subscriber asked for. The beacon token
 * goes only to TokenStore and the plugin; it is never logged. The plugins record the time of
 * EVERY post attempt, whatever the answer, with the HTTP status in `lastShareStatus` (0 network
 * failure, 2xx accepted, 401/403 rejected, -1 expired): only an accepted post counts as shared,
 * and a rejected one means the beacon token is dead and must be replaced. Nothing here throws
 * to a screen: native or server failures are swallowed, and stopping always forgets the token.
 */
class LocationSharing
{
    public const WATCH_ID = 'wxnotify-location';

    /** Do not report a move smaller than this (metres): it spares the battery. */
    private const MIN_DISTANCE_METERS = 250;

    /** How often Android's location request runs (ms); the plugin's default of 5 s drains the battery. */
    private const LOCATION_REQUEST_MS = 60_000;

    public function __construct(
        private WxNotifyApi $api,
        private TokenStore $tokens,
        private LocationSharingState $state,
    ) {}

    /**
     * @return array{status: 'off'|'on'|'waiting', interval_minutes: ?int, last_shared_at: ?string, while_using_only: bool}
     */
    public function state(): array
    {
        $watch = $this->activeWatch();
        $sharing = $watch !== null;

        return [
            'status' => $sharing ? 'on' : ($this->state->wanted() ? 'waiting' : 'off'),
            'interval_minutes' => $sharing && isset($watch['shareIntervalMs']) ? (int) round($watch['shareIntervalMs'] / 60000) : null,
            'last_shared_at' => $sharing && $this->lastShareAccepted($watch) ? ($watch['lastShareAt'] ?? null) : null,
            'while_using_only' => $sharing && ($watch['authorization'] ?? null) === 'whenInUse',
        ];
    }

    /**
     * @return 'started'|'limit'|'offline'|'failed'
     */
    public function enable(): string
    {
        try {
            $result = $this->api->enableLocationSharing();
        } catch (LocationLimitReached) {
            return 'limit';
        } catch (Offline) {
            return 'offline';
        } catch (ApiException) {
            return 'failed';
        }

        try {
            $this->tokens->putBeaconToken($result['beacon_token'], $result['url']);
            $this->state->markWanted();
            $this->startBeacon($result['url'], $result['beacon_token'], $result['interval_seconds']);
        } catch (Throwable) {
            $this->tokens->forgetBeaconToken();
            $this->state->markOff();

            return 'failed';
        }

        return 'started';
    }

    /**
     * @return bool false when the server could not be told, in which case sharing stays on
     */
    public function disable(): bool
    {
        try {
            $this->api->disableLocationSharing();
        } catch (Unauthenticated) {
            // The phone is signed out on the server anyway: stop locally.
        } catch (ApiException) {
            return false;
        }

        $this->stopLocal();

        return true;
    }

    /**
     * On launch while sharing is wanted: learn from the server whether the location is still linked
     * and what interval it wants, and bring the native beacon in line. Never throws at the screens.
     */
    public function refreshOnLaunch(): void
    {
        if (! $this->state->wanted()) {
            return;
        }

        try {
            $remote = $this->api->locationSharing();

            if (! ($remote['linked'] ?? false)) {
                $this->stopLocal();

                return;
            }

            $watch = $this->activeWatch();
            $token = $this->tokens->beaconToken();
            $url = $this->tokens->beaconUrl();
            $wantedSeconds = (int) ($remote['interval_seconds'] ?? 0);

            if ($watch === null || $this->beaconRejected($watch) || $token === null || $url === null) {
                $fresh = $this->api->enableLocationSharing();
                $this->tokens->putBeaconToken($fresh['beacon_token'], $fresh['url']);
                $this->startBeacon($fresh['url'], $fresh['beacon_token'], $fresh['interval_seconds']);

                return;
            }

            if ($wantedSeconds > 0 && (int) ($watch['shareIntervalMs'] ?? 0) !== $wantedSeconds * 1000) {
                $this->startBeacon($url, $token, $wantedSeconds);

                return;
            }

            // The watch keeps every fix in an on-device buffer nothing reads: drop what it holds.
            Geolocation::trimWatch(self::WATCH_ID, (int) ($watch['bufferBytes'] ?? 0));
        } catch (Unauthenticated) {
            // The screens handle a revoked sign-in on their own API calls.
        } catch (ApiException) {
            // Offline, limit or a server hiccup: the next launch tries again.
        } catch (Throwable) {
            // A native failure must never reach the screen that asked: the next launch tries again.
        }
    }

    /**
     * Stop sharing on this phone without asking the server (signing out, or a server that says the
     * location is no longer linked): stop the native watch and forget the token and the flag.
     */
    public function stopLocal(): void
    {
        try {
            Geolocation::stopBackgroundWatch(self::WATCH_ID, clearBuffer: true);
        } catch (Throwable) {
            // Forgetting the token and the flag below must happen whatever the native layer does.
        }

        $this->tokens->forgetBeaconToken();
        $this->state->markOff();
    }

    private function startBeacon(string $url, string $token, int $intervalSeconds): void
    {
        // A watch with this id may already be running with the old settings; its buffer is of no use.
        Geolocation::stopBackgroundWatch(self::WATCH_ID, clearBuffer: true);

        Geolocation::sharePosition(url: $url, token: $token, interval: $intervalSeconds, fineAccuracy: false)
            ->id(self::WATCH_ID)
            ->minDistance(self::MIN_DISTANCE_METERS)
            ->interval(self::LOCATION_REQUEST_MS)
            ->start();
    }

    /**
     * The native status of this beacon's watch, or null when it is not running.
     *
     * @return array<string, mixed>|null
     */
    private function activeWatch(): ?array
    {
        $watch = Geolocation::backgroundWatchStatus();

        if (! is_array($watch) || ! ($watch['shareActive'] ?? false) || ($watch['id'] ?? null) !== self::WATCH_ID) {
            return null;
        }

        return $watch;
    }

    /**
     * @param  array<string, mixed>  $watch
     */
    private function lastShareAccepted(array $watch): bool
    {
        $status = $watch['lastShareStatus'] ?? null;

        return is_numeric($status) && (int) $status >= 200 && (int) $status < 300;
    }

    /**
     * The server refused the beacon token (revoked or expired); a 0 or a 5xx is only transient.
     *
     * @param  array<string, mixed>  $watch
     */
    private function beaconRejected(array $watch): bool
    {
        $status = $watch['lastShareStatus'] ?? null;

        return is_numeric($status) && in_array((int) $status, [401, 403], true);
    }
}
