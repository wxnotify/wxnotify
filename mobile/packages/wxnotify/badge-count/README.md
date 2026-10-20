# wxnotify/badge-count

A local NativePHP Mobile plugin for the WxNotify app. It sets the iOS app icon badge number, which the
installed firebase and local-notifications plugins can only clear.

```php
use Wxnotify\BadgeCount\Facades\BadgeCount;

BadgeCount::set(3); // iOS: the icon shows 3; 0 clears it. Android: no-op, answers success.
```

The app calls it only through `App\Services\AppBadge`, which clamps negatives and never throws.
Native code changes need a native rebuild of the app.
