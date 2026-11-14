# WxNotify EMWIN HTTP poller

Fetches EMWIN text products over HTTP from the National Weather Service and forwards them to WxNotify's pipeline API through an authenticated webhook. It is a second route to the same products as the ByteBlaster client (`wxnotify/emwin-byteblaster`), useful as a backup: WxNotify keeps only one copy of a product that arrives on more than one feed.

It is one feed of the WxNotify stack; the application (`wxnotify/wxnotify`) does the matching and delivery.

## Quick start

Use the Compose files in the repository, which start this image beside the application and the other feeds: [`deploy/single-server`](https://github.com/wxnotify/wxnotify/tree/main/deploy/single-server). The full guide is [`deploy/README.md`](https://github.com/wxnotify/wxnotify/tree/main/deploy/README.md).

## Configuration

| Variable | Purpose |
| --- | --- |
| `EMWIN_WEBHOOK_URL` | WxNotify's pipeline endpoint that receives each product. |
| `PIPELINE_WEBHOOK_TOKEN` | The bearer token shared with the application (required). |
| `EMWIN_HTTP_STATE_DIR` | Where the poller remembers what it has already sent (mount a volume here). |
| `EMWIN_RETENTION_DAYS` | How long downloaded products are kept (default 7). |

The source and what is downloaded (text products by default) are set in `/config/config.json` inside the image. A health check is built in.

## Tags

Every release publishes all five WxNotify images with the same version tag, for `linux/amd64` and `linux/arm64`. A release such as `v1.2.3` is tagged `1.2.3`, `1.2`, `1` and `latest`; a pre-release such as `v1.2.3-rc.1` gets only its full version. Pin an exact version (`wxnotify/emwin-http:1.2.3`) and change it when you choose to upgrade.

## Source and licence

Source, issues and the full documentation: [https://github.com/wxnotify/wxnotify](https://github.com/wxnotify/wxnotify). WxNotify is licensed under GPL-3.0-or-later.
