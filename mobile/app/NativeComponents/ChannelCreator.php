<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\ChannelLimitReached;
use App\Exceptions\Api\CodeNotSent;
use App\Exceptions\Api\Maintenance;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\RateLimited;
use App\Exceptions\Api\Unauthenticated;
use App\Exceptions\Api\ValidationFailed;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\Concerns\RequiresSignIn;
use Native\Mobile\Edge\NativeComponent;

/**
 * Adds a channel: pick one of the types the server offers, fill in its fields (described by the
 * server, so a type's setup is never hard-coded here) and have the server send a confirmation
 * code. On success the screen is replaced by the channel's own screen, where the code is entered.
 * Typed values (a webhook URL or bot token is a password) live only in this component and are
 * cleared once the channel exists.
 */
class ChannelCreator extends NativeComponent
{
    use RequiresSignIn;

    /** @var array<int, array<string, mixed>> */
    public array $types = [];

    public bool $ready = false;

    /** Why the types could not be loaded. */
    public ?string $banner = null;

    public bool $canRetry = false;

    /** Index into $types of the chosen type; null while choosing. */
    public ?int $selected = null;

    /** The most fields the screen has an input for (native:model needs a static path per input). */
    private const MAX_FIELDS = 3;

    /** @var array<int, string> value of each field of the chosen type, by the field's position */
    public array $values = [];

    public string $label = '';

    /** @var array<string, string> */
    public array $errors = [];

    /** The message beside the button (or under the list while choosing). */
    public ?string $feedback = null;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $this->load();
    }

    public function load(): void
    {
        $this->banner = null;
        $this->canRetry = false;

        try {
            $this->types = array_values(app(WxNotifyApi::class)->channelTypes());
            $this->ready = true;
        } catch (Unauthenticated) {
            $this->signOutLocally();
        } catch (Offline) {
            $this->banner = 'Connect to the internet to add a channel.';
            $this->canRetry = true;
        } catch (Maintenance $e) {
            $this->banner = $e->getMessage();
            $this->canRetry = true;
        } catch (ApiException) {
            $this->banner = 'WxNotify could not load the channel types. Try again in a moment.';
            $this->canRetry = true;
        }
    }

    public function pickType(int $index): void
    {
        $this->feedback = null;
        $type = $this->types[$index] ?? null;

        if ($type === null) {
            return;
        }

        if (! $type['can_add']) {
            $this->feedback = 'Your plan\'s free channel is in use. Remove a channel, or upgrade, to add another.';

            return;
        }

        if (count($type['fields']) > self::MAX_FIELDS) {
            $this->feedback = 'This channel type can\'t be set up in the app yet.';

            return;
        }

        $this->selected = $index;
        $this->values = array_map(fn (array $field): string => (string) ($field['default'] ?? ''), array_values($type['fields']));
        $this->label = '';
        $this->errors = [];
    }

    public function chooseAnother(): void
    {
        $this->selected = null;
        $this->values = [];
        $this->label = '';
        $this->errors = [];
        $this->feedback = null;
    }

    public function send(): void
    {
        $type = $this->selected === null ? null : ($this->types[$this->selected] ?? null);

        if ($type === null) {
            return;
        }

        $this->feedback = null;
        $this->errors = $this->validateValues($type);

        if ($this->errors !== []) {
            return;
        }

        try {
            $channel = app(WxNotifyApi::class)->createChannel($this->payload($type));
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (ChannelLimitReached $e) {
            $this->feedback = $e->getMessage() !== '' ? $e->getMessage() : 'Your plan\'s free channel is in use. Remove a channel, or upgrade, to add another.';

            return;
        } catch (CodeNotSent) {
            $this->feedback = 'We couldn\'t send the confirmation code. Please try again in a moment.';

            return;
        } catch (ValidationFailed $e) {
            $this->errors = $this->mapServerErrors($type, $e->errors);

            return;
        } catch (Maintenance $e) {
            $this->feedback = $e->getMessage();

            return;
        } catch (Offline) {
            $this->feedback = 'You\'re offline. Reconnect to send the code.';

            return;
        } catch (RateLimited) {
            $this->feedback = 'You\'ve sent too many codes or tests. Try again in a little while.';

            return;
        } catch (ApiException) {
            $this->feedback = 'Couldn\'t add the channel. Please try again.';

            return;
        }

        $this->values = [];
        $this->label = '';
        $this->replace("/channels/{$channel['id']}");
    }

    /**
     * The on-screen keyboard that suits a field.
     *
     * @param  array<string, mixed>  $field
     */
    public function keyboardFor(array $field): string
    {
        return match ($field['input']) {
            'email' => 'email',
            'url' => 'url',
            default => 'text',
        };
    }

    /**
     * @param  array<string, mixed>  $type
     * @return array<string, string>
     */
    private function validateValues(array $type): array
    {
        $errors = [];

        foreach (array_values($type['fields']) as $position => $field) {
            if ($field['required'] && trim($this->values[$position] ?? '') === '') {
                $errors[$field['key']] = "{$field['label']} is required.";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $type
     * @return array<string, mixed>
     */
    private function payload(array $type): array
    {
        $payload = ['channel_type' => $type['slug']];

        foreach (array_values($type['fields']) as $position => $field) {
            $value = trim($this->values[$position] ?? '');

            if ($value !== '') {
                $payload[$field['key']] = $value;
            }
        }

        if (trim($this->label) !== '') {
            $payload['label'] = trim($this->label);
        }

        return $payload;
    }

    /**
     * A message for a field this screen shows goes under it; any other goes beside the button.
     *
     * @param  array<string, mixed>  $type
     * @param  array<string, string>  $serverErrors
     * @return array<string, string>
     */
    private function mapServerErrors(array $type, array $serverErrors): array
    {
        $known = [...array_column($type['fields'], 'key'), 'label'];
        $errors = [];

        foreach ($serverErrors as $field => $message) {
            if (in_array($field, $known, true)) {
                $errors[$field] = $message;
            } else {
                $this->feedback ??= $message;
            }
        }

        return $errors;
    }

    private function signOutLocally(): void
    {
        app(SignOut::class)->clearLocal();
        $this->replace('/sign-in');
    }
}
