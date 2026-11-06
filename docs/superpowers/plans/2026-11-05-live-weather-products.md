# Live Weather Products Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A **Products** page in the dashboard's "Live Weather" group: a live list of incoming products pushed over WebSockets (Laravel Reverb) instead of a table that reloads on a timer.

**Architecture:** When the pipeline has processed a product, it broadcasts a small `ProductIngested` event on a private channel (`live-products`) that any signed-in subscriber may join. A Filament page with a Livewire listener loads the latest 50 products, prepends each broadcast one, keeps at most 100, and falls back to a once-a-minute poll (plus a gap fill by id on reconnect) when the socket is down. Laravel Echo connects to Reverb only on this page.

**Tech Stack:** Laravel 13 / PHP 8.4 / Filament v5 / Livewire / `laravel/reverb` (Composer), `laravel-echo` and `pusher-js` (npm) — **the owner approved installing exactly these on 2026-11-05** / Pest.

**Spec:** `docs/superpowers/specs/2026-11-05-live-weather-pages-design.md`

**Order:** Do this plan after the Map plan (`docs/superpowers/plans/2026-11-05-live-weather-map.md`) has created the "Live Weather" group; the Products page joins it (sort 2). If it is built first, it creates the group and the Map plan reuses it.

## Global Constraints

- Read `.ai/rules/index.md` and the rule files covering your paths (at least `pipeline.md`, `app-jobs.md`, `filament.md`, `pages.md`, `jobs.md`, `feature.md`). `.ai/rules` is written only by the controller through `record-rule`.
- Backend tests run in the app container (start it with `docker compose up -d front-end pgsql redis` if down): `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && DB_HOST=pgsql php artisan test --compact <files>'`; Pint: `docker exec wxnotify-nextgen-front-end-1 bash -c 'cd /var/www/html && vendor/bin/pint --dirty --format agent'`. Create files with `php artisan make:* --no-interaction`; read the `testing-best-practices` skill; Pest helper functions need file-prefixed names; TDD with RED/GREEN evidence. Tests never need a Reverb server (`BROADCAST_CONNECTION` is `null` or `log` under test).
- Commit directly on `main`, one commit per task, message ending with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Never push.
- **Dependencies:** only `laravel/reverb`, `laravel-echo` and `pusher-js` may be added. No other package. Do not start, stop or rebuild containers other than a new `reverb` service you add and the ones in `docker compose up -d front-end pgsql redis`.
- **Secrets:** `REVERB_APP_SECRET` and the like never appear in the repository; `.env.example` holds placeholders only; the browser receives only the app key, host, port and scheme.
- **Ingestion must not suffer:** broadcasting is queued; any failure to broadcast is logged (exception class only) and never thrown out of `ProcessIngestedProduct`.
- Test products (`is_test`) and replays (`replay_of_id`) are never broadcast or listed. The payload carries no raw product text.
- Text from products (names, headlines, office) is rendered escaped, never as HTML.
- Every signed-in tier may use the page; the channel authorizes any authenticated user and no guest.

## Review Focus

Inputs and conditions the spec implies that no happy-path test would cover, most likely first (each gets a test in the owning task):

1. The broadcaster is down or misconfigured: product ingestion still completes and logs only the exception class (Task 2).
2. A test product or a replay: never broadcast, never listed, not returned by the page's catch-up query (Task 2, Task 3).
3. A duplicate or out-of-order broadcast: the list never shows a product twice and stays ordered newest first (Task 3).
4. A guest or a request without a valid session asking for the channel: refused (Task 2 or Task 4).
5. The socket drops and returns: the page polls while disconnected, and on reconnect fills the gap from the last seen id without duplicates (Task 3, Task 4).

---

### Task 1: Install Reverb and its wiring

