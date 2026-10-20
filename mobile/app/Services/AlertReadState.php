<?php

namespace App\Services;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Maintenance;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\RateLimited;
use App\Exceptions\Api\Rejected;
use App\Exceptions\Api\Retryable;
use App\Exceptions\Api\Unauthenticated;
use App\Support\AlertRefFormat;
use App\Support\LocalCache;
use App\Support\ReadCutoff;

/**
 * Read and unread alerts on this phone. A read is applied locally first (the cached flag and the unread
 * count), then sent to the server best-effort: a call that fails is kept in a small pending list (one
 * entry for "mark all", holding its cutoff) and replayed on the next feed load, so opening an alert never waits for or fails
 * on the network. Every change ends with the icon badge set to the unread count. Never throws to a screen;
 * a refused token stops a retry and leaves the pending reads for the screen's own sign-out path.
 */
class AlertReadState
{
    public function __construct(
        private LocalCache $cache,
        private WxNotifyApi $api,
        private AppBadge $badge,
    ) {}

    /**
     * Reads one alert. An alert this phone already shows read, with no read waiting for the server, sends
     * nothing (the list tap and the detail screen both call this); an alert it has not cached asks the server.
     * An id already pending was counted out when it was first read, so it never lowers the count again.
     */
    public function open(int $id): void
    {
        $cachedRead = $this->cache->isCachedRead($id);

        if ($cachedRead === true && ! in_array($id, $this->cache->pendingReads(), true)) {
            $this->setBadge();

            return;
        }

        $wasPending = in_array($id, $this->cache->pendingReads(), true);

        if ($this->cache->markAlertRead($id) && ! $wasPending) {
            $this->lowerUnreadCount();
        }

        try {
            $this->cache->rememberUnreadCount($this->api->markAlertRead($id));
            $this->forgetPending($id);
        } catch (Rejected) {
            $this->forgetPending($id);
        } catch (ApiException) {
            $this->cache->rememberPendingReads([...$this->cache->pendingReads(), $id]);
        }

        $this->setBadge();
    }

    /**
     * Reads the alert a tapped push named by its reference (AlertRefFormat). The phone may not have cached it, and its
     * delivery row may not even exist yet, so the server decides: its count is stored and shown on the badge, and the
     * feed reloads on its next showing so the dot goes. A reference in the wrong format is ignored. Offline, a server
     * error, rate limiting or maintenance keep the reference for retryPending(); any other refusal drops it; a refused
     * token stops (the feed load that follows signs out). Never throws to a screen.
     */
    public function openByRef(string $ref): void
    {
        if (! AlertRefFormat::isValid($ref)) {
            return;
        }

        try {
            $this->syncFromServer($this->api->markAlertReadByRef($ref));
            $this->cache->forgetSyncedAt();
            $this->scheduleSecondPass($ref);
        } catch (Offline|Retryable|Maintenance) {
            $this->cache->rememberPendingRefs([...$this->cache->pendingRefs(), $ref]);
        } catch (ApiException) {
            return;
        }
    }

    /**
     * Queues the read of a tapped push without calling the server, for a screen that must not wait (the alert tap
     * link, which hands straight over to Alerts, whose feed load replays it before fetching). Ignores a malformed reference.
     */
    public function queueRef(string $ref): void
    {
        if (AlertRefFormat::isValid($ref)) {
            $this->cache->rememberPendingRefs([...$this->cache->pendingRefs(), $ref]);
        }
    }

    /**
     * Reads every alert the phone had loaded. The cutoff is the `as_of` of the last first-page load: only alerts
     * sent at or before it turn read, and the count becomes the cached alerts still unread (those that arrived
     * after it), so a warning the subscriber never saw is not read for them. Without a known `as_of` (an older
     * backend) everything cached turns read and the count drops to zero, as before. A call that fails is kept
     * with its cutoff, so the replay reads exactly what this tap read. After a cutoff mark all the single reads still
     * waiting are replayed at once, so the stored count (the server's) never counts an alert already shown read.
     *
     * @return string|null the cutoff used, null when everything was read
     */
    public function markAll(): ?string
    {
        $through = $this->cache->feedAsOf();

        $this->cache->markAllAlertsRead($through);
        $this->cache->rememberUnreadCount($through === null ? 0 : $this->cache->unreadInFeed());

        try {
            $this->cache->rememberUnreadCount($this->api->markAllAlertsRead($through));
            $this->settlePendingReadAll($through);
            $this->replayPendingReads();
        } catch (ApiException) {
            $this->cache->rememberPendingReadAll(through: $through);
        }

        $this->setBadge();

        return $through;
    }

    /**
     * Stores the unread count the server reported and sets the icon badge to it.
     */
    public function syncFromServer(int $unread): void
    {
        $this->cache->rememberUnreadCount($unread);
        $this->badge->set($unread);
    }

