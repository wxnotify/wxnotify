# WxNotify NWWS-OI client

A small client for NOAA's NWWS-OI (NOAA Weather Wire Service). It joins the NWWS-OI chat room, receives each National Weather Service text product as it is broadcast, saves it to disk and notifies WxNotify's pipeline API through an authenticated webhook.

This image is one feed of the WxNotify stack. On its own it only receives and stores products; the WxNotify application (`wxnotify/wxnotify`) does the matching and delivery.

## You need your own NWWS-OI account

NWWS-OI access is free but requires registering with the National Weather Service: [request NWWS-OI credentials](https://www.weather.gov/nwws/nwws_oi_request). Without credentials, leave the username and password empty and the stack simply runs without this feed.

## Quick start

Use the Compose files in the repository, which start this image beside the application and the other feeds: [`deploy/single-server`](https://github.com/wxnotify/wxnotify/tree/main/deploy/single-server). The full guide is [`deploy/README.md`](https://github.com/wxnotify/wxnotify/tree/main/deploy/README.md).

## Configuration

| Variable | Purpose |
| --- | --- |
| `NWWS_USERNAME`, `NWWS_PASSWORD` | Your NWWS-OI account. |
| `NWWS_WEBHOOK_URL` | WxNotify's pipeline endpoint that receives each product. |
| `PIPELINE_WEBHOOK_TOKEN` | The bearer token shared with the application (required). |
| `NWWS_ARCHIVEDIR` | Where products are written inside the container (mount a volume here). |
| `NWWS_RETRY` | Reconnect automatically if the connection drops. |
| `NWWS_RETENTION_DAYS` | How long saved products are kept before the hourly cleanup removes them (default 7). |

## Tags

Every release publishes all five WxNotify images with the same version tag, for `linux/amd64` and `linux/arm64`. A release such as `v1.2.3` is tagged `1.2.3`, `1.2`, `1` and `latest`; a pre-release such as `v1.2.3-rc.1` gets only its full version. Pin an exact version (`wxnotify/nwws:1.2.3`) and change it when you choose to upgrade.

## Source and licence

Source, issues and the full documentation: [https://github.com/wxnotify/wxnotify](https://github.com/wxnotify/wxnotify). WxNotify is licensed under GPL-3.0-or-later.

The NWWS-OI client is a lightly patched fork of the MIT-licensed [nwws-go-client](https://github.com/jbuitt/nwws-go-client) by jbuitt.
