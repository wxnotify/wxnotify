<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Rejected;
use App\Exceptions\Api\Unauthenticated;
use App\Services\AlertReadState;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\AlertPresenter;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;

/**
 * One alert: what was sent, by which rule, and to which of the subscriber's phones.
 * An alert opened before is kept for offline use. Opening it reads it (also offline, or from a deep
 * link); an alert the server no longer has, or a refused token, reads nothing.
 */
class AlertDetail extends NativeComponent
{
    use RequiresSignIn;

    /** @var array<string, mixed>|null */
    public ?array $alert = null;

    public bool $offline = false;

    public ?string $message = null;

    public ?string $timezone = null;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $id = (int) $this->param('id');
        $cache = app(LocalCache::class);

        $this->timezone = $cache->profile()['timezone'] ?? null;
        $this->alert = $cache->detail($id);

        try {
            $this->alert = app(WxNotifyApi::class)->alert($id);
            $cache->storeDetail($this->alert);
            $this->offline = false;
        } catch (Unauthenticated) {
            app(SignOut::class)->clearLocal();
            $this->replace('/sign-in');

            return;
        } catch (Offline) {
            $this->offline = true;
            $this->message = $this->alert === null ? 'Connect to the internet to see this alert.' : null;
        } catch (Rejected) {
            $this->alert = null;
            $this->message = 'This alert is no longer available.';

            return;
        } catch (ApiException) {
            $this->message = $this->alert === null ? 'WxNotify could not load this alert. Go back and try again.' : null;
        }

        app(AlertReadState::class)->open($id);
    }

    public function openProduct(): void
    {
        if (filled($this->alert['product_url'] ?? null)) {
            Browser::inApp($this->alert['product_url']);
        }
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'success' => 'Delivered',
            'suppressed' => 'Held back',
            'skipped' => 'Skipped',
            default => ucfirst($status),
        };
    }

    public function categoryAndSentAt(): string
    {
        return AlertPresenter::withCategory($this->alert, $this->sentAt());
    }

    public function sentAt(): string
    {
        $iso = $this->alert['dispatched_at'];

        return AlertPresenter::absolute($iso, $this->timezone) ?? AlertPresenter::when($iso);
    }
}
