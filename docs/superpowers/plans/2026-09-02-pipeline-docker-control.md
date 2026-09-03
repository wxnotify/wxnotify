# Pipeline Docker Control Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the static "status unknown" NWWS row on the admin `/admin/pipeline-control` page with real start/stop/restart control of the `wxnotify-nwws` Docker container, via the local Docker Engine API over the already-mounted host socket.

**Architecture:** A small `DockerEngineClient` service class in `app/Support/Pipeline/` talks to `unix:///var/run/docker.sock` (already bind-mounted into `laravel.test` — see `compose.yaml`) using Laravel's `Http` facade with Guzzle's unix-socket curl option, no new Composer dependency. `App\Filament\Admin\Pages\PipelineControl` (currently a fully static placeholder) gets three page-level `Filament\Actions\Action`s (start/stop/restart) that call the client and show a success/failure `Notification`. The two EMWIN rows stay untouched, static placeholders — this plan is NWWS only.

**Tech Stack:** Laravel 13 / PHP 8.5, Filament v5 (`Filament\Actions\Action` on a custom `Filament\Pages\Page`, using its built-in `InteractsWithActions`/`HasActions` — no extra traits needed), Laravel's `Http` facade + `Http::fake()` for testing (no real Docker socket touched by the automated suite), Pest.

---

## Context (do not re-derive — read this first)

This is Plan 2 of the NWWS Pipeline work; Plan 1 (already shipped, all on `main`) built the `nwws` sidecar container, the ingestion webhook, and the real `IngestedProducts` archive resource. Plan 1's Task 5 already added the Docker socket bind mount to `laravel.test` in `compose.yaml`, specifically in preparation for this plan:

```yaml
    laravel.test:
        volumes:
            # ...
            # Unused by nwws itself; mounted here in prep for a later,
            # separate feature: Docker Engine API-based start/stop/restart
            # control of pipeline containers from the admin panel.
            - '/var/run/docker.sock:/var/run/docker.sock'
```

The container this plan controls is named `wxnotify-nwws` (the `container_name:` set on the `nwws` service in `compose.yaml`) — no container discovery/listing is needed, it's one fixed, known name.

The full original design for this piece lives in `docs/pipeline-nwws-filament-blueprint.md`, section "3. Pipeline Infrastructure" (the "Docker control service" subsection) and section "4. Resources & Pages" (the `PipelineControl` page subsection) — read those two subsections for the full rationale if anything below is unclear. This plan's code differs from that doc in a few small, deliberate ways (documented inline below where it happens): no `wire:poll` (Livewire already re-renders after every action call, which is enough for this low-traffic internal tool), and tests use `Http::fake()` directly against the Docker socket's HTTP surface rather than mocking `DockerEngineClient` itself, matching this project's established pattern (see `tests/Feature/GetSevenDayForecastTest.php`) — there is no existing Mockery/`$this->mock()` convention in this codebase to match instead.

The host's actual Docker Engine API version was confirmed via `docker version --format '{{.Server.APIVersion}}'` → `1.55`, comfortably above the `v1.43` this plan pins (Docker's API is backward-compatible for older version paths).

**Existing conventions to match** (read these files before starting if unfamiliar):
- `app/Support/Apprise/AppriseApiClient.php` — this project's existing shape for a small `Http`-facade-based external-service client class.
- `app/Filament/Admin/Resources/Products/Tables/ProductsTable.php`'s `retire` `Action` — this project's existing "`Action` calling into a service, then `Notification::make()`" shape.
- `.ai/rules/filament.md` — read in full before touching anything under `app/Filament/`. Its "`EditRecord`'s default save bypasses non-Fillable fields" note isn't relevant here (no form), but its note that testing a resource/page on the `admin` panel needs `Filament::setCurrentPanel('admin')` in `beforeEach()` **is** relevant to this plan's tests.

---

### Task 1: `DockerEngineClient` service class

**Files:**
- Create: `app/Support/Pipeline/DockerEngineClient.php`
- Test: `tests/Feature/DockerEngineClientTest.php`

- [ ] **Step 1: Write the failing tests**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest DockerEngineClientTest --no-interaction"`

Replace `tests/Feature/DockerEngineClientTest.php` with:

