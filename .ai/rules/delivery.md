---
paths:
  - 'app/Support/Delivery/**'
---

# Delivery

## Channel delivery goes through ChannelDispatcher; subscriber-controlled URLs go through WebhookUrlGuard and never follow redirects
Every send to a subscriber NotificationChannel must go through ChannelDispatcher (SendActivityAlert is the one exception: it sends to an admin-set ActivityAlertRule::apprise_target). Any URL a subscriber controls — webhook URLs and Web Push endpoints — must pass WebhookUrlGuard both when saved and again at send time, and the HTTP client used to call it must NOT follow redirects (Guzzle follows up to 5 by default and would let a public https URL 302 the server to a private/metadata address). Webhooks get this via config('webhook-server.webhook_job') = NoRedirectCallWebhookJob; restoring Spatie's CallWebhookJob reopens the hole. Web Push builds its own Guzzle client with allow_redirects=false and treats any non-2xx (including 3xx) as failure. Never put an exception message, response body, endpoint URL or key material in a DeliveryResult error: build it from fixed strings plus the numeric HTTP status. apprise_url and config are encrypted and must not be logged. New channel types need a channel_types migration AND a ChannelTypeSeeder::SHIPPED entry in matching sort order. Known open item: the guard's DNS resolution and the HTTP client's own resolution are separate (TOCTOU); pinning via CURLOPT_RESOLVE in the webhook_job subclass is the follow-up.
