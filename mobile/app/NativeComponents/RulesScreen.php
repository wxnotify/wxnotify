<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Maintenance;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Unauthenticated;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\Concerns\HandlesPushToken;
use App\Support\Concerns\OpensSettingsMenu;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use Native\Mobile\Edge\NativeComponent;

/**
 * The subscriber's alert rules: a list with an on/off switch per row, an add button and a tap to
 * edit. Paints from the cache first and refreshes; offline it keeps the last copy with a note.
 */
class RulesScreen extends NativeComponent
{
    use HandlesPushToken;
    use OpensSettingsMenu;
    use RequiresSignIn;

    /** @var array<int, array<string, mixed>> */
    public array $rules = [];

    public bool $offline = false;

    /** Whether the list on screen is known (remembered or just loaded): only then can it be "empty". */
    public bool $loaded = false;

    public ?string $error = null;

    public ?string $message = null;

    protected function settingsPage(): ?string
    {
        return '/rules';
    }

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $cached = app(LocalCache::class)->rules();
        $this->rules = $cached ?? [];
        $this->loaded = $cached !== null;

        $this->refresh();
    }

    /**
     * Back from the editor (saved, deleted or abandoned): the list may have changed.
     */
    public function onResume(): void
    {
        $this->refresh();
    }

    /**
     * Offline or failing with nothing remembered leaves the list unknown, so no "no alert rules yet".
     */
    public function refresh(): void
    {
        $this->message = null;

        try {
            $this->rules = app(WxNotifyApi::class)->alertRules();
            app(LocalCache::class)->rememberRules($this->rules);
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
            $this->error = 'WxNotify could not load your alert rules. Try again in a moment.';
        }
    }

    /**
     * The switch flips at once; a refusal puts it back and says why.
     */
    public function setActive(int $id, bool $value): void
    {
        $index = $this->indexOf($id);

        if ($index === null) {
            return;
        }

        $this->message = null;
        $this->rules[$index]['is_active'] = $value;

        try {
            $this->rules[$index] = app(WxNotifyApi::class)->setAlertRuleActive($id, $value);
            app(LocalCache::class)->rememberRules($this->rules);
        } catch (Unauthenticated) {
            app(SignOut::class)->clearLocal();
            $this->replace('/sign-in');
        } catch (Maintenance $e) {
            $this->rules[$index]['is_active'] = ! $value;
            $this->message = $e->getMessage();
        } catch (Offline) {
            $this->rules[$index]['is_active'] = ! $value;
            $this->message = 'You\'re offline. Reconnect to change this alert.';
        } catch (ApiException) {
            $this->rules[$index]['is_active'] = ! $value;
            $this->message = 'Couldn\'t update that alert. Please try again.';
        }
    }

    public function create(): void
    {
        $this->navigate('/rules/new');
    }

    public function open(int $id): void
    {
        $this->navigate("/rules/{$id}");
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    public function subtitle(array $rule): string
    {
        return $rule['is_active'] ? (string) $rule['category_label'] : $rule['category_label'].' · Off';
    }

    private function indexOf(int $id): ?int
    {
        foreach ($this->rules as $index => $rule) {
            if ($rule['id'] === $id) {
                return $index;
            }
        }

        return null;
    }
}
