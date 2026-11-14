# WxNotify EMWIN ByteBlaster client

Receives NOAA's EMWIN (Emergency Managers Weather Information Network) broadcast over the ByteBlaster protocol and forwards each product to WxNotify's pipeline API through an authenticated webhook. It is one feed of the WxNotify stack; the application (`wxnotify/wxnotify`) does the matching and delivery.

The receiver is [npemwin](https://bitbucket.org/noaaport/npemwin), built from source in this image from a pinned upstream commit (its checksum is verified at build time).

## Quick start

Use the Compose files in the repository, which start this image beside the application and the other feeds: [`deploy/single-server`](https://github.com/wxnotify/wxnotify/tree/main/deploy/single-server). The full guide is [`deploy/README.md`](https://github.com/wxnotify/wxnotify/tree/main/deploy/README.md).

## Configuration

| Variable | Purpose |
| --- | --- |
| `EMWIN_WEBHOOK_URL` | WxNotify's pipeline endpoint that receives each product. |
| `PIPELINE_WEBHOOK_TOKEN` | The bearer token shared with the application (required). |
| `EMWIN_SERVERS_REFRESH_HOURS` | How often the list of EMWIN servers is refreshed (default 6). |
| `EMWIN_MAX_UNZIP_BYTES` | Upper limit for unpacking a compressed product (default 5242880). |
| `EMWIN_RETENTION_DAYS` | How long received products are kept (default 7). |

The image exposes port 8016 and has a built-in health check.

## Tags

Every release publishes all five WxNotify images with the same version tag, for `linux/amd64` and `linux/arm64`. A release such as `v1.2.3` is tagged `1.2.3`, `1.2`, `1` and `latest`; a pre-release such as `v1.2.3-rc.1` gets only its full version. Pin an exact version (`wxnotify/emwin-byteblaster:1.2.3`) and change it when you choose to upgrade.

## Source and licence

Source, issues and the full documentation: [https://github.com/wxnotify/wxnotify](https://github.com/wxnotify/wxnotify). WxNotify is licensed under GPL-3.0-or-later.

npemwin is licensed under the ISC licence, Copyright (c) 2004-2012 Jose F. Nieves.
