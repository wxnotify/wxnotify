---
paths:
  - 'app/Filament/Dashboard/Pages/Auth/**'
---

# Auth

## The sign-up Turnstile captcha is optional only on self-hosted installs
Register skips the Turnstile field only when WXNOTIFY_MODE is `selfhost` AND both turnstile.turnstile_site_key and turnstile_secret_key are blank, so a self-hosted install without Cloudflare keys can still sign people up. With either key set the check still applies, so a half-configured install fails loudly. In `cloud` mode (the default, and the hosted service) the captcha is always required, so a missing key is an error and never silently disables bot protection. Tests that need the captcha rely on the keys in phpunit.xml; a test of the no-captcha path must set `wxnotify.mode` to `selfhost` and null both keys with config().
