# Pipeline (NWWS) Filament Blueprint

## Context

The Admin Tools' Pipeline section (`docs/admin-tools-filament-blueprint.md`) shipped two static placeholders — Pipeline Control and Ingested Products — because at the time neither had any real backing infrastructure: the actual NWWS client was `nwws-go-client/`, an intentionally separate Go project with no connection to this Laravel app at all.

That's changed. The user copied `nwws-go-client`'s full source into `import/nwws-go-client/` in this repo. It's a working, tested Go CLI that connects to NOAA's NWWS-OI XMPP feed, joins the `nwws@conference.nwws-oi.weather.gov` MUC room, parses broadcast weather products, and atomically writes each one to `products/<cccc>/<filename>.txt` (dedup via `O_CREATE|O_EXCL` — an existing file is left untouched, not overwritten). It also supports a **PAN (Product Arrival Notification)** hook: after each newly-saved, non-duplicate product, it can exec an external script (`pan_run <path-to-file>`) asynchronously, with a 30s timeout. This is the integration seam this Blueprint uses — **no changes to `nwws-go-client` itself are needed**, it's used exactly as designed.

Four scoping questions were resolved with the user before writing this plan (all recommended options accepted):

1. **Deployment model**: `nwws-go-client` runs as a new sidecar service in this repo's own `compose.yaml`, on the same `sail` network, sharing a volume with the Laravel app container — not a separate remote host. This is a real departure from the Phase 0 blueprint's assumption of a remote pipeline server reached over a TLS-authenticated remote Docker Engine API; that assumption predates this repo actually having the client's source.
2. **Ingestion path**: `pan_run` points at a small script that calls a new Laravel API endpoint per new product (push), not a polling/watching command scanning the shared volume.
3. **Pipeline Control's scope**: real start/stop/restart via the local Docker Engine API (the host's Docker socket bind-mounted into the Laravel container — acceptable for this Sail-based dev setup; a socket-proxy restricted to this one container's lifecycle operations is the noted hardening step for a real production host, not built now). EMWIN's two rows are untouched, still static placeholders — this Blueprint is NWWS only.
4. **Ingested Products' scope**: a real, read-only Filament resource over the new archive table gets built now, not left as a stub — the table and ingestion pipe exist either way once Pipeline Control is wired up, so the incremental cost is small and it proves the data flows end-to-end.

**A filename-format gotcha that shapes the ingestion design**: `Product.Filename()` (see `import/nwws-go-client/internal/product/product.go`) encodes the issue time as `ddHHMM` only — day, hour, minute, **no month or year**. That's a property of the WMO abbreviated-header convention the filename mirrors, not a bug. It means the filename alone cannot losslessly reconstruct the original full `Issue` timestamp (which *does* have a year, on the XMPP stanza — it's just not carried into the filename). Rather than build a fragile "guess the month/year from now" heuristic, this Blueprint stores `received_at` (the webhook's own processing time — always correct, zero ambiguity) as the primary timestamp for search/sort/filtering, and keeps the raw `ddHHMM` string from the filename as a display-only field alongside it.

**Non-goal, explicitly out of scope**: `ttaaii`/`awipsid` (raw WMO/AWIPS identifiers) don't map onto the existing `products.code` catalog scheme (dotted VTEC-style codes, a different taxonomy entirely — see `.ai/rules/models.md`'s existing note on `Product::family` vs `Product::category` for the established precedent of not conflating adjacent-but-different taxonomies). This Blueprint does not attempt to cross-link an ingested product to a Product Catalog entry. That's a real, useful future feature (a `ttaaii`→catalog crosswalk, similar in spirit to `ProductCatalogSeeder`'s legacy-catalog crosswalk) but is its own separate piece of work.

## Per-Component Design (locked in)

### 1. Compose service (`nwws`)

