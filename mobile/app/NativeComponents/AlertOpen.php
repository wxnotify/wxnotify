<?php

namespace App\NativeComponents;

use App\Services\AlertReadState;
use App\Support\TokenStore;
use Native\Mobile\Edge\NativeComponent;

/**
 * Where a tapped push with no product page lands (wxnotify://alert/{ref}: a daily summary, a threshold or lightning
 * alert): the read is only queued (AlertReadState::queueRef(), a malformed reference ignored) and the screen replaces
 * itself with Alerts at once, so a slow server never holds the tap on a spinner; Alerts' feed load replays the queued
 * read before it fetches the list, so the list it shows already has the alert read. A signed-out phone just goes to
 * Alerts, which asks it to sign in.
 */
class AlertOpen extends NativeComponent
{
    public function mount(): void
    {
        $ref = $this->param('ref');

        if (is_string($ref) && (new TokenStore)->token() !== null) {
            app(AlertReadState::class)->queueRef($ref);
        }

        $this->replace('/');
    }
}