```php
<?php

use App\Support\Pipeline\DockerEngineClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

test('status returns the container state when the container exists', function () {
    Http::fake([
        'localhost/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
    ]);

    expect((new DockerEngineClient)->status('wxnotify-nwws'))->toBe('running');
});

test('status returns not_found when the container does not exist', function () {
    Http::fake([
        'localhost/v1.43/containers/wxnotify-nwws/json' => Http::response([], 404),
    ]);

    expect((new DockerEngineClient)->status('wxnotify-nwws'))->toBe('not_found');
});

test('status throws when the docker daemon returns an unexpected error', function () {
    Http::fake([
        'localhost/v1.43/containers/wxnotify-nwws/json' => Http::response([], 500),
    ]);

    (new DockerEngineClient)->status('wxnotify-nwws');
})->throws(RequestException::class);

test('start posts to the start endpoint', function () {
    Http::fake([
        'localhost/v1.43/containers/wxnotify-nwws/start' => Http::response('', 204),
    ]);

    (new DockerEngineClient)->start('wxnotify-nwws');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/containers/wxnotify-nwws/start'));
});

test('stop posts to the stop endpoint', function () {
    Http::fake([
        'localhost/v1.43/containers/wxnotify-nwws/stop' => Http::response('', 204),
    ]);

    (new DockerEngineClient)->stop('wxnotify-nwws');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/containers/wxnotify-nwws/stop'));
});

test('restart posts to the restart endpoint', function () {
    Http::fake([
        'localhost/v1.43/containers/wxnotify-nwws/restart' => Http::response('', 204),
    ]);

    (new DockerEngineClient)->restart('wxnotify-nwws');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/containers/wxnotify-nwws/restart'));
});

test('start throws when the docker daemon returns an error', function () {
    Http::fake([
        'localhost/v1.43/containers/wxnotify-nwws/start' => Http::response([], 500),
    ]);

    (new DockerEngineClient)->start('wxnotify-nwws');
})->throws(RequestException::class);
```

- [ ] **Step 2: Run the tests, confirm they fail**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/DockerEngineClientTest.php --compact"`

Expected: FAIL — `Class "App\Support\Pipeline\DockerEngineClient" not found`.

- [ ] **Step 3: Implement the client**

Create `app/Support/Pipeline/DockerEngineClient.php`:

```php
<?php

namespace App\Support\Pipeline;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DockerEngineClient
{
    private function request(string $method, string $path): Response
    {
        return Http::withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => '/var/run/docker.sock']])
            ->{$method}("http://localhost/v1.43{$path}");
    }

    public function status(string $container): string
    {
        $response = $this->request('get', "/containers/{$container}/json");

        if ($response->status() === 404) {
            return 'not_found';
        }

        $response->throw();

        return $response->json('State.Status');
    }

    public function start(string $container): void
    {
        $this->request('post', "/containers/{$container}/start")->throw();
    }

    public function stop(string $container): void
    {
        $this->request('post', "/containers/{$container}/stop")->throw();
    }

    public function restart(string $container): void
    {
        $this->request('post', "/containers/{$container}/restart")->throw();
    }
}
```

(`status()` deliberately does not throw on a 404 — the caller needs to render *something* for "container doesn't exist yet" rather than crash, e.g. before the `nwws` image has ever been built. Every other non-2xx response does throw, via `Response::throw()`, which raises `Illuminate\Http\Client\RequestException` — callers are expected to catch this, not the client itself.)

- [ ] **Step 4: Run the tests, confirm they pass**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/DockerEngineClientTest.php --compact"`

Expected: `Tests: 7 passed`

- [ ] **Step 5: Pint, full suite, commit**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent && vendor/bin/pest --compact"
git add app/Support/Pipeline/DockerEngineClient.php tests/Feature/DockerEngineClientTest.php
git commit -m "Add DockerEngineClient for local Docker Engine API control

Talks to the host's Docker socket (already bind-mounted into
laravel.test) via Http::withOptions()'s unix-socket curl option — no
new Composer dependency. Used by the next task's Pipeline Control page
to start/stop/restart the nwws sidecar.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

