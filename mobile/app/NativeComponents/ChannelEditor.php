<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\CodeNotSent;
use App\Exceptions\Api\Conflict;
use App\Exceptions\Api\InvalidCode;
use App\Exceptions\Api\Maintenance;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\RateLimited;
use App\Exceptions\Api\Rejected;
use App\Exceptions\Api\TestFailed;
use App\Exceptions\Api\Unauthenticated;
use App\Exceptions\Api\ValidationFailed;
use App\Services\WxNotifyApi;
use App\Support\Concerns\EditsADraft;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use Native\Mobile\Edge\NativeComponent;

/**
 * One channel: confirm it with the code it was sent (resending if needed), rename it, send a test
 * through it, or delete it. The destination is never shown beyond its masked summary. A rename is
 * a draft held here until Save. The channel is found in the channels list (there is no single-channel
 * endpoint), from the server or, offline, the remembered copy.
 */
class ChannelEditor extends NativeComponent
{
    use EditsADraft;
    use RequiresSignIn;

    public ?int $channelId = null;

    /** @var array<string, mixed> */
    public array $channel = [];

    /** @var array<string, string> */
    public array $draft = ['label' => ''];

    /** @var array<string, string> */
    public array $original = [];

    /** @var array<string, string> */
    public array $errors = [];

    public string $code = '';

    public ?string $codeError = null;

    /** A failure, shown beside the buttons (or why the channel could not be opened). */
    public ?string $banner = null;

    /** A success ("Channel verified.", "Test notification sent."), shown beside the buttons. */
    public ?string $info = null;

    public bool $ready = false;

    public bool $canRetry = false;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $this->channelId = (int) $this->param('id');

