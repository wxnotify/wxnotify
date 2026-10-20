<?php

namespace App\NativeComponents;

use App\Services\AlertReadState;
use App\Support\ProductLinkTarget;
use App\Support\TokenStore;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;

/**
 * Where a tapped push notification about a product lands (wxnotify://product/{link}, or .../{link}/{ref} from a
 * backend that knows this build reads the alert on tap): the Alerts screen, with the product's View Product page
 * opened over it, so closing the page leaves the subscriber on Alerts. The page opens first; the alert the reference
 * names is then read best-effort (AlertReadState::openByRef(), which ignores a malformed reference and never throws),
 * so a slow or unreachable server never holds the page up. A link that is not a View Product page of this server, or
 * a signed-out phone, just shows Alerts.
 */
class ProductLink extends NativeComponent
{
    public function mount(): void
    {
        $page = ProductLinkTarget::pageFrom((string) $this->param('link'));
        $ref = $this->param('ref');

        $this->replace('/');

        if ((new TokenStore)->token() === null) {
            return;
        }

        if ($page !== null) {
            Browser::inApp($page);
        }

        if (is_string($ref) && $ref !== '') {
            app(AlertReadState::class)->openByRef($ref);
        }
    }
}
