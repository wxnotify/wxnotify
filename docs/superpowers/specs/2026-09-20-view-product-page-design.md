# View Product Page (first pass) — Design

Source: master plan (`wxnotify-nextgen-plan.html`, FEAT "View Product page"): a public, unauthenticated page showing one product's full text and metadata, on its own subdomain behind Cloudflare caching, with the filename moved into the URL path for SEO.

## Goal

A public page at `/view-product/{slug}` for any ingested product, from either feed (NWWS or EMWIN), that alert messages, the short-link service and the dashboard's Active Alerts widget can link to.

## Scope

In: slug, route, page (text + metadata), caching headers, test-product handling, tests.
Out (later stages): polygon map, issuance-time radar snapshot (needs a pipeline capture step and B2 storage), the `wxn.io` shortener, links from alerts/widget, a sitemap, and the cross-feed dedup time-window fix (below).

## Decisions (user-approved)

- **One normalized slug regardless of source.** The plan's example (`kpah_fxus63-afdpah.251747_0000.7897.txt`) is an NWWS file name; EMWIN files are stored under content-hash names (npemwind renames them), so the original name is not available. The slug is built from the WMO header instead.
- Slug format: `{cccc}-{ttaaii}-{awipsid}-{yyyymmddhhmm}[-{bbb}]`, lowercase, `[a-z0-9-]` only. Examples: `kewx-fous54-pfwewx-202609200625`; correction `...-cca`; no AWIPS ID `kncf-wous99-202609200625`; no parseable header `nws-20260920062553-10122` (received time + row id).

## Slug

- Migration adds `ingested_products.view_slug` (string, unique, indexed) and backfills existing rows.
- `GenerateViewSlug` action builds it from the stored `cccc`, `ttaaii`, `awipsid`, `wmo_ddhhmm`. The correction marker (BBB) is not a stored column, so the action re-reads the header from `text` with the existing `ExtractWmoHeaderIdentity`.
- DDHHMM has no month/year (it recurs monthly), so the full timestamp is derived from `received_at`: same month, or the previous month when the header's day is later than the received day.
- If two products still yield the same slug, the row id is appended. Rows with no parseable header use the fallback form (which needs the row id, so it is set right after insert).
- Set once when a product is created (model `created` hook), so both feed controllers and the test-product generator get it without changes.
- 339 of ~10,100 current rows (all NWWS) have no `wmo_header_key`; these take the fallback form.

## Route

- `GET /view-product/{slug}`, route name `view-product.show`, handled by `ViewProductController`.
- Optional dedicated subdomain via `config('wxnotify.product_domain')` (`WXNOTIFY_PRODUCT_DOMAIN`). Unset (local dev, self-host) serves from the main domain.
- `IngestedProduct::viewUrl()` is the single place links are built.

## Page

- Standalone public Blade page (not Filament) with the WxNotify branding and a login link to `/dashboard`.
- Shows: catalog product name (`products.name` via `product_id`, falling back to the AWIPS ID), issuing office code, issued time (UTC), WMO header, AWIPS ID; VTEC details and headline when present; the product text in monospace.
- Product text is external content and is always HTML-escaped, never rendered raw.
- SEO: `<title>`, meta description, canonical link, `index` for real products.

## Caching

- Found: `Cache-Control: public, max-age=31536000, immutable` (content never changes once issued; Cloudflare caching rule is configuration, not code).
- Not found: short `max-age` so a link cannot stay cached as missing.

## Test products

- `is_test` in production: 404.
- Elsewhere: "TEST PRODUCT" banner and `noindex`.

## Testing

Slug: normal, correction marker, no AWIPS ID, no header, month rollover (header day later than received day), collision suffix; backfill. Route: 200 with expected content and headers, 404 for unknown slug, test-product rules in production vs non-production, product text escaped (a `<script>` in the text is not emitted), optional-domain routing.

## Known separate issue

`IngestOncePerWmoHeader` skips any product whose `wmo_header_key` already exists, with no time bound. Because DDHHMM recurs monthly, a legitimate product with the same header a month later would be dropped as a duplicate. The database currently spans about 9 hours, so nothing has hit this yet (first exposure about 2026-10-19). To be fixed as its own task (bound the check to a recent window).
