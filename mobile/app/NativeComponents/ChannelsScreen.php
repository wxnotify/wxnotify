<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Maintenance;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Unauthenticated;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use Native\Mobile\Edge\NativeComponent;

/**
 * The subscriber's notification channels: where alerts are sent, and whether each is verified.
 * Paints from the cache first, refreshes, and keeps the last copy with a note when offline.
 */
class ChannelsScreen extends NativeComponent
{
    use RequiresSignIn;

    /** @var array<int, array<string, mixed>> */
    public array $channels = [];

    public bool $offline = false;

    /** Whether the list on screen is known (remembered or just loaded): only then can it be "empty". */
    public bool $loaded = false;

    public ?string $error = null;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $cached = app(LocalCache::class)->channels();
        $this->channels = $cached ?? [];
        $this->loaded = $cached !== null;

        $this->refresh();
    }

    /**
     * Back from the new-channel or channel screen (added, renamed, verified, deleted): the list may have changed.
     */
    public function onResume(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        try {
            $this->channels = app(WxNotifyApi::class)->channels();
            app(LocalCache::class)->rememberChannels($this->channels);
            $this->offline = false;
            $this->error = null;
            $this->loaded = true;
        } catch (Unauthenticated) {
            app(SignOut::class)->clearLocal();
            $this->replace('/sign-in');
        } catch (Offline) {
            $this->offline = true;
            $this->error = null;
        } catch (Maintenance $e) {
            $this->offline = false;
            $this->error = $e->getMessage();
        } catch (ApiException) {
            $this->offline = false;
            $this->error = 'WxNotify could not load your channels. Try again in a moment.';
        }
    }

    public function create(): void
    {
        $this->navigate('/channels/new');
    }

    public function open(int $id): void
    {
        $this->navigate("/channels/{$id}");
    }

    /**
     * The type, then where it sends (masked) or who manages it, then whether it still needs verifying.
     *
     * @param  array<string, mixed>  $channel
     */
    public function subtitle(array $channel): string
    {
        $parts = [(string) $channel['type_label']];

        if ($channel['managed_by_app']) {
            $parts[] = 'Managed in Account';
        } else {
            if (filled($channel['destination'] ?? null)) {
                $parts[] = (string) $channel['destination'];
            }

            if (! $channel['verified']) {
                $parts[] = 'Not verified';
            }
        }

        return implode(' · ', $parts);
    }
}