The full-suite run must show every test passing, not just this task's new file — if anything else fails, stop and investigate before committing.

---

### Task 2: `PipelineControl` page — real start/stop/restart for NWWS

**Files:**
- Modify: `app/Filament/Admin/Pages/PipelineControl.php`
- Modify: `resources/views/filament/admin/pages/pipeline-control.blade.php`
- Test: `tests/Feature/PipelineControlTest.php`

Current state of both files (read them yourself to confirm before starting — they're short):

`app/Filament/Admin/Pages/PipelineControl.php` is a bare `Filament\Pages\Page` with no actions, `protected string $view = 'filament.admin.pages.pipeline-control'`, nav icon `Heroicon::OutlinedServerStack`, nav group `'Operations'`, nav label `'Pipeline Control'`.

`resources/views/filament/admin/pages/pipeline-control.blade.php` renders an explanatory paragraph ("not built yet... needs a TLS-client-certificate-authenticated connection...") followed by a `@foreach` over `['NWWS', 'EMWIN Byteblaster', 'EMWIN HTTP backup']` rendering each as a row with a static gray "Status unknown" pill.

- [ ] **Step 1: Write the failing feature test**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest PipelineControlTest --no-interaction"`

Replace `tests/Feature/PipelineControlTest.php` with:

```php
<?php

use App\Filament\Admin\Pages\PipelineControl;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
});

test('the start action is visible and the stop action is hidden when nwws is not running', function () {
    Http::fake([
        'localhost/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'exited']], 200),
    ]);

    livewire(PipelineControl::class)
        ->assertActionVisible('start')
        ->assertActionHidden('stop');
});

test('the stop action is visible and the start action is hidden when nwws is running', function () {
    Http::fake([
        'localhost/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
    ]);

    livewire(PipelineControl::class)
        ->assertActionHidden('start')
        ->assertActionVisible('stop');
});

test('calling the start action starts the nwws container and notifies success', function () {
    Http::fake([
        'localhost/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'exited']], 200),
        'localhost/v1.43/containers/wxnotify-nwws/start' => Http::response('', 204),
    ]);

    livewire(PipelineControl::class)
        ->callAction('start')
        ->assertNotified('NWWS start succeeded');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/containers/wxnotify-nwws/start'));
});

test('calling the restart action restarts the nwws container and notifies success', function () {
    Http::fake([
        'localhost/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
        'localhost/v1.43/containers/wxnotify-nwws/restart' => Http::response('', 204),
    ]);

    livewire(PipelineControl::class)
        ->callAction('restart')
        ->assertNotified('NWWS restart succeeded');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/containers/wxnotify-nwws/restart'));
});

test('a failed docker call shows a failure notification instead of erroring', function () {
    Http::fake([
        'localhost/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'exited']], 200),
        'localhost/v1.43/containers/wxnotify-nwws/start' => Http::response([], 500),
    ]);

    livewire(PipelineControl::class)
        ->callAction('start')
        ->assertNotified('Failed to start NWWS');
});

test('the emwin rows remain static placeholders', function () {
    Http::fake([
        'localhost/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
    ]);

    livewire(PipelineControl::class)
        ->assertSee('EMWIN Byteblaster')
        ->assertSee('EMWIN HTTP backup')
        ->assertSee('Status unknown');
});
```

- [ ] **Step 2: Run the tests, confirm they fail**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/PipelineControlTest.php --compact"`

Expected: FAIL — no `start`/`stop`/`restart` actions exist yet on the page.

- [ ] **Step 3: Implement the page's actions**

Replace `app/Filament/Admin/Pages/PipelineControl.php`:

```php
<?php

namespace App\Filament\Admin\Pages;

use App\Support\Pipeline\DockerEngineClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Throwable;
use UnitEnum;

class PipelineControl extends Page
{
    protected string $view = 'filament.admin.pages.pipeline-control';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Pipeline Control';

    private const NWWS_CONTAINER = 'wxnotify-nwws';

    public function nwwsStatus(): string
    {
        return app(DockerEngineClient::class)->status(self::NWWS_CONTAINER);
    }

    public function nwwsStatusColor(): string
    {
        return match ($this->nwwsStatus()) {
            'running' => 'success',
            'exited' => 'gray',
            'dead' => 'danger',
            default => 'warning',
        };
    }

    public function nwwsStatusLabel(): string
    {
        return match ($status = $this->nwwsStatus()) {
            'not_found' => 'Not found',
            default => ucfirst($status),
        };
    }

    public function startAction(): Action
    {
        return Action::make('start')
            ->label('Start')
            ->color('success')
            ->visible(fn (): bool => $this->nwwsStatus() !== 'running')
            ->action(fn () => $this->runDockerAction('start', fn () => app(DockerEngineClient::class)->start(self::NWWS_CONTAINER)));
    }

    public function stopAction(): Action
    {
        return Action::make('stop')
            ->label('Stop')
            ->color('danger')
            ->visible(fn (): bool => $this->nwwsStatus() === 'running')
            ->action(fn () => $this->runDockerAction('stop', fn () => app(DockerEngineClient::class)->stop(self::NWWS_CONTAINER)));
    }

    public function restartAction(): Action
    {
        return Action::make('restart')
            ->label('Restart')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('This briefly drops the live NWWS-OI feed connection.')
            ->action(fn () => $this->runDockerAction('restart', fn () => app(DockerEngineClient::class)->restart(self::NWWS_CONTAINER)));
    }

    private function runDockerAction(string $verb, callable $callback): void
    {
        try {
            $callback();

            Notification::make()
                ->title("NWWS {$verb} succeeded")
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title("Failed to {$verb} NWWS")
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
```

(No `wire:poll` — a custom Filament page already has `InteractsWithActions`/`HasActions` built in, and calling an action is itself a Livewire round-trip, so the page (and thus `nwwsStatus()`) naturally re-renders with fresh data immediately after Start/Stop/Restart is clicked. Continuous background polling would just add socket load on a low-traffic internal tool with no one watching it live; skip it.)

- [ ] **Step 4: Rewrite the Blade view**

Replace `resources/views/filament/admin/pages/pipeline-control.blade.php`:

```blade
<x-filament-panels::page>
    <div class="space-y-3">
        <div class="flex items-center justify-between rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="flex items-center gap-3">
                <span class="font-medium text-gray-700 dark:text-gray-200">NWWS</span>
                <x-filament::badge :color="$this->nwwsStatusColor()">
                    {{ $this->nwwsStatusLabel() }}
                </x-filament::badge>
            </div>
            <div class="flex items-center gap-2">
                {{ $this->startAction }}
                {{ $this->stopAction }}
                {{ $this->restartAction }}
            </div>
        </div>

        @foreach (['EMWIN Byteblaster', 'EMWIN HTTP backup'] as $client)
            <div class="flex items-center justify-between rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                <span class="font-medium text-gray-700 dark:text-gray-200">{{ $client }}</span>
                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    Status unknown
                </span>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
```

- [ ] **Step 5: Run the tests, confirm they pass**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/PipelineControlTest.php --compact"`

Expected: `Tests: 6 passed`

- [ ] **Step 6: Pint, full suite, commit**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent && vendor/bin/pest --compact"
git add app/Filament/Admin/Pages/PipelineControl.php resources/views/filament/admin/pages/pipeline-control.blade.php tests/Feature/PipelineControlTest.php
git commit -m "Wire real Docker start/stop/restart into Pipeline Control's NWWS row

Replaces the static 'status unknown' NWWS placeholder with a live
status badge and start/stop/restart actions backed by
DockerEngineClient. EMWIN's two rows are untouched — still static,
out of scope here.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

The full-suite run must show every test passing (Task 1's `DockerEngineClientTest` included), not just this task's new file.

---

## Task 3: `docker-socket-proxy` sidecar (added mid-implementation — see rationale below)

**Why this task exists, not in the original plan:** Final Verification's real end-to-end check (see below) found that `laravel.test`'s actual web-serving process (`php artisan serve`, run as the unprivileged `sail` user via Sail's `gosu`-based entrypoint) cannot reach `/var/run/docker.sock` even after adding `group_add: ['0']` to `compose.yaml` — confirmed via direct evidence, not guessed: `docker exec --user sail ... id` shows group 0 present (a *fresh* `docker exec` does inherit Compose's `group_add`), but the actual long-running `artisan serve` process (PID 16, checked via `cat /proc/16/status`) only has group 20 — because Sail's entrypoint (`gosu $WWWUSER "$@"`) resets the process's supplementary groups from `/etc/group`'s static membership when dropping from root to `sail`, discarding whatever Docker's `group_add` added at the container-credential level. `group_add` and `docker exec --user` both bypass `/etc/group`; `gosu` does not.

Two other options were considered and rejected: running the whole app as root inside the container (broadens the app's privilege far beyond what this one feature needs), and editing the sail user's `/etc/group` membership at build time via the *vendored* `laravel/sail` Dockerfile (fragile — gets silently discarded on `composer update laravel/sail`).

Instead: add `tecnativa/docker-socket-proxy`, a small, purpose-built proxy container. It mounts the real socket itself (as its own minimal, single-purpose image — not `laravel.test`), and exposes a *restricted* plain-HTTP API over the internal `sail` network. `DockerEngineClient` talks to the proxy over the network instead of the raw socket — no unix-socket curl option, no container user/group permission juggling at all. This is exactly the "socket-proxy restricted to this one container's lifecycle operations" hardening step the original design doc (`docs/pipeline-nwws-filament-blueprint.md`, section 3, per-scoping-decision #3) already flagged as deferred production hardening — implementing it now to unblock verification also happens to satisfy that deferred item, not scope creep for its own sake.

The proxy is configured with the minimum surface this feature needs: container inspect (`CONTAINERS=1`) and exactly three write actions (`ALLOW_START=1`/`ALLOW_STOP=1`/`ALLOW_RESTARTS=1`, gated by the global `POST=1` toggle) — no images, networks, volumes, exec, build, swarm, or anything else. This is *not* scoped to the single container name `wxnotify-nwws` specifically (the proxy image doesn't support per-container-name scoping, only per-resource-type/per-action), so in principle it could start/stop/restart any container on the host, not just this one — `DockerEngineClient` itself still only ever calls it with the fixed name `'wxnotify-nwws'`, so no *feature* exposes broader control, but this is worth knowing if the threat model here ever gets more adversarial than "trusted staff clicking a button."

**Files:**
- Modify: `compose.yaml` (add the proxy service, remove `laravel.test`'s socket bind-mount + `group_add`)
- Modify: `app/Support/Pipeline/DockerEngineClient.php`
- Modify: `config/services.php`
- Modify: `.env.example`
- Modify: `tests/Feature/DockerEngineClientTest.php` (fake URLs change host)

- [ ] **Step 1: Add the proxy service to `compose.yaml`, remove the direct socket mount from `laravel.test`**

Add a new service (place it alongside `nwws`/`queue`, matching the file's existing ordering/indentation style):

```yaml
    docker-socket-proxy:
        image: tecnativa/docker-socket-proxy:0.2.0
        container_name: wxnotify-docker-socket-proxy
        environment:
            CONTAINERS: 1
            ALLOW_START: 1
            ALLOW_STOP: 1
            ALLOW_RESTARTS: 1
            POST: 1
            AUTH: 0
            BUILD: 0
            COMMIT: 0
            CONFIGS: 0
            DISTRIBUTION: 0
            EXEC: 0
            IMAGES: 0
            NETWORKS: 0
            NODES: 0
            PLUGINS: 0
            SECRETS: 0
            SERVICES: 0
            SESSION: 0
            SWARM: 0
            SYSTEM: 0
            TASKS: 0
            VOLUMES: 0
        volumes:
            - '/var/run/docker.sock:/var/run/docker.sock:ro'
        networks:
            - sail
        restart: unless-stopped
```

In `laravel.test`'s service definition, remove these (added in the now-superseded direct-socket-access attempt):
- The `- '/var/run/docker.sock:/var/run/docker.sock'` volume line (and its explanatory comment).
- The `group_add: ['0']` key and its explanatory comment.

`laravel.test` needs no other change — it can already reach `docker-socket-proxy` by service name over the existing `sail` network with no extra config.

- [ ] **Step 2: Update `DockerEngineClient` to talk to the proxy over HTTP instead of the raw socket**

Read the current file first — it was hardened in an earlier fix (config-driven socket path, 5s timeout, safe status parsing); keep all of that, only change how the request is transported.

Replace the `request()` method and the config key it reads:

```php
private function request(string $method, string $path): Response
{
    return Http::baseUrl(config('services.docker.proxy_url'))
        ->timeout(5)
        ->{$method}('/'.self::API_VERSION.$path);
}
```

Remove the now-unused `CURLOPT_UNIX_SOCKET_PATH` import/option entirely — there is no more curl-specific socket option, this is a plain HTTP call.

- [ ] **Step 3: Update `config/services.php`**

Replace the `docker.socket_path` key with `docker.proxy_url`:

```php
    'docker' => [
        'proxy_url' => env('DOCKER_PROXY_URL', 'http://docker-socket-proxy:2375'),
    ],
```

- [ ] **Step 4: Update `.env.example`**

Replace the `DOCKER_SOCKET_PATH=/var/run/docker.sock` line with:

```
DOCKER_PROXY_URL=http://docker-socket-proxy:2375
```

- [ ] **Step 5: Update the fake URLs in `tests/Feature/DockerEngineClientTest.php`**

All 9 existing `Http::fake([...])` calls key on `'localhost/v1.43/containers/wxnotify-nwws/...'` — since requests now go to `docker-socket-proxy:2375` instead of `localhost`, update every occurrence of `'localhost/v1.43/'` to `'docker-socket-proxy:2375/v1.43/'` (a straightforward find-and-replace across the file; the path portion after that host doesn't change). Also update the two `str_contains($request->url(), '/containers/wxnotify-nwws/start')`-style assertions if any hardcode `localhost` (check — they may only check the path suffix, which wouldn't need to change).

- [ ] **Step 6: Bring up the new service, rebuild, run tests**

```bash
docker compose up -d docker-socket-proxy
docker compose up -d laravel.test
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/DockerEngineClientTest.php --compact"
```

Expected: `Tests: 9 passed` (same count as before — this task changes *how* the client connects, not its test count).

- [ ] **Step 7: Confirm the proxy actually works against the real container**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "curl -s http://docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json | head -c 200"
```

Expected: real JSON starting with something like `{"Id":"...`, not a connection error. This confirms `laravel.test` (as the unprivileged `sail` user, no special groups needed) can reach the proxy, and the proxy can reach the real socket.

- [ ] **Step 8: Pint, full suite, commit**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent && vendor/bin/pest --compact"
git add compose.yaml app/Support/Pipeline/DockerEngineClient.php config/services.php .env.example tests/Feature/DockerEngineClientTest.php
git commit -m "Route Docker control through a socket proxy, not a raw bind mount

Sail's entrypoint drops laravel.test from root to the unprivileged
sail user via gosu, which resets supplementary groups from /etc/group
— Compose's group_add doesn't survive that, so the app process could
never actually reach a directly bind-mounted /var/run/docker.sock no
matter how the mount/group config was tuned (confirmed via
/proc/<pid>/status on the real running process, not guessed).

Replaces the direct bind mount with a tecnativa/docker-socket-proxy
sidecar, scoped to exactly what this feature needs (container inspect
+ start/stop/restart, nothing else) — this also happens to be the
socket-proxy hardening step the original design doc already flagged
as deferred production work.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

The full-suite run must show every test passing.

---

## Final Verification

- [ ] Full Pest suite passes: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact"`
- [ ] Pint clean: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"`
- [ ] `php artisan route:list` unaffected (this plan adds no routes — sanity check only, expect no diff in `/admin/pipeline-control`'s existing route).
- [ ] **Real end-to-end check against the actual `wxnotify-nwws` container**, now that Task 3's proxy is in place: as a seeded staff user in the browser, confirm `/admin/pipeline-control`'s NWWS row shows a real "Running" badge, click Stop and confirm via `docker ps`/`docker compose logs nwws` that the container actually stopped and the badge updates to a non-running color, click Start and confirm it's running again, click Restart (confirm the modal appears) and confirm the container's start time changes.
- [ ] `import/nwws-go-client/` — no change from this plan; still the user's call whether to delete it.
