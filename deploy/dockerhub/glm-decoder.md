# WxNotify GLM decoder

Turns GOES lightning data into alert-ready flashes. It polls NOAA's public, anonymously readable GOES Geostationary Lightning Mapper (GLM) Level 2 buckets for new flash files, decodes each one and posts the flashes to WxNotify's pipeline API through an authenticated webhook.

It is one feed of the WxNotify stack; the application (`wxnotify/wxnotify`) matches flashes against people's locations and sends the alerts. No account is needed to read the GLM data.

## Quick start

Use the Compose files in the repository, which start this image beside the application and the other feeds: [`deploy/single-server`](https://github.com/wxnotify/wxnotify/tree/main/deploy/single-server). The full guide is [`deploy/README.md`](https://github.com/wxnotify/wxnotify/tree/main/deploy/README.md).

## Configuration

| Variable | Purpose |
| --- | --- |
| `GLM_WEBHOOK_URL` | WxNotify's pipeline endpoint that receives the decoded flashes. |
| `PIPELINE_WEBHOOK_TOKEN` | The bearer token shared with the application (required). |
| `GLM_BUCKETS` | The NOAA GOES buckets to read (default `noaa-goes19,noaa-goes18`). |
| `GLM_POLL_INTERVAL_SECONDS` | How often to look for new files (default 30). |
| `GLM_STATE_FILE` | Where it remembers processed files (default `/state/processed_keys.json`; mount a volume at `/state`). |
| `GLM_LOG_LEVEL` | Log verbosity (default `INFO`). |

## Tags

Every release publishes all five WxNotify images with the same version tag, for `linux/amd64` and `linux/arm64`. A release such as `v1.2.3` is tagged `1.2.3`, `1.2`, `1` and `latest`; a pre-release such as `v1.2.3-rc.1` gets only its full version. Pin an exact version (`wxnotify/glm-decoder:1.2.3`) and change it when you choose to upgrade.

## Source and licence

Source, issues and the full documentation: [https://github.com/wxnotify/wxnotify](https://github.com/wxnotify/wxnotify). WxNotify is licensed under GPL-3.0-or-later.
