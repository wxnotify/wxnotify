<?php

namespace App\NativeComponents;

use App\Support\ProductLinkTarget;
use App\Support\TokenStore;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;

/**
 * Where a tapped push notification about a product lands (wxnotify://product/{link}): the Alerts screen,
 * with the product's View Product page opened over it, so closing the page leaves the subscriber on Alerts.
 * A link that is not a View Product page of this server, or a signed-out phone, just shows Alerts.
 */
class ProductLink extends NativeComponent
{
    public function mount(): void
    {
        $page = ProductLinkTarget::pageFrom((string) $this->param('link'));

        $this->replace('/');

        if ($page !== null && (new TokenStore)->token() !== null) {
            Browser::inApp($page);
        }
    }
}