**Files:**
- Modify: `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `compose.yaml`, `.env.example`, `vite.config.js`, `bootstrap/app.php`
- Create (by the installer, then reviewed): `config/broadcasting.php`, `config/reverb.php`, `routes/channels.php`, `resources/js/echo.js`
- Test: `tests/Feature/BroadcastingConfigTest.php`

**Interfaces:**
- Produces: Reverb as a broadcast connection (`config('broadcasting.connections.reverb')`), a channels route file registered through `withBroadcasting` with the authentication route using the web session, an Echo bootstrap `resources/js/echo.js` (exposes `window.Echo`, reads `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME`) added as a Vite input but loaded only where a page asks for it, and a `reverb` service in `compose.yaml`.

- [ ] **Step 1:** run `composer require laravel/reverb` and `php artisan install:broadcasting --reverb --no-interaction` in the container (and `npm install` for `laravel-echo` and `pusher-js` where the project's npm runs; if the container has no npm, say so in the report and add the entries to `package.json`). Review what the installer wrote and keep only what the design needs (no extra scaffolding, no sample channel).
- [ ] **Step 2:** add the `reverb` service to `compose.yaml` following the style of the `queue` service (same image and volumes, `php artisan reverb:start --host=0.0.0.0 --port=8080`, the `sail` network, `depends_on` redis, `restart: unless-stopped`, a profile matching the other app services, port 8080 exposed to the host for local use only). `.env.example` gets `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` (placeholders), `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`, `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT`, `REVERB_SCALING_ENABLED=false` and the `VITE_REVERB_*` mirrors; keep the existing `BROADCAST_CONNECTION=log` behaviour for the test environment (`phpunit.xml`).
- [ ] **Step 3: Write failing-then-passing tests** (`BroadcastingConfigTest`): the `reverb` connection is configured from env with the keys the browser needs; the broadcasting authentication route exists, uses the `web` session guard and refuses a guest; the default connection under test is not `reverb`.
- [ ] **Step 4:** run `BroadcastingConfigTest` and the existing suites that touch bootstrap/routes quickly (`tests/Feature/ApiAlertsTest.php`); Pint. Run `npm run build` if the environment has it and report the result.
- [ ] **Step 5: Commit** `Reverb: install broadcasting and the Reverb server service`.

### Task 2: The ProductIngested event, its channel and the pipeline hook

**Files:**
- Create: `app/Events/ProductIngested.php`
- Modify: `routes/channels.php`, `app/Jobs/ProcessIngestedProduct.php`
- Test: `tests/Feature/ProductIngestedBroadcastTest.php`

**Interfaces:**
- Produces:
  - `App\Events\ProductIngested implements ShouldBroadcast` on `PrivateChannel('live-products')`, `broadcastAs()` `ProductIngested`, `broadcastWith()`: `{id: int, name: string, code: ?string, office: ?string, issued_at: string, headline: ?string}` (`name` is the catalog product's name else the AWIPS id else "Weather product"; `office` is the issuing office via `App\Support\IssuingOffice`; `issued_at` ISO 8601 of `received_at`; `headline` is the event names of the product's segments joined with " + " when it has segments, else null). No raw text and no `view_slug` (the page reads the row by id: the slug is generated after creation).
  - `Broadcast::channel('live-products', fn (User $user): bool => true)` (any authenticated user; a guest never reaches the callback).
  - `ProcessIngestedProduct::handle()` announces the product right after `buildSegments()` and before its branching (every branch returns), for products that are neither `is_test` nor replays, through a private method that catches `Throwable`, logs only the exception class, and never rethrows.

- [ ] **Step 1: Write failing tests**: a normal ingested product dispatches exactly one `ProductIngested` carrying the payload above (use `Event::fake([ProductIngested::class])`); an `is_test` product and a replay dispatch none; a broadcaster that throws (bind a throwing dispatcher or make `event()` throw) does not fail `handle()`, the rest of the processing (matching/sync) still runs, and the log context holds the exception class only; the channel authorization callback allows a user and the authentication route refuses a guest.
- [ ] **Step 2-4:** red, implement, green; run `ProcessIngestedProductTest` and the pipeline tests that exercise the job (find them with `grep -l ProcessIngestedProduct tests/Feature/*.php`); Pint. **Step 5: Commit** `Pipeline: broadcast each processed product on a private channel`.

### Task 3: The Products page

**Files:**
- Create: `app/Filament/Dashboard/Pages/LiveWeather/Products.php`, `resources/views/filament/dashboard/pages/live-weather/products.blade.php`, `app/Support/LiveProductCategory.php`
- Test: `tests/Feature/LiveWeatherProductsPageTest.php`

**Interfaces:**
- Consumes: `ProductIngested` and the channel (Task 2).
- Produces:
  - `LiveProductCategory` enum with cases `WarningsAndWatches`, `OutlooksAndDiscussions`, `Other` and `forProduct(?Product): self` (families `warning`, `watch`, `advisory`, `statement` → the first; `spc_wpc`, `forecast_discussion` → the second; anything else or no catalog product → `Other`).
  - The page (`navigationGroup` `Live Weather`, label `Products`, sort 2, slug `live-weather/products`): public properties `rows` (list of arrays: `id`, `name`, `office`, `headline`, `issued_at`, `category`, `view_url`), `categories` (the selected `LiveProductCategory` values, all selected by default), `pending` (count of products that arrived while the viewer was scrolled down). `mount()` loads the latest 50 non-test, non-replay ingested products (newest first). The Livewire listener for the private channel's `ProductIngested` event calls `onProductIngested(array $payload)`: it reads the row by `payload.id` (skipping a missing, test or replayed one), builds the row (the `view_url` from the row's public page when it has one), puts it at the top, ignores an id already present, keeps the list ordered by `issued_at` then id descending and at most 100 rows. `fetchSince(int $lastId)` returns and merges products newer than `$lastId` (the gap fill), capped at 100. `setCategories(array $categories)` validates against the enum. The Blade view lists the rows with category chips (all products by default), the time in the subscriber's timezone, the name, the office and headline and a "View" link (`target=_blank`, `rel=noopener`, only when `view_url` is an `https` public page); a sticky "N new products" pill is shown by Alpine while the list is scrolled down instead of shifting rows, and clicking it scrolls to the top and clears `pending`.

- [ ] **Step 1: Write failing tests** (`livewire(Products::class)` with a signed-in user): the page renders for any tier and lists the latest 50, newest first, excluding test products and replays; `onProductIngested` with a fresh id adds one row at the top; the same payload twice adds one row; an out-of-order older id lands in order; the list never exceeds 100; a payload whose id is a test product, a replay or does not exist adds nothing; `fetchSince` returns only newer, non-test rows and merges them without duplicates; category filtering hides the other categories' rows and rejects an unknown category value; `LiveProductCategory::forProduct` maps the families above and null; row names and offices are escaped in the markup (a product named `<script>x</script>` appears as text).
- [ ] **Step 2-4:** red, implement, green, Pint. **Step 5: Commit** `Live Weather: the Products page`.

### Task 4: Echo on the page, connection status and the polling fallback

**Files:**
- Modify: `app/Filament/Dashboard/Pages/LiveWeather/Products.php`, `resources/views/filament/dashboard/pages/live-weather/products.blade.php`, `resources/js/echo.js`
- Test: `tests/Feature/LiveWeatherProductsPageTest.php` (add cases)

**Interfaces:**
- Consumes: the `Products` page (Task 3), `resources/js/echo.js` (Task 1).
- Produces: the page loads the Echo bundle through `@vite` only on this page, joins the private channel `live-products` and wires the event to the Livewire listener (use Livewire's `echo-private:` listener syntax in `getListeners()` where it fits, otherwise `window.Echo.private('live-products').listen(...)` calling `$wire.onProductIngested`). A status chip shows "Live" when connected, "Reconnecting…" while connecting and "Offline: refreshing every minute" when the connection is down or unavailable. While not connected an Alpine-driven `wire:poll.60s` calls `fetchSince` with the newest id the page holds; when the socket connects again it calls `fetchSince` once and stops polling. If Echo cannot be created at all (a missing key, blocked WebSocket) the page works as the "Offline" mode: no error toast, the list still loads and polls.

- [ ] **Step 1: Write failing tests**: the rendered page includes the Echo bootstrap and the three status labels and the channel name; with no Reverb configuration the page still renders and carries the offline polling markup; the polling method uses the newest id the page holds and merges without duplicates (reuse Task 3's `fetchSince` tests through the page's poll entry point); a guest cannot reach the page (redirect to the panel login).
- [ ] **Step 2-4:** red, implement, green, Pint. **Step 5:** controller check with a local Reverb (`docker compose up -d reverb`, `npm run build` or the Vite dev server): sign in as a test subscriber, open the page, ingest a sample product through the existing ingest route or `php artisan` test tool and watch a row appear without a reload; stop Reverb and confirm the "Offline" chip and the minute poll; restart it and confirm the gap fill (report what could not be checked). **Step 6: Commit** `Live Weather: Echo, connection status and the polling fallback`.

## Controller steps after the tasks (not for implementers)

- Final whole-branch review on the most capable model; record rules with `record-rule` (the broadcast hook and its failure containment, the channel and its payload, `LiveProductCategory`, "Reverb is optional at runtime: the page degrades to polling").
- **Owner/ops items to hand over:** production needs the `reverb` process (a supervisor or compose service), the web proxy forwarding WebSocket upgrades (`/app` and `/apps`) to it with TLS (`wss://`), the `REVERB_*` and `VITE_REVERB_*` values in the environment and a rebuilt front end, `REVERB_SCALING_ENABLED=true` when more than one Reverb process runs (it uses Redis), and `php artisan reverb:restart` on each deploy. Nothing here changes how alerts are delivered.
