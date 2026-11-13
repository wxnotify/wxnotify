---
paths:
  - 'app/Filament/Dashboard/Pages/Auth/**'
---

# Auth

## The sign-up Turnstile captcha is optional
Register shows the Turnstile field only when at least one of turnstile.turnstile_site_key / turnstile_secret_key is set. With BOTH blank, a self-hosted install signs people up without a captcha. With only one set the check still applies on purpose, so a half-configured install fails loudly instead of silently running without bot protection. Tests that need the captcha rely on the keys in phpunit.xml; a test of the no-captcha path must null both keys with config().
