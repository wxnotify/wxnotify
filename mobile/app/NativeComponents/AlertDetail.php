<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Rejected;
use App\Exceptions\Api\Unauthenticated;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\AlertPresenter;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;

/**
 * One alert: what was sent, by which rule, over which channels, and how each delivery went.
 * An alert opened before is kept for offline use.
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
        } catch (Offline) {
            $this->offline = true;
            $this->message = $this->alert === null ? 'Connect to the internet to see this alert.' : null;
        } catch (Rejected) {
            $this->alert = null;
            $this->message = 'This alert is no longer available.';
        } catch (ApiException) {
            $this->message = $this->alert === null ? 'WxNotify could not load this alert. Go back and try again.' : null;
        }
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
            'failure' => 'Failed',
            'suppressed' => 'Held back',
            'skipped' => 'Skipped',
            default => ucfirst($status),
        };
    }

    public function sentAt(): string
    {
        $iso = $this->alert['dispatched_at'];

        return AlertPresenter::absolute($iso, $this->timezone) ?? AlertPresenter::when($iso);
    }
}
