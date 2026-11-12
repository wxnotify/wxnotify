# Mobile Product screen Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `Browser::inApp()` for WxNotify View Product pages with a native Product screen that paints at once, shows a loading placeholder, keeps viewed products on the phone, and offers "Open in browser".

**Architecture:** Backend: the web page's "Product details" lines move into a shared action, and a new `GET /api/v1/products/{slug}` returns the page's public fields. App (`../wxnotify-mobile`): a pushed native route `/view-product/{slug}` (`ProductView`) fetches that endpoint through `WxNotifyApi`, keeps up to 200 viewed products in a local table, and the five callers push it instead of opening the browser.

**Tech Stack:** Laravel 13 / Pest (backend, tests run in the app container); NativePHP v4 SuperNative `native:*` elements, SQLite on device (mobile repo, tests run locally).

**Spec:** `docs/superpowers/specs/2026-11-09-mobile-product-screen-design.md` (in this repo).

## Global Constraints

- Tasks 1 and 2 are in `<project root>`; Tasks 3 to 6 are in `<mobile repo root>`. Commit in the repo each task touches, directly on `main`. Do not push.
- Backend first, then the app. The endpoint is additive: an old app build keeps using the browser. No fallback to the browser from the new screen: a 404 shows "no longer available".
- A product is opened only when its address passes `ProductLinkTarget` (https, this server's site or a subdomain of its two-label domain, path `/view-product/{slug}` with `[a-z0-9-]+`, no query, credentials or port). Anything else opens nothing in the browser.
- The endpoint returns public fields only, answers 404 for an unknown slug, and 404 for `is_test` products when `app()->isProduction()` (no role exemption).
- Screens use native EDGE elements and theme tokens only (mobile `CLAUDE.md`): no web view, no emoji, no inline CSS, no hex colours. Never run `native:run`/`native:build`; tell the owner to. No new Composer or npm dependency. `native:validate` must stay clean.
- Marking an alert read stays with the callers (`AlertReadState::open()` / `openByRef()`); the Product screen never reads an alert.
- `Browser::auth` (sign-in), the SPC report links (`AlertDetail::openReportLink`) and the weather legal link (`HomeScreen::openWeatherLegal`) keep their browser calls; `Browser::open` (external) is new, for Open in browser.
- Backend PHP: run `vendor/bin/pint --dirty --format agent` in the container; tests `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php artisan test --compact <files>'`. Mobile: `php artisan test --compact <files>` and `vendor/bin/pint --dirty --format agent`. Record durable rules with the Boost `record-rule` tool (never by hand-editing `.ai/rules`).

## Review Focus

- A very long product text, and one with long unbroken lines: the screen scrolls, wraps, and does not crash or truncate silently (Task 5).
- A slug with unexpected characters, a look-alike host or another site's page: nothing is fetched and nothing opens (Tasks 3, 6).
- A test product on a production server and a slug that never existed: both "no longer available", no retry loop (Tasks 2, 5).
- Offline with a saved copy shows the copy; offline with none says to connect; a refused token signs out (Task 5).
- The same product opened twice quickly: one saved row, no duplicate fetch loop, the Inbox row still marked read (Tasks 4, 6).

---

### Task 1: Extract the product details lines (backend)

**Files:**
- Create: `app/Actions/Products/GetProductDetails.php`
- Modify: `app/Http/Controllers/ViewProductController.php` (its private `details()`/`format()` move out)
- Test: `tests/Feature/GetProductDetailsTest.php`; `tests/Feature/ViewProductPageTest.php` must stay green unchanged

**Interfaces:**
- Produces: `App\Actions\Products\GetProductDetails::__invoke(IngestedProduct $product): array<string, string>` returning the labelled lines the page shows today, in the same order and wording (Office, Issued, WMO header, AWIPS ID, Source, then VTEC, Valid from, Valid until when present, times as `Y-m-d H:i UTC`). Issued time comes from `GenerateViewSlug::issuedAt()`, falling back to `received_at`.

- [ ] **Step 1:** Write `GetProductDetailsTest` (Pest, feature): a full NWWS product with VTEC yields every label and value in order; a product with no office, WMO header or AWIPS ID leaves those labels out; source `emwin` reads `EMWIN`.
- [ ] **Step 2:** Run it; expect FAIL (class missing).
- [ ] **Step 3:** Create the action by moving the controller's `details()` and `format()` logic into it (constructor takes `GenerateViewSlug`); the controller calls the action for its `details` view variable. No change to the page's output.
- [ ] **Step 4:** Run `GetProductDetailsTest` and `ViewProductPageTest`; expect PASS. Run Pint.
- [ ] **Step 5:** Commit: `Product page: move the Product details lines into a shared action`.

### Task 2: `GET /api/v1/products/{slug}` (backend)

**Files:**
- Create: `app/Http/Resources/Api/V1/ProductResource.php`
- Modify: `app/Http/Controllers/Api/V1/ProductController.php` (add `show`), `routes/api.php`
- Test: `tests/Feature/ApiProductShowTest.php`

**Interfaces:**
- Consumes: `GetProductDetails` (Task 1); `IngestedProduct::displayTitle()`, `viewUrl()`, `view_slug`, `headline`, `text`, `is_test`.
- Produces: route `api.v1.products.show` = `GET /api/v1/products/{slug}` (`->where('slug', '[a-z0-9-]+')`) inside the existing `auth:sanctum` + `ability:app` + `ExtendAppTokenExpiry` + `throttle:app-api` group. Response `{data: {title: string, headline: ?string, text: string, is_test: bool, view_url: ?string, details: [{label: string, value: string}]}}`. 404 JSON for an unknown slug, and for `is_test` when `app()->isProduction()`.

- [ ] **Step 1:** Write `ApiProductShowTest` (model it on `ApiProductsTest`: user + `createToken('iPhone', ['app'])`): needs an app token (401 without, 403 with a `location` token); returns the documented shape for a product with headline and VTEC, with `details` as label/value pairs in page order; `headline` null when blank; unknown slug 404; a test product is 200 outside production and 404 in production (use `app()->instance('env', 'production')`, per `.ai/rules/feature.md`); a slug with upper-case or a slash is rejected (404) before any lookup.
- [ ] **Step 2:** Run it; expect FAIL.
- [ ] **Step 3:** Add `ProductController::show(string $slug, ...)` querying `IngestedProduct::with('product')->where('view_slug', $slug)->first()` (404 via `abort(404)`), `ProductResource` wrapping title (`displayTitle()`), headline, text, `is_test`, `view_url` (`viewUrl()`), and `details` (the action's map turned into a list of `{label, value}`). Register the route next to the other `/products` routes.
- [ ] **Step 4:** Run the test and `ApiProductsTest`; expect PASS. Run Pint. Record a short rule with `record-rule` (glob `app/Http/Controllers/Api/**`): the product endpoint mirrors the public page's 404 rule for test products and reuses `GetProductDetails` so the page and the app cannot drift.
- [ ] **Step 5:** Commit: `API: GET /products/{slug} returns a product's page content for the app`.

### Task 3: Product screen prototype wired to the Inbox (app)

**Files:**
- Create: `app/NativeComponents/ProductView.php`, `resources/views/native/product-view.blade.php`
- Modify: `routes/mobile.php`, `app/Services/WxNotifyApi.php`, `app/Support/ProductLinkTarget.php`, `app/NativeComponents/InboxScreen.php`
- Test: `tests/Feature/ProductViewTest.php`, `tests/Feature/ProductLinkTargetTest.php` (extend or create), `tests/Feature/InboxScreenTest.php` (the open-product test)

**Interfaces:**
- Consumes: the Task 2 endpoint.
- Produces:
  - `ProductLinkTarget::slugFrom(string $url): ?string` — the slug when `$url` is an https View Product page of this server (the same checks as `pageFrom()`, reusing them), else null.
  - `WxNotifyApi::product(string $slug): array` — GET `/api/v1/products/{slug}`, returns the `data` object; throws `Rejected` on 404 as `alert()` does; the slug is `rawurlencode`d.
  - Route `Route::native('/view-product/{slug}', ProductView::class)`; `ProductView` has `public ?array $product`, `public bool $loading`, `public bool $offline`, `public ?string $message`.
  - How the Inbox hands over: `InboxScreen::open()` calls `slugFrom($alert['product_url'])`; a slug navigates to `/view-product/{slug}` (still after `AlertReadState::open()` and `showAsRead()`); a null slug or no product URL opens the alert detail as it does for an alert with no product. The builder decides how a known title travels to the screen (route params come only from the path), and may drop it from this task if it needs more than a small helper.

- [ ] **Step 1:** Write tests: `slugFrom` accepts `https://wx.test/view-product/kbou-tor-202610121500` and a `products.wx.test` subdomain URL, and rejects the same cases `ProductLinkTest` rejects (look-alike host, http, query, credentials, port, upper-case slug, deeper path, another site); `ProductView` mounted with a slug and the API faked shows the product's title and text; a signed-out phone is replaced with `/sign-in`; tapping an Inbox row with a product URL navigates to `/view-product/{slug}` and opens no browser (`$this->browser->inAppUrls` stays empty), while a row with no product URL still opens the detail.
- [ ] **Step 2:** Run them; expect FAIL.
- [ ] **Step 3:** Implement the interfaces above. `ProductView::mount()` uses `RequiresSignIn`, validates the slug against `[a-z0-9-]+` before any request, sets `loading` while it fetches, then shows the content. The Blade view has `<native:top-bar title="Product" show-navigation-icon="true">`, a heading, and a placeholder (a `native:activity-indicator`, optionally grey bars) shown while `loading`; text in a scrollable `native:scroll-view`. Keep this task to loading plus the plain title and text; the other states are Task 5.
- [ ] **Step 4:** Run the new tests and `InboxScreenTest`; expect PASS. Run `php artisan native:validate` if available and Pint.
- [ ] **Step 5:** Commit: `Product screen: a native View Product page, opened from the Inbox`.
- [ ] **Gate (owner):** stop after this commit and report. Ask the owner to rebuild (`php artisan native:run ios`, and Android) after the backend is deployed (or against the dev server) and confirm tapping an Inbox product shows the Product screen's top bar and placeholder with no white screen. If a pushed native screen still flashes, do not continue to Tasks 4 to 6; bring the owner the options.

### Task 4: Saved copies of viewed products (app)

**Files:**
- Create: `database/migrations/<timestamp>_create_cached_products_table.php`, `app/Models/CachedProduct.php`
- Modify: `app/Support/LocalCache.php` (new methods, and `wipe()`)
- Test: `tests/Feature/LocalCacheProductsTest.php` (or the existing LocalCache test file)

**Interfaces:**
- Produces: table `cached_products` (`slug` string primary, `data` json, `viewed_at` timestamp); `LocalCache::rememberProduct(string $slug, array $product): void` (upserts and sets `viewed_at`, then deletes all but the 200 most recently viewed; the limit is the constant `LocalCache::MAX_CACHED_PRODUCTS`); `LocalCache::product(string $slug): ?array`; `wipe()` also deletes every `cached_products` row.

- [ ] **Step 1:** Write tests: a remembered product is returned by `product()`; remembering the same slug twice keeps one row and updates `viewed_at`; the 201st distinct product evicts the least recently viewed one; `product()` of an unknown slug is null; `wipe()` empties the table.
- [ ] **Step 2:** Run them; expect FAIL.
- [ ] **Step 3:** Create the migration (`make:migration`), the model (follow `CachedAlert`'s casts and `#[Fillable]`), and the `LocalCache` methods.
- [ ] **Step 4:** Run the tests and the existing `LocalCache`/`SignOut` tests; expect PASS. Pint.
- [ ] **Step 5:** Commit: `Product screen: keep the last 200 viewed products on the phone`.

### Task 5: Complete the Product screen (app)

**Files:**
- Modify: `app/NativeComponents/ProductView.php`, `resources/views/native/product-view.blade.php`, `tests/Fakes/RecordingBrowser.php`
- Test: `tests/Feature/ProductViewTest.php`

**Interfaces:**
- Consumes: `WxNotifyApi::product()`, `LocalCache::rememberProduct()/product()` (Task 4), `ProductLinkTarget::slugFrom()` (Task 3).
- Produces: `RecordingBrowser::open(string $url)` recording into `public array $openUrls`. `ProductView::openInBrowser(): void` calls `Browser::open($this->product['view_url'])` only when `slugFrom()` accepts that address.

- [ ] **Step 1:** Write failing tests in `ProductViewTest`: a saved copy shows at once and the screen then refreshes from the server (the saved row is replaced); offline with a saved copy shows it with a quiet offline note; offline with none says "Connect to the internet to see this product."; a 404 (`Rejected`) says "This product is no longer available." and makes no second request; any other API error shows a retry (`retry()` refetches); `Unauthenticated` signs out (`SignOut::clearLocal()`) and replaces with `/sign-in`; the details section lists the label/value lines and is collapsed until toggled; a headline shows only when present; a `is_test` product shows a TEST PRODUCT notice; a 200,000-character text with a 5,000-character unbroken line renders without error; Open in browser records the product's `view_url` in `$this->browser->openUrls`, and a `view_url` on another host records nothing.
- [ ] **Step 2:** Run them; expect FAIL.
- [ ] **Step 3:** Implement the states in `ProductView` and the view: top bar with an Open in browser action (`native:top-bar-action`, icon from the `App\Icons` enums), heading, headline, a collapsible Product details section (a toggle property like `HomeScreen::toggleWeatherDetails`), the monospace text in a scrollable element (use the `mono` font alias if `config/native-ui.php` has it, else add it there), and the message/retry states. Save each fetched product with `rememberProduct()`. If rendering a very long text in one element breaks the renderer, split it into line chunks of a size the builder measures.
- [ ] **Step 4:** Run `ProductViewTest`; expect PASS. Pint, `native:validate`.
- [ ] **Step 5:** Commit: `Product screen: details, saved copies, offline and error states, Open in browser`.

### Task 6: Move the other four callers and the push tap (app)

**Files:**
- Modify: `app/NativeComponents/HomeScreen.php` (`openActive`), `app/NativeComponents/AlertDetail.php` (`openProduct`), `app/NativeComponents/ProductsScreen.php` (`open`), `app/NativeComponents/ProductLink.php` (`mount`), `tests/Feature/NativePluginsTest.php` (the Browser entry's wording)
- Test: `tests/Feature/HomeScreenTest.php`, `AlertDetailTest.php`, `ProductsScreenTest.php`, `ProductLinkTest.php`, `InboxScreenTest.php` (update every test that asserted `$this->browser->inAppUrls` for a product page)

**Interfaces:**
- Consumes: `ProductLinkTarget::slugFrom()` and the `/view-product/{slug}` route.
- Produces: each caller navigates to `/view-product/{slug}` when `slugFrom()` accepts its address and does nothing in the browser. A null slug does nothing (Home, Products) or opens the detail (Inbox, Task 3). `ProductLink::mount()` keeps `replace('/inbox')`, then pushes the Product screen over it when the pushed link's page yields a slug, then still calls `AlertReadState::openByRef($ref)` best-effort exactly as before; a signed-out phone, or a link that is not a View Product page of this server, still just shows the Inbox.

- [ ] **Step 1:** Update the tests: each caller now navigates to `/view-product/{slug}` and `$this->browser->inAppUrls` stays empty; `ProductLinkTest` keeps every rejection case and asserts the Inbox replace plus the Product navigation (and `openByRef` still runs for a link with a ref); the two-phone, malformed-ref and signed-out cases keep their current expectations. Add one test that a view URL on another host navigates nowhere.
- [ ] **Step 2:** Run them; expect FAIL.
- [ ] **Step 3:** Change the four callers and `ProductLink`. Leave `openReportLink`, `openWeatherLegal` and sign-in on the browser. Update the `NativePluginsTest` entry text to say sign-in handoff, SPC report links, the weather legal link and Open in browser.
- [ ] **Step 4:** Run the full mobile suite (`php artisan test --compact`) and Pint; expect PASS. Confirm `grep -rn "inApp(" app` lists only the SPC report link and weather legal link.
- [ ] **Step 5:** Commit: `Product screen: Home, Products, alert detail and push taps open it instead of the browser`. Record a rule with `record-rule` if the mobile repo has `.ai/rules` (a View Product page opens only through `ProductLinkTarget::slugFrom()` and the Product screen).
- [ ] **Step 6:** Update the owner's memory notes only if asked; report the deploy order: backend first (new endpoint), then the app, which needs a native rebuild (`native:run` on both platforms); the new table is created by the migration on first launch.
