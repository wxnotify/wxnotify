<?php

namespace App\Actions\Channels;

use App\Models\NotificationChannel;
use STS\FilamentImpersonate\ImpersonateManager;

/**
 * Hands a channel's full Apprise URL back to its owner, on request.
 *
 * The URL holds the subscriber's own tokens (their Pushover user key and application
 * token, their bot token or webhook), so it is shown only on demand, only to the owner,
 * never while staff impersonate the subscriber, and each reveal is written to the
 * activity log without the URL. `NotificationChannel`'s own log options exclude
 * `apprise_url` and `config`, and this entry carries no properties at all.
 */
class RevealChannelUrl
{
    /**
     * @return array{url: ?string, error: ?string}
     */
    public function __invoke(NotificationChannel $channel): array
    {
        if (app(ImpersonateManager::class)->isImpersonating()) {
            return $this->refused('Not available while impersonating a subscriber.');
        }

        if ($channel->user_id !== auth()->id()) {
            return $this->refused('You can only reveal your own channels.');
        }

        if (blank($channel->apprise_url)) {
            return $this->refused('This channel has no URL to reveal.');
        }

        activity()
            ->performedOn($channel)
            ->causedBy(auth()->user())
            ->event('revealed')
            ->log('Revealed the channel destination URL');

        return ['url' => $channel->apprise_url, 'error' => null];
    }

    /**
     * @return array{url: null, error: string}
     */
    private function refused(string $message): array
    {
        return ['url' => null, 'error' => $message];
    }
}
