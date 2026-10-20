<?php

namespace App\Services;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Rejected;
use App\Exceptions\Api\Unauthenticated;
use App\Support\LocalCache;

/**
 * Read and unread alerts on this phone. A read is applied locally first (the cached flag and the unread
 * count), then sent to the server best-effort: a call that fails is kept in a small pending list (one
 * flag for "mark all") and replayed on the next feed load, so opening an alert never waits for or fails
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
     */
    public function open(int $id): void
    {
        $cachedRead = $this->cache->isCachedRead($id);

        if ($cachedRead === true && ! in_array($id, $this->cache->pendingReads(), true)) {
            $this->setBadge();

            return;
        }

        if ($this->cache->markAlertRead($id)) {
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
     * Reads every alert: everything cached turns read and the count drops to zero at once.
     */
    public function markAll(): void
    {
        $this->cache->markAllAlertsRead();
        $this->cache->rememberUnreadCount(0);

        try {
            $this->cache->rememberUnreadCount($this->api->markAllAlertsRead());
            $this->cache->rememberPendingReads([]);
            $this->cache->rememberPendingReadAll(false);
        } catch (ApiException) {
            $this->cache->rememberPendingReadAll();
        }

        $this->setBadge();
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
        $pending = $this->cache->pendingReads();
        $locallyRead = 0;

        foreach ($entries as $index => $entry) {
            if (($entry['read'] ?? true) === false && ($readAll || in_array($entry['id'], $pending, true))) {
                $entries[$index]['read'] = true;
                $locallyRead++;
            }
        }

        if ($serverUnread !== null) {
            $this->syncFromServer($readAll ? 0 : max(0, $serverUnread - $locallyRead));
        }

        return $entries;
    }

    /**
     * Replays the pending "mark all" and then each pending id. A 404 drops an id (it left the feed);
     * offline or a refused token stops at once and keeps what is left; any other failure keeps that id.
     */
    public function retryPending(): void
    {
        if ($this->cache->pendingReadAll()) {
            try {
                $this->cache->rememberUnreadCount($this->api->markAllAlertsRead());
                $this->cache->rememberPendingReads([]);
                $this->cache->rememberPendingReadAll(false);
            } catch (Rejected) {
                $this->cache->rememberPendingReadAll(false);
            } catch (ApiException) {
                return;
            }
        }

        foreach ($this->cache->pendingReads() as $id) {
            try {
                $this->cache->rememberUnreadCount($this->api->markAlertRead($id));
                $this->forgetPending($id);
            } catch (Offline|Unauthenticated) {
                return;
            } catch (Rejected) {
                $this->forgetPending($id);
            } catch (ApiException) {
                continue;
            }
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