    /**
     * Applies the reads still waiting for the server to a freshly fetched feed page, so an alert read
     * offline does not turn unread again, then syncs the count (less those alerts) and the badge. A page
     * without a count leaves the stored count and the badge alone.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    public function applyFeed(array $entries, ?int $serverUnread): array
    {
        $readAll = $this->cache->pendingReadAll();
        $cutoff = $this->cache->pendingReadAllCutoff();
        $pending = $this->cache->pendingReads();
        $locallyRead = 0;

        foreach ($entries as $index => $entry) {
            $readByMarkAll = $readAll && ReadCutoff::covers($cutoff, $entry['dispatched_at'] ?? null);

            if (($entry['read'] ?? true) === false && ($readByMarkAll || in_array($entry['id'], $pending, true))) {
                $entries[$index]['read'] = true;
                $locallyRead++;
            }
        }

        if ($serverUnread !== null) {
            $this->syncFromServer($readAll && $cutoff === null ? 0 : max(0, $serverUnread - $locallyRead));
        }

        return $entries;
    }

    /**
     * Replays the pending "mark all", then each pending id, then each pending push-tap reference. A 404 drops an id
     * (it left the feed); offline, rate limiting or a refused token stops at once and keeps what is left; any other
     * failure keeps that id.
     */
    public function retryPending(): void
    {
        if ($this->cache->pendingReadAll()) {
            $through = $this->cache->pendingReadAllCutoff();

            try {
                $this->cache->rememberUnreadCount($this->api->markAllAlertsRead($through));
                $this->settlePendingReadAll($through);
            } catch (Rejected) {
                $this->cache->rememberPendingReadAll(false);
            } catch (ApiException) {
                return;
            }
        }

        if ($this->replayPendingReads()) {
            $secondPass = $this->cache->secondPassRefs();

            if ($this->replayPendingRefs()) {
                $this->replaySecondPass($secondPass);
            }
        }
    }

    /**
     * Replays each queued push-tap reference, storing the server's count after each. Offline, rate limiting or a
     * refused token stops at once; another server error or maintenance keeps that reference; any other refusal drops it.
     * A reference read here gets its second pass on the next feed load.
     *
     * @return bool false when it stopped early (offline, rate limited, refused token)
     */
    private function replayPendingRefs(): bool
    {
        foreach ($this->cache->pendingRefs() as $ref) {
            try {
                $this->syncFromServer($this->api->markAlertReadByRef($ref));
                $this->forgetPendingRef($ref);
                $this->scheduleSecondPass($ref);
            } catch (Offline|RateLimited|Unauthenticated) {
                return false;
            } catch (Retryable|Maintenance) {
                continue;
            } catch (ApiException) {
                $this->forgetPendingRef($ref);
            }
        }

        return true;
    }

    /**
     * Reads once more the references read on an earlier feed load, then forgets them (never a third time): a group is
     * unread while any of its push rows is, so a row saved after the first read (another phone's push of the same
     * alert, or the tapped push's own row not yet written) would bring the dot back. Offline, rate limiting or a
     * refused token stops and keeps what is left; another server error keeps that reference; any other refusal drops it.
     *
     * @param  array<int, string>  $refs  the second-pass list as it was before this feed load's first passes
     */
    private function replaySecondPass(array $refs): void
    {
        foreach ($refs as $ref) {
            try {
                $this->syncFromServer($this->api->markAlertReadByRef($ref));
                $this->forgetSecondPass($ref);
            } catch (Offline|RateLimited|Unauthenticated) {
                return;
            } catch (Retryable|Maintenance) {
                continue;
            } catch (ApiException) {
                $this->forgetSecondPass($ref);
            }
        }
    }

    private function scheduleSecondPass(string $ref): void
    {
        $this->cache->rememberSecondPassRefs([...$this->cache->secondPassRefs(), $ref]);
    }

    private function forgetSecondPass(string $ref): void
    {
        $this->cache->rememberSecondPassRefs(array_values(array_diff($this->cache->secondPassRefs(), [$ref])));
    }

    private function forgetPendingRef(string $ref): void
    {
        $this->cache->rememberPendingRefs(array_values(array_diff($this->cache->pendingRefs(), [$ref])));
    }

    /**
     * Replays each pending id, storing the server's count after each. A 404 drops an id; offline, rate limiting
     * or a refused token stops at once; any other failure keeps that id.
     *
     * @return bool false when it stopped early (offline, rate limited, refused token)
     */
    private function replayPendingReads(): bool
    {
        foreach ($this->cache->pendingReads() as $id) {
            try {
                $this->cache->rememberUnreadCount($this->api->markAlertRead($id));
                $this->forgetPending($id);
            } catch (Offline|RateLimited|Unauthenticated) {
                return false;
            } catch (Rejected) {
                $this->forgetPending($id);
            } catch (ApiException) {
                continue;
            }
        }

        return true;
    }

    /**
     * After the server took a mark all: without a cutoff it read everything, so the single reads waiting are
     * done too; with one they are kept (an alert opened that arrived after the cutoff is still unread there)
     * and replayed by the caller, which is harmless because a read is idempotent.
     */
    private function settlePendingReadAll(?string $through): void
    {
        $this->cache->rememberPendingReadAll(false);

        if ($through === null) {
            $this->cache->rememberPendingReads([]);
        }
    }

    private function lowerUnreadCount(): void
    {
        $count = $this->cache->unreadCount();

        if ($count !== null) {
            $this->cache->rememberUnreadCount($count - 1);
        }
    }

    private function forgetPending(int $id): void
    {
        $this->cache->rememberPendingReads(array_values(array_diff($this->cache->pendingReads(), [$id])));
    }

    /**
     * Sets the icon to the unread count this phone knows; nothing until a feed load has reported one.
     */
    private function setBadge(): void
    {
        $count = $this->cache->unreadCount();

        if ($count !== null) {
            $this->badge->set($count);
        }
    }
}