New service in `compose.yaml`, built from a Dockerfile at `docker/nwws/Dockerfile` (multi-stage: `golang:1.27` build stage running `go build -o nwws-go-client ./cmd/nwws-go-client`, copied into a minimal runtime stage — `nwws-go-client`'s own `go.mod` pins toolchain 1.27, confirmed in `import/nwws-go-client/CLAUDE.md`). Source moves from `import/nwws-go-client/` to a permanent home in this repo, e.g. `pipeline/nwws-go-client/` (`import/` was always meant as a staging area, not a permanent location — see the product-catalog merge, which copied its source data out of `import/` into `database/` and left `import/` for the user to delete).

The runtime stage must **not** be a bare `scratch`/`distroless` image despite only needing to run one static Go binary — it also needs `curl` (the PAN script's only dependency) and the script itself baked in and executable, or PAN invocations fail silently (`nwws-go-client` treats a PAN failure as a logged, non-fatal event per its own design — the client keeps running, but ingestion just quietly never happens, which is a confusing failure mode to debug after the fact). Use a minimal base that has a package manager (e.g. `alpine`) for this reason: `RUN apk add --no-cache curl`, `COPY docker/nwws/pan-notify.sh /pan-notify.sh` + `RUN chmod +x /pan-notify.sh` in the runtime stage, alongside the copied-in binary.

```yaml
nwws:
    build:
        context: ./pipeline/nwws-go-client
        dockerfile: ../../docker/nwws/Dockerfile
    container_name: wxnotify-nwws
    environment:
        NWWS_USERNAME: '${NWWS_USERNAME}'
        NWWS_PASSWORD: '${NWWS_PASSWORD}'
        NWWS_ARCHIVEDIR: /products
        NWWS_PAN_RUN: /pan-notify.sh
        NWWS_RETRY: 'true'
    volumes:
        - 'nwws-products:/products'
    networks:
        - sail
    restart: unless-stopped
```

`NWWS_USERNAME`/`NWWS_PASSWORD` go in `.env` (never committed), matching how `DB_*`/`TURNSTILE_*`/etc. already work in this project. A new named volume `nwws-products` (declared alongside the existing `sail-pgsql`/`sail-redis` volumes, at the compose file's top-level `volumes:` key) is mounted into **both** `nwws` (at `/products`, matching `NWWS_ARCHIVEDIR`) and `laravel.test` (at the same path, so the ingestion endpoint can read a file the moment PAN reports it). `restart: unless-stopped` (not the Sail app services' default of nothing) — this is a long-running feed client meant to survive host reboots without a manual `docker compose up`.

The existing `laravel.test` service also needs three additions — the shared products volume, the Docker socket (see Pipeline Control below — `nwws` itself never needs to control other containers, so the socket is *only* mounted here, not into `nwws`), and the webhook shared secret:

```yaml
laravel.test:
    volumes:
        - '.:/var/www/html'
        - 'nwws-products:/products'
        - '/var/run/docker.sock:/var/run/docker.sock'
    environment:
        # ...existing WWWUSER/LARAVEL_SAIL/etc. entries stay as they are...
        PIPELINE_WEBHOOK_TOKEN: '${PIPELINE_WEBHOOK_TOKEN}'
```

### 2. Models

```
Model: IngestedProduct (new)
  Command: php artisan make:model IngestedProduct -mf --no-interaction
  Docs: https://filamentphp.com/docs/5.x/resources/overview

  Migration (create_ingested_products_table):
    id
    string('cccc')->nullable()->index()       # originating office, e.g. KKCI — nullable: filename parse can fail on malformed data (see step 4 of the ingestion controller spec below)
    string('ttaaii')->nullable()->index()     # WMO abbreviated header, e.g. FTUS21
    string('awipsid')->nullable()->index()    # AWIPS product ID, e.g. TAFKORD
    string('nwws_id')->nullable()             # NWWS-OI's own routing/sequence id for this product
    string('wmo_ddhhmm', 6)->nullable()       # raw day/hour/minute fragment from the filename — display only, NOT a real date (no month/year, see Context)
    text('text')                              # full raw product text
    string('file_path')->unique()             # path relative to the shared volume root; also the idempotency key
    timestamp('received_at')->index()         # when this app's ingestion endpoint processed it — the real, unambiguous timestamp to sort/filter by
    timestamps()

  Casts: none needed beyond default datetime casting on received_at/timestamps.

  No #[Fillable(...)] restriction needed — this table is only ever written by
  one internal path (the ingestion controller, using updateOrCreate keyed on
  file_path), never through a Filament form. Follow the project's established
  attribute pattern (see app/Models/Product.php) but there's nothing here a
  subscriber or a Filament form could mass-assign, so a permissive #[Fillable]
  covering all the above columns is fine.

  Factory: standard field-per-column, matching Product's/DeliveryLog's own
  factory conventions in this project (fake()->... per field).
```

### 3. Pipeline Infrastructure (ingestion pipe + Docker control)

Not Filament — plain Laravel pieces the Filament resources in the next section depend on.

**PAN script** (`docker/nwws/pan-notify.sh`, baked into the `nwws` image, referenced by `NWWS_PAN_RUN=/pan-notify.sh`):

```bash
#!/bin/sh
set -eu
path="${1#/products/}"
curl -sf -X POST "http://laravel.test/api/pipeline/nwws-products" \
    -H "Authorization: Bearer ${PIPELINE_WEBHOOK_TOKEN}" \
    -H "Content-Type: application/json" \
    -d "{\"path\":\"${path}\"}"
```

Deliberately does no parsing at all — its only job is to tell Laravel a path is ready. `PIPELINE_WEBHOOK_TOKEN` is a shared secret, set as an env var on **both** the `nwws` service (so the script can read it) and `laravel.test` (so the endpoint can check it) — generate one with `php artisan tinker --execute 'echo Str::random(40);'` and store it in `.env`, not committed. `laravel.test` is Sail's own container hostname for the app service (confirmed in `compose.yaml`), reachable by name from any other service on the `sail` network — no port publishing or external exposure needed.

**Ingestion endpoint** — plain route, not part of any Filament panel:

```
bootstrap/app.php:
    This project has no routes/api.php yet (confirmed — ->withRouting() only
    registers web/commands/health today, despite withExceptions() already
    having an api/* JSON-response check with nothing routed under that
    prefix). Add `api: __DIR__.'/../routes/api.php'` to the existing
    ->withRouting(...) call (Laravel auto-prefixes api.php routes with
    /api and applies the api middleware group — no manual Route::prefix()
    needed).

routes/api.php (new):
    Route::post('/pipeline/nwws-products', IngestNwwsProductController::class)
        ->middleware('pipeline.webhook');

app/Http/Middleware/VerifyPipelineWebhookToken.php (new):
    Compares the Authorization: Bearer header against config('services.pipeline.webhook_token')
    using hash_equals() (not ==, to avoid a timing side-channel), aborts 401 on mismatch.
    Register in bootstrap/app.php's ->withMiddleware(...) with alias 'pipeline.webhook'.

config/services.php:
    Add a 'pipeline' => ['webhook_token' => env('PIPELINE_WEBHOOK_TOKEN')] block,
    matching this project's existing convention for third-party secrets (see the
    'google' block added for Socialite in Phase 0).

app/Http/Controllers/IngestNwwsProductController.php (new):
    1. Validate the request: ['path' => 'required|string'].
    2. Resolve the absolute path against the shared volume root (a new
       config('services.pipeline.products_path'), env-driven, defaulting to
       /products to match NWWS_ARCHIVEDIR — do NOT trust the request's path
       as already-absolute; join it under the configured root and verify
       realpath() still resolves inside that root before reading, the same
       path-traversal discipline nwws-go-client's own sanitizeID() already
       applies on the writing side).
    3. If the file doesn't exist (race with the write, or a bad path), return
       404 — pan_run's own 30s timeout/retry-on-next-arrival covers transient
       misses; don't build a retry queue for this pass.
    4. Parse the filename (basename only) against:
         /^([a-z0-9._-]+)_([a-z0-9._-]+)-([a-z0-9._-]+)\.(\d{6})_(.+)\.txt$/
       capturing cccc/ttaaii/awipsid/wmo_ddhhmm/nwws_id. This isn't
       theoretically unambiguous — nwws-go-client's own sanitizeID() allows
       `_`/`-`/`.` *inside* a field, the same characters used as delimiters
       here, so a field containing one could in principle misparse. In
       practice cccc/ttaaii/awipsid are well-known fixed-format NWS/WMO
       identifiers (4/6/6 alphanumeric characters) that never legitimately
       contain those characters, so this is a non-issue for real feed data;
       it's called out here so nobody "fixes" the regex into something more
       clever without knowing why it's shaped this way.
       On a parse failure, still ingest the row with cccc/ttaaii/awipsid/
       wmo_ddhhmm/nwws_id all null and log a warning — never silently drop a
       product just because its filename was unparseable.
    5. IngestedProduct::updateOrCreate(['file_path' => $path], [...parsed
       fields, 'text' => file_get_contents($absolutePath), 'received_at' =>
       now()]) — updateOrCreate on file_path makes a retried/duplicate
       webhook call a no-op rather than a duplicate row, matching this
       project's established idempotent-ingestion pattern (see
       ProductCatalogSeeder).
    6. Return 204.
```

**Docker control service** (used by the Pipeline Control page below):

```
app/Support/Pipeline/DockerEngineClient.php (new):
    Talks to the Docker Engine API over the host's unix socket using
    Laravel's Http facade with Guzzle's unix-socket curl option — NOT a new
    Composer dependency. (docker-php/docker-php, the obvious PHP client
    package, is archived/unmaintained since 2019 — confirmed via its GitHub
    repo — so this avoids depending on a dead package. Guzzle is already
    present as a transitive Laravel dependency; no composer.json change
    needed.)

    Docs: https://docs.docker.com/reference/api/engine/version/v1.43/
    (verify the Docker Engine version actually installed on the host is
    >= the API version pinned here before implementing — check with
    `docker version --format '{{.Server.APIVersion}}'`)

    private function request(string $method, string $path): Response
    {
        return Http::withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => '/var/run/docker.sock']])
            ->{$method}("http://localhost/v1.43{$path}");
    }

    public function status(string $container): string
    {
        // GET /containers/{id}/json -> 200 with JSON body; ->json('State.Status')
        // is one of created/running/paused/restarting/removing/exited/dead.
        // A 404 (container doesn't exist / image never built) should surface
        // as a distinct "not found" status, not throw — Pipeline Control
        // needs to render *something* even before the image is ever built.
    }

    public function start(string $container): void   // POST /containers/{id}/start  -> 204
    public function stop(string $container): void    // POST /containers/{id}/stop   -> 204
    public function restart(string $container): void // POST /containers/{id}/restart -> 204
```

`$container` is the fixed string `'wxnotify-nwws'` (the `container_name` set in compose above) — no container discovery/listing needed for one known service.

### 4. Resources & Pages

```
Page: PipelineControl (modify existing app/Filament/Admin/Pages/PipelineControl.php)
  Docs: https://filamentphp.com/docs/5.x/actions/overview

  Replace the static Blade view's three hardcoded rows with one real row for
  NWWS (backed by DockerEngineClient) and keep the other two (EMWIN
  Byteblaster, EMWIN HTTP backup) exactly as they are — still static
  "status unknown", out of scope here.

  The NWWS row needs, per Filament's own Page-level actions pattern (see
  Products/Tables/ProductsTable.php's retire Action for this project's
  existing "Action calling into a service class, then Notification::make()"
  shape):

  Action: start
    Component: Filament\Actions\Action
    Docs: https://filamentphp.com/docs/5.x/actions/overview
    Location: PipelineControl page, NWWS row
    Visibility: ->visible(fn (): bool => app(DockerEngineClient::class)->status('wxnotify-nwws') !== 'running')
    Authorization: staff-only (inherited from canAccessPanel() — no extra check)
    Behavior: calls DockerEngineClient::start('wxnotify-nwws'), then a success
      Notification, then refresh the row's displayed status (e.g. via
      wire:poll or a manual $this->dispatch('$refresh') — confirm the
      current Filament v5 idiom via search-docs before implementing).

  Action: stop
    Same shape, ->visible(...status(...) === 'running'), calls ::stop().

  Action: restart
    Same shape, always visible, calls ::restart(), requiresConfirmation()
    (this drops the live feed connection briefly — worth a confirm step,
    unlike start/stop which are already implicitly confirmed by only being
    visible when the opposite state holds).

  Status badge: color-coded from DockerEngineClient::status() — green/running,
  gray/exited, red/dead, amber/restarting-or-not-found — replacing the
  static "Status unknown" pill.

Resource: IngestedProducts (new)
  Command: php artisan make:filament-resource IngestedProducts --view --no-interaction
    (--view: List + View pages, no Create/Edit/Delete — confirmed read-only
    precedent in this project is ActivityLogs' ActivityResource: List +
    View(Infolist), canCreate() false, canEdit(Model $record)/canDelete(Model
    $record) both false. Match that exactly, not a Form-based Edit page.)
  Location: app/Filament/Admin/Resources/IngestedProducts/
  Docs: https://filamentphp.com/docs/5.x/resources/overview
  Follow ActivityLogs' exact folder/class shape, renamed:
    IngestedProducts/IngestedProductResource.php   (class IngestedProductResource,
      mirroring ActivityResource's own "singular class name, plural directory"
      naming — not "IngestedProductsResource")
    IngestedProducts/Tables/IngestedProductsTable.php
    IngestedProducts/Schemas/IngestedProductInfolist.php
    IngestedProducts/Pages/ListIngestedProducts.php
    IngestedProducts/Pages/ViewIngestedProduct.php
  The Infolist's main job is showing the full `text` column readably —
  Filament\Infolists\Components\TextEntry::make('text')->columnSpanFull()
  (see Docs: https://filamentphp.com/docs/5.x/infolists/text-entry),
  probably with ->markdown(false)/plain rendering and possibly a
  `prose`-style wrapping class, since raw WMO product text is fixed-width
  and line-broken on purpose — verify via search-docs whether TextEntry has
  a built-in "preserve whitespace" mode before reaching for a custom view.

  Navigation:
    Group: 'Operations' (matching Pipeline Control's/Delivery Log's group)
    Icon: Heroicon::OutlinedArchiveBox (or similar — pick one distinct from
      Delivery Log's/Pipeline Control's existing icons)

  Table Columns:
    Column: cccc
      Component: Filament\Tables\Columns\TextColumn
      Docs: https://filamentphp.com/docs/5.x/tables/columns/text
      Config: ->label('Office')->searchable()->sortable()
        ->formatStateUsing(fn (?string $state) => $state ?? '—')
    Column: ttaaii
      Component: Filament\Tables\Columns\TextColumn
      Config: ->searchable()->toggleable()
    Column: awipsid
      Component: Filament\Tables\Columns\TextColumn
      Config: ->label('AWIPS ID')->searchable()
    Column: wmo_ddhhmm
      Component: Filament\Tables\Columns\TextColumn
      Config: ->label('Issued (DDHHMM)')->toggleable(isToggledHiddenByDefault: true)
        ->formatStateUsing(fn (?string $state) => $state ?? '—')
    Column: received_at
      Component: Filament\Tables\Columns\TextColumn
      Config: ->dateTime()->sortable()->since() as a secondary description
        line (see other resources in this project using ->description() for
        a relative-time subtitle under an absolute one)

  Filters:
    Filter: cccc
      Component: Filament\Tables\Filters\SelectFilter
      Docs: https://filamentphp.com/docs/5.x/tables/filters/overview
      Config: ->options(fn () => IngestedProduct::query()->whereNotNull('cccc')
        ->distinct()->orderBy('cccc')->pluck('cccc', 'cccc')->all())
        (a plain distinct-values select, not an enum — cccc is open-ended
        real-world data, not a fixed catalog)
    Filter: received_at range
      Component: Filament\Tables\Filters\Filter
      Docs: https://filamentphp.com/docs/5.x/tables/filters/overview
      Config: two DatePicker fields (from/until) in ->schema(), ->query()
        applying whereDate('received_at', '>=', ...) / whereDate('received_at', '<=', ...)
        conditionally per Laravel's own Builder::when() pattern — mirror the
        existing date-range filter shape if any other resource in this
        project already has one; otherwise this is net-new, write it
        matching Filament's documented Filter::make()->schema() example.

  No Create/Edit/Delete actions — this resource is exclusively fed by the
  ingestion endpoint. canCreate()/canEdit(Model $record)/canDelete(Model
  $record) all false, matching ActivityResource's exact signatures (shown
  above).
```

### 5. Authorization

Both PipelineControl and the IngestedProducts resource stay under the `admin` panel's existing flat `is_staff` gate (`canAccessPanel()` on `User`) — no new authorization tier, matching every other Admin Tool in this project (confirmed flat-`is_staff` scoping decision from the original Admin Tools Blueprint, unchanged here). The ingestion webhook endpoint is authenticated separately and *not* via Filament/session auth at all — it's a machine-to-machine call authenticated by the bearer token described above, since the caller (the `nwws` container's PAN script) has no user session and shouldn't be given one.

### 6. Tests

```
DockerEngineClient:
  Unit-ish tests using Http::fake() (Laravel's own HTTP-client faking,
  confirmed to work with the curl unix-socket option since Http::fake()
  intercepts before the request is actually dispatched) — assert start()/
  stop()/restart() hit the expected path+method, and status() correctly
  maps a faked 200/State.Status body and a faked 404 to their respective
  return values.

IngestNwwsProductController (Feature test):
  - A request without a valid bearer token gets 401 and creates no row.
  - A valid request for a well-formed filename creates an IngestedProduct
    with correctly-parsed cccc/ttaaii/awipsid/wmo_ddhhmm/nwws_id and the
    file's actual text content.
  - A valid request for a path outside the configured products root is
    rejected (path-traversal guard), not silently ingested.
  - A valid request for a path that doesn't exist on disk returns 404 and
    creates no row.
  - Calling the endpoint twice with the same path is idempotent — still
    exactly one row (updateOrCreate on file_path), second call's data wins
    if the file somehow changed (it shouldn't, given nwws-go-client's own
    O_CREATE|O_EXCL dedup, but the controller's own behavior should still
    be well-defined here rather than accidentally undefined).
  - A malformed/unparseable filename still creates a row (cccc/ttaaii/
    awipsid/wmo_ddhhmm/nwws_id null, text still populated) rather than
    being dropped.

PipelineControl (Feature test, livewire()):
  - The NWWS row's start action is visible/hidden correctly based on a
    faked DockerEngineClient::status() response (bind a fake/mock instance
    in the container for the test, don't hit a real Docker socket).
  - Calling the start/stop/restart actions calls the corresponding
    DockerEngineClient method (assert via a mock's expectation, not a real
    Docker daemon — tests must not depend on Docker actually being
    available in the test environment).

IngestedProducts resource (Feature test):
  - List page shows seeded IngestedProduct rows.
  - The cccc filter and the received_at range filter each narrow results
    correctly.
  - No create/edit/delete actions exist (assertActionDoesNotExist, matching
    every other read-only resource's existing test pattern in this
    project).
```

## Verification

- `docker compose build nwws` succeeds; `docker compose up -d nwws` starts the container and it successfully authenticates to NWWS-OI (check its logs for a successful MUC join — real credentials are required for this specific check, everything else is testable without them).
- A manually-dropped file under the shared `nwws-products` volume, or a real product arriving from the live feed, triggers PAN, which reaches the ingestion endpoint, which produces exactly one correctly-parsed `IngestedProduct` row — confirm via `php artisan tinker` or the Ingested Products admin page, not just the automated tests.
- From `/admin/pipeline-control` as a seeded staff user: the NWWS row's status badge reflects the container's real state, and Start/Stop/Restart actually start/stop/restart the `wxnotify-nwws` container (confirm via `docker ps` alongside the UI, not just that the button click doesn't error).
- `php artisan route:list --path=api/pipeline` shows the new webhook route.
- Full Pest suite passes; `vendor/bin/pint --dirty` clean.

## As-Built Notes

*(to be filled in during implementation — this section intentionally starts empty, matching this project's established Blueprint-doc convention of recording corrections/gotchas discovered while building, not guessed in advance)*
