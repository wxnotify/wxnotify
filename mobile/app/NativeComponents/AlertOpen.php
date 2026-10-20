<?php

namespace App\NativeComponents;

use App\Services\AlertReadState;
use App\Support\TokenStore;
use Native\Mobile\Edge\NativeComponent;

/**
 * Where a tapped push with no product page lands (wxnotify://alert/{ref}: a daily summary, a threshold or lightning
 * alert): the alert the reference names is read (AlertReadState::openByRef(): best-effort, short timeout, queued when
 * it fails, a malformed reference ignored), then the screen replaces itself with Alerts. Renders nothing. A
 * signed-out phone just goes to Alerts, which asks it to sign in.
 */
class AlertOpen extends NativeComponent
{
    public function mount(): void
    {
        $ref = $this->param('ref');

        if (is_string($ref) && (new TokenStore)->token() !== null) {
            app(AlertReadState::class)->openByRef($ref);
        }

        $this->replace('/');
    }
}
