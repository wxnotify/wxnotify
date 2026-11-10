# Mobile Product screen (replacing the in-app browser for View Product) — design

## Why

Tapping a product in the app (an Inbox row, a push notification) opens the public View Product web page with `Browser::inApp()` (the `nativephp/browser-plugin`). The owner dislikes what happens first: an all-white screen shows for a moment before the page appears. A screen drawn by the app itself can appear at once, in the app's own look, and fill in when the text arrives.

## What I checked in the code

- **The app opens a product page from five places**, all with `Browser::inApp($url)`: Home's current-alerts rows (`HomeScreen::openActive`, the event's `view_url`), the Inbox (`InboxScreen::open`, the row's `product_url`), the alert detail ("View full product", `AlertDetail::openProduct`), the Products tab (`ProductsScreen::open`, the result's `view_url`) and a tapped push (`ProductLink::mount`, from the `wxnotify://product/{link}[/{ref}]` link, which `ProductLinkTarget::pageFrom()` turns into an https address after checking it is a View Product page of this server). Sign-in uses `Browser::auth` and the Outlook report's SPC links use `Browser::inApp` on external pages; neither is a WxNotify product.
- **The public page** (`ViewProductController`, `resources/views/view-product/show.blade.php`) shows: a TEST PRODUCT notice for test products, the title, the headline when there is one, a collapsible "Product details" section (Office, Issued, WMO header, AWIPS ID, Source) and the product text in a monospace block. Pages are cached forever because a stored product never changes; a missing product is a 404.
- **Every address is `{host}/view-product/{slug}`.** The slug is the only thing the app has for a product. The API has no endpoint for one product (`GET /api/v1/products` is the archive search and never returns the text).
- **The app's native toolkit has `native:activity-indicator`** (a spinner, sizes sm/md/lg) and a determinate progress bar; it has no skeleton or shimmer element.

## What the owner decided

- Replace the in-app browser with a native Product screen **everywhere a WxNotify product is opened** (the five places above).
- While the text loads, show a **placeholder with a loading animation**.
- The screen mirrors the web page: title, headline, collapsible Product details, product text (scrollable, selectable), and a TEST PRODUCT notice for test products.
- An **Open in browser** button in the top bar opens the public page in the phone's own browser, so a subscriber can share or print it.
- Closing the screen returns to where the subscriber was; a push tap lands on the Inbox with the product open over it, as it does now. Marking alerts read works as it does today.
- Offline with nothing saved says to connect to the internet; a product that no longer exists says so; other errors offer a retry.
- SPC report links and sign-in keep the browser plugin.

## My calls

- **Backend:** a new `GET /api/v1/products/{slug}` behind the same app-token sign-in as the rest of the API, returning `{data: {title, headline, text, is_test, view_url, details: [{label, value}...]}}` (public fields, the same ones the web page shows) and 404 for an unknown slug. A test product is 404 in production, as on the web page. Eloquent resource and API versioning follow the existing `Api\V1` pattern.
- **Phone cache:** a viewed product is saved on the phone (a stored product never changes) and shown from the saved copy at once, offline too, without waiting for a fetch. The saved copies are bounded (oldest dropped past a limit the builder picks).
- **What shows before the text arrives:** the screen opens with its top bar and the placeholder. When the caller already knows the title (a Products row, an Inbox subject, an active event's name), the screen shows it straight away as the heading, above the placeholder for the rest.
- **Open in browser** uses the browser plugin's external open (`Browser::open`), so the plugin stays installed.
- **The web page's `view_url` is the address of record.** The app derives the slug from it with the same checks `ProductLinkTarget` makes today (https, this server's site, `/view-product/{slug}` path only); anything else shows the error state, not a browser.

## Settled after the builder check

- **Navigation (my call, to be proved first):** the Product screen is an ordinary pushed native route, like `/alerts/{id}` (`AlertDetail`), so Back returns to the caller. The first task of the plan is a small prototype that proves the screen's own top bar paints at once on a tap on a device or simulator, before the other four callers are moved. If a pushed screen still flashes, the plan stops there and comes back to the owner.
- **Push tap (answered from the code):** `{link}` in `wxnotify://product/{link}[/{ref}]` is the base64url of the public page address, not the slug, so the slug is taken from the address `ProductLinkTarget::pageFrom()` returns (its checks stay as they are). Marking the alert read stays with the callers as today: `AlertReadState::openByRef()` / the Inbox's `open()` run beside the navigation, never inside the Product screen, which only shows a product. `ProductLink` keeps `replace('/inbox')` and then pushes the Product screen over it instead of calling the browser.
- **Test products (answered):** the web page returns 404 for `is_test` only when `app()->isProduction()`. The endpoint applies the same rule with no role exemption, so the TEST PRODUCT notice shows only on a non-production server.
- **Endpoint placement (answered):** inside the existing `auth:sanctum` + `ability:app` + `ExtendAppTokenExpiry` + `throttle:app-api` group in `routes/api.php`. The web page builds its Product details lines inside `ViewProductController::details()`; the builder extracts that into a shared action so the page and the endpoint cannot drift apart, and the endpoint returns the details as the same labelled lines the page shows (including VTEC, Valid from, Valid until when present) rather than a fixed set of fields.
- **Saved copies (my call):** a table in the app's local database (like `cached_alerts`, with a migration in the mobile repo), keyed by slug, public data only so it is not user-scoped, bounded to the most recent 200 products. Signing out clears it with the other cached data.
- **Old server (my call):** no fallback to the browser. Deploy order is the protection, as with every earlier slice: backend first, then the app build. A 404 shows "no longer available".

## Goals

1. Tapping a product never shows a white screen: the Product screen's own top bar and placeholder appear on the tap.
2. A product seen once opens instantly afterwards, including offline.
3. The five entry points all go through one screen.

## Non-goals and anti-goals

- No change to the public web page or its caching.
- No editing, sharing sheet, search inside the text, or syntax colouring of the text.
- No removal of `nativephp/browser-plugin` (sign-in and Open in browser still use it).
- Must not open an address that is not a View Product page of this server.
- Must not leak test products into production.

## Constraints

- SuperNative (NativePHP v4) screens with EDGE `native:*` elements; app rules in the mobile repo's `CLAUDE.md` and `native:validate` clean. No emoji unless the owner allows it.
- Backend first, then the app; an old app build keeps using the browser, so the endpoint is additive.
- No new Composer or npm dependency without approval.
- Text can be long (a full forecast discussion): the screen scrolls and does not render it in one huge element if that breaks the native renderer (builder to measure).

## Left to the builder

The route name (`/product/{slug}` or similar), how the slug travels from each caller, the saved-copy store and its size limit, whether the placeholder is grey pulsing bars, a spinner, or both (an activity indicator exists; a pulse needs measuring), the exact wording of the error and offline states, how the Details section collapses, how a test product notice looks, and the tests.

## Edge cases the design must cover

- A product with no headline, no office, no WMO header or no AWIPS ID: those lines are left out.
- A very long product text, and text with long unbroken lines (wrap, do not scroll sideways, or scroll if wrapping breaks the layout).
- A slug with unexpected characters or a link on another host: error state, nothing opened.
- A push tap while signed out: the Inbox (sign-in) as today, no Product screen.
- The product arrives while the subscriber has already backed out: nothing happens.
- The same product opened twice quickly, or the Inbox row's alert marked read while the screen loads: no duplicate fetch, no lost read.
- Offline with a saved copy: the saved copy shows, with a quiet offline note.
- 404 (never existed, a test product in production): "This product is no longer available", no retry loop.