        $this->load();
    }

    public function load(): void
    {
        $this->banner = null;
        $this->canRetry = false;

        $channel = $this->loadChannel($this->channelId);

        if ($channel === null) {
            return;
        }

        if ($channel['managed_by_app']) {
            $this->banner = 'This channel is a phone\'s push notifications, managed in Account.';

            return;
        }

        $this->channel = $channel;
        $this->draft = ['label' => (string) $channel['label']];
        $this->original = $this->draft;
        $this->ready = true;
    }

    public function updatedDraft(mixed $value, ?string $key = null): void
    {
        unset($this->errors['label']);
    }

    public function updatedCode(): void
    {
        $this->codeError = null;
    }

    // ---- actions -------------------------------------------------------------------------------

    public function save(): void
    {
        $this->banner = null;
        $this->info = null;
        $this->errors = [];

        if (trim($this->draft['label']) === '') {
            $this->errors['label'] = 'Give this channel a name.';

            return;
        }

        try {
            $channel = app(WxNotifyApi::class)->renameChannel($this->channelId, trim($this->draft['label']));
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (ValidationFailed $e) {
            $this->errors['label'] = $e->errors['label'] ?? 'That name can\'t be used.';

            return;
        } catch (Conflict $e) {
            $this->banner = $e->getMessage() !== '' ? $e->getMessage() : 'This channel can\'t be renamed.';

            return;
        } catch (Rejected) {
            $this->banner = 'This channel no longer exists.';

            return;
        } catch (Maintenance $e) {
            $this->banner = $e->getMessage();

            return;
        } catch (Offline) {
            $this->banner = 'You\'re offline. Reconnect to save.';

            return;
        } catch (ApiException) {
            $this->banner = 'Couldn\'t save the channel. Please try again.';

            return;
        }

        $this->rememberChannel($channel);
        $this->original = $this->draft;
        $this->back();
    }

    public function verify(): void
    {
        $this->banner = null;
        $this->info = null;
        $this->codeError = null;
        $code = trim($this->code);

        if ($code === '') {
            $this->codeError = 'Enter the 6-digit code.';

            return;
        }

        try {
            $channel = app(WxNotifyApi::class)->verifyChannel($this->channelId, $code);
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (InvalidCode $e) {
            $this->codeError = $e->getMessage() !== '' ? $e->getMessage() : 'That code didn\'t match, or it expired. Request a new code if you need one.';

            return;
        } catch (ValidationFailed $e) {
            $this->codeError = $e->errors['code'] ?? 'Enter the 6-digit code.';

            return;
        } catch (Conflict $e) {
            $this->banner = $e->getMessage() !== '' ? $e->getMessage() : 'This channel can\'t be verified.';

            return;
        } catch (Rejected) {
            $this->banner = 'This channel no longer exists.';

            return;
        } catch (Maintenance $e) {
            $this->banner = $e->getMessage();

            return;
        } catch (Offline) {
            $this->banner = 'You\'re offline. Reconnect to verify.';

            return;
        } catch (ApiException) {
            $this->banner = 'Couldn\'t verify the channel. Please try again.';

            return;
        }

        $this->channel = $channel;
        $this->code = '';
        $this->info = 'Channel verified.';
        $this->rememberChannel($channel);
    }

    public function resend(): void
    {
        $this->banner = null;
        $this->info = null;
        $this->codeError = null;

        try {
            app(WxNotifyApi::class)->resendChannelCode($this->channelId);
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (CodeNotSent) {
            $this->banner = 'We couldn\'t send the confirmation code. Please try again in a moment.';

            return;
        } catch (Conflict $e) {
            $this->banner = $e->getMessage() !== '' ? $e->getMessage() : 'A new code can\'t be sent for this channel.';

            return;
        } catch (Rejected) {
            $this->banner = 'This channel no longer exists.';

            return;
        } catch (Maintenance $e) {
            $this->banner = $e->getMessage();

            return;
        } catch (Offline) {
            $this->banner = 'You\'re offline. Reconnect to resend the code.';

            return;
        } catch (RateLimited) {
            $this->banner = 'You\'ve sent too many codes or tests. Try again in a little while.';

            return;
        } catch (ApiException) {
            $this->banner = 'Couldn\'t send a new code. Please try again.';

            return;
        }

        $this->code = '';
        $this->info = 'We sent a new code.';
    }

    public function sendTest(): void
    {
        $this->banner = null;
        $this->info = null;

        try {
            app(WxNotifyApi::class)->testChannel($this->channelId);
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (TestFailed) {
            $this->banner = 'Couldn\'t send the test notification. Check the channel is still reachable and try again.';

            return;
        } catch (Conflict $e) {
            $this->banner = $e->getMessage() !== '' ? $e->getMessage() : 'A test can\'t be sent through this channel.';

            return;
        } catch (Rejected) {
            $this->banner = 'This channel no longer exists.';

            return;
        } catch (Maintenance $e) {
            $this->banner = $e->getMessage();

            return;
        } catch (Offline) {
            $this->banner = 'You\'re offline. Reconnect to send a test.';

            return;
        } catch (RateLimited) {
            $this->banner = 'You\'ve sent too many codes or tests. Try again in a little while.';

            return;
        } catch (ApiException) {
            $this->banner = 'Couldn\'t send the test notification. Please try again.';

            return;
        }

        $this->info = 'Test notification sent.';
    }

    /**
     * A refusal keeps the channel and shows the server's own message (a channel that is the only one
     * for some alerts names the first few of them).
     */
    public function delete(): void
    {
        $this->banner = null;
        $this->info = null;

        try {
            app(WxNotifyApi::class)->deleteChannel($this->channelId);
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (Conflict $e) {
            $this->confirmingDelete = false;
            $this->banner = $e->getMessage() !== '' ? $e->getMessage() : 'This channel can\'t be deleted right now.';

            return;
        } catch (Rejected) {
            // Already gone elsewhere: the list should drop it too.
        } catch (Maintenance $e) {
            $this->confirmingDelete = false;
            $this->banner = $e->getMessage();

            return;
        } catch (Offline) {
            $this->confirmingDelete = false;
            $this->banner = 'You\'re offline. Reconnect to delete this channel.';

            return;
        } catch (ApiException) {
            $this->confirmingDelete = false;
            $this->banner = 'Couldn\'t delete the channel. Please try again.';

            return;
        }

        $cache = app(LocalCache::class);
        $cache->rememberChannels(array_values(array_filter($cache->channels() ?? [], fn (array $channel): bool => $channel['id'] !== $this->channelId)));

        $this->original = $this->draft;
        $this->back();
    }

    public function isDirty(): bool
    {
        return $this->draft !== $this->original;
    }

    public function isVerified(): bool
    {
        return (bool) ($this->channel['verified'] ?? false);
    }

    // ---- helpers -------------------------------------------------------------------------------

    /**
     * The channel from the server's list, or the remembered copy when the server cannot be reached.
     *
     * @return array<string, mixed>|null the channel, or null with a banner (or a sign-out) when it cannot be opened
     */
    private function loadChannel(int $id): ?array
    {
        $cache = app(LocalCache::class);
        $cached = collect($cache->channels() ?? [])->firstWhere('id', $id);

        try {
            $channels = app(WxNotifyApi::class)->channels();
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return null;
        } catch (ApiException $e) {
            if ($cached === null) {
                $this->banner = match (true) {
                    $e instanceof Offline => 'Connect to the internet to open this channel.',
                    $e instanceof Maintenance => $e->getMessage(),
                    default => 'WxNotify could not load this channel. Try again in a moment.',
                };
                $this->canRetry = true;
            }

            return $cached;
        }

        $cache->rememberChannels($channels);
        $channel = collect($channels)->firstWhere('id', $id);

        if ($channel === null) {
            $this->banner = 'This channel no longer exists.';
        }

        return $channel;
    }

    /**
     * @param  array<string, mixed>  $channel
     */
    private function rememberChannel(array $channel): void
    {
        $cache = app(LocalCache::class);

        $cache->rememberChannels(array_values(array_map(
            fn (array $listed): array => $listed['id'] === ($channel['id'] ?? null) ? $channel : $listed,
            $cache->channels() ?? [],
        )));
    }
}
