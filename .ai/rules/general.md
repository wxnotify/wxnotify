---
paths:
  - compose.yaml
---

# General

## reverb service: server-a, host port 8085, workers broadcast over REVERB_HOST
Reverb listens on 8080 inside its container and is published on host 8085 because 8080 is the front end. Queue workers reach it at REVERB_HOST/PORT (compose name `reverb` works on one host only). Run `php artisan reverb:restart` and `queue:restart` on each deploy. `REVERB_ALLOWED_ORIGINS` (comma list) defaults to the APP_URL host; production sets the real hostnames.
