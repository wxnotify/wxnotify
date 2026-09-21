# Pipeline Control Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the Pipeline Control admin page into a live operations view with one combined health verdict per feed, a summary banner, 24-hour sparklines, recent products (including a shared EMWIN table verifying both EMWIN feeds deliver the same products), and on-demand container logs.

**Architecture:** A new `pipeline_deliveries` table records every delivery that reaches ingest (feed, WMO header key, outcome). `DockerEngineClient` exposes container health and logs. Pure classes (`PipelineFeed`, `FeedHealth`, `PipelineSummary`, `PipelineActivity`) compute health, the banner and the page's data; the Filament page is rewritten around them and polls every 10 seconds.

**Tech Stack:** Laravel 13, Filament v5 (Page, Actions, Blade), Livewire, Pest, PostgreSQL (Sail), inline SVG for sparklines (no new dependencies).

**Spec:** `docs/superpowers/specs/2026-09-21-pipeline-control-redesign-design.md`

## Global Constraints

- Run PHP/tests inside Sail: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact <path>"`. Pint: `docker exec wxnotify-nextgen-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"`. Run Pint before every commit.
- NEVER run `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:seed`, and do NOT run `php artisan migrate` on the dev DB (tests use `RefreshDatabase` inside Sail only). The user runs the migration.
- No new Composer or npm dependencies. Do not edit `CLAUDE.md` (if a hook rewrites it, `git checkout CLAUDE.md`). Do not push.
- Commits end with the trailer `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`. Work on `main`; there are unrelated uncommitted changes in the tree (the emwin-byteblaster rename and pid fix) — stage only the files each task names, never `git add -A`.
- Read the matching `.ai/rules/*.md` before editing (`.ai/rules/index.md`): app/Filament → filament.md, app/Filament/Admin/Pages → pages.md, app/Support → support.md, tests/Feature → feature.md, app/Models → models.md.
- The page stays staff-only (no new routes). Logs are untrusted text: strip control characters, cap size, render escaped. Delivery rows store basenames and header keys only.
- Stale thresholds (minutes): NWWS 15, EMWIN Byteblaster 15, EMWIN HTTP 30. Delivery retention: 48 hours. Poll interval: 10 seconds. Recent lists: NWWS last 8; shared EMWIN table last 10 products; "Waiting" grace 10 minutes; logs: last 200 lines, each capped at 500 characters.
- Feed keys are exactly `nwws`, `emwin` (Byteblaster), `emwin_http` — the same keys `PipelineHeartbeat` uses.
- The `emwin_http` container is `wxnotify-emwin-http`; Byteblaster is `wxnotify-emwin-byteblaster`; NWWS is `wxnotify-nwws`. Never start any container against a real feed as verification.

---

### Task 1: Delivery storage, recording action and pruning

**Files:**
- Create: `database/migrations/2026_09_21_300000_create_pipeline_deliveries_table.php`
- Create: `app/Models/PipelineDelivery.php`
- Create: `database/factories/PipelineDeliveryFactory.php`
- Create: `app/Actions/Pipeline/RecordPipelineDelivery.php`
- Create: `app/Console/Commands/PrunePipelineDeliveries.php`
- Modify: `config/wxnotify.php` (add `pipeline` key)
- Modify: `bootstrap/app.php` (schedule the prune)
- Test: `tests/Feature/PipelineDeliveryTest.php`

**Interfaces:**
- Produces: `App\Models\PipelineDelivery` (columns `feed`, `wmo_header_key`, `file_name`, `outcome`, `received_at`; `public $timestamps = false`; constants `OUTCOME_INGESTED = 'ingested'`, `OUTCOME_DUPLICATE = 'duplicate'`; factory `PipelineDelivery::factory()`).
- Produces: `App\Actions\Pipeline\RecordPipelineDelivery::__invoke(string $feed, ?string $wmoHeaderKey, string $filePath, bool $ingested): void` — best-effort, never throws.
- Produces: config `wxnotify.pipeline.delivery_retention_hours` (int, default 48); artisan command `pipeline:prune-deliveries`.

- [ ] **Step 1: Write the failing test** — create `tests/Feature/PipelineDeliveryTest.php`:

```php
<?php

use App\Actions\Pipeline\RecordPipelineDelivery;
use App\Models\PipelineDelivery;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

test('it records a delivery with the file basename only', function () {
    app(RecordPipelineDelivery::class)('emwin_http', 'wfus53 kbou 191622 torbou', 'http/../deep/dir/TORBOU_1.txt', true);

    $row = PipelineDelivery::sole();

    expect($row->feed)->toBe('emwin_http')
        ->and($row->wmo_header_key)->toBe('wfus53 kbou 191622 torbou')
        ->and($row->file_name)->toBe('TORBOU_1.txt')
        ->and($row->outcome)->toBe('ingested')
        ->and($row->received_at->isToday())->toBeTrue();
});

test('a deduplicated delivery is recorded as a duplicate and a null key is allowed', function () {
    app(RecordPipelineDelivery::class)('emwin', null, 'a.txt', false);

    expect(PipelineDelivery::sole())
        ->outcome->toBe('duplicate')
        ->wmo_header_key->toBeNull();
});

test('a recording failure is swallowed and logged, never thrown', function () {
    Log::spy();
    PipelineDelivery::creating(fn () => throw new RuntimeException('boom'));

    app(RecordPipelineDelivery::class)('nwws', 'k', 'a.txt', true);

    expect(PipelineDelivery::count())->toBe(0);
    Log::shouldHaveReceived('warning')->once();
});

test('the prune command deletes deliveries older than the retention and keeps newer ones', function () {
    config(['wxnotify.pipeline.delivery_retention_hours' => 48]);
    $old = PipelineDelivery::factory()->create(['received_at' => now()->subHours(49)]);
    $recent = PipelineDelivery::factory()->create(['received_at' => now()->subHours(47)]);

    Artisan::call('pipeline:prune-deliveries');

    expect(PipelineDelivery::whereKey($old->id)->exists())->toBeFalse()
        ->and(PipelineDelivery::whereKey($recent->id)->exists())->toBeTrue();
});

test('the prune command is scheduled hourly', function () {
    $events = collect(app(Schedule::class)->events());

    expect($events->contains(fn ($event) => str_contains($event->command, 'pipeline:prune-deliveries')))->toBeTrue();
});
```

- [ ] **Step 2: Run to verify it fails** — `vendor/bin/pest --compact tests/Feature/PipelineDeliveryTest.php`. Expected: FAIL (class/table not found).

- [ ] **Step 3: Implement.** Create the migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('feed');
            $table->string('wmo_header_key')->nullable()->index();
            $table->string('file_name');
            $table->string('outcome');
            $table->timestamp('received_at');
            $table->index(['feed', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_deliveries');
    }
};
```

Create `app/Models/PipelineDelivery.php`:

```php
<?php

namespace App\Models;

use Database\Factories\PipelineDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['feed', 'wmo_header_key', 'file_name', 'outcome', 'received_at'])]
class PipelineDelivery extends Model
{
    /** @use HasFactory<PipelineDeliveryFactory> */
    use HasFactory;

    public const OUTCOME_INGESTED = 'ingested';

    public const OUTCOME_DUPLICATE = 'duplicate';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }
}
```

Create `database/factories/PipelineDeliveryFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\PipelineDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelineDelivery>
 */
class PipelineDeliveryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'feed' => 'nwws',
            'wmo_header_key' => 'wfus53 kbou '.fake()->numerify('######').' torbou',
            'file_name' => fake()->lexify('????????').'.txt',
            'outcome' => PipelineDelivery::OUTCOME_INGESTED,
            'received_at' => now(),
        ];
    }

    public function forFeed(string $feed): static
    {
        return $this->state(['feed' => $feed]);
    }

    public function duplicate(): static
    {
        return $this->state(['outcome' => PipelineDelivery::OUTCOME_DUPLICATE]);
    }
}
```

Create `app/Actions/Pipeline/RecordPipelineDelivery.php`:

```php
<?php

namespace App\Actions\Pipeline;

use App\Models\PipelineDelivery;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecordPipelineDelivery
{
    public function __invoke(string $feed, ?string $wmoHeaderKey, string $filePath, bool $ingested): void
    {
        try {
            PipelineDelivery::create([
                'feed' => $feed,
                'wmo_header_key' => $wmoHeaderKey,
                'file_name' => mb_substr(basename($filePath), 0, 255),
                'outcome' => $ingested ? PipelineDelivery::OUTCOME_INGESTED : PipelineDelivery::OUTCOME_DUPLICATE,
                'received_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to record a pipeline delivery', ['feed' => $feed, 'error' => $e->getMessage()]);
        }
    }
}
```

Create the command (`php artisan make:command PrunePipelineDeliveries --no-interaction`, then fill):

```php
<?php

namespace App\Console\Commands;

use App\Models\PipelineDelivery;
use Illuminate\Console\Command;

class PrunePipelineDeliveries extends Command
{
    protected $signature = 'pipeline:prune-deliveries';

    protected $description = 'Delete pipeline delivery records older than the retention window';

    public function handle(): int
    {
        $hours = (int) config('wxnotify.pipeline.delivery_retention_hours', 48);

        $deleted = PipelineDelivery::where('received_at', '<', now()->subHours($hours))->delete();

        $this->info("Pruned {$deleted} pipeline deliveries older than {$hours} hours.");

        return self::SUCCESS;
    }
}
```

In `config/wxnotify.php` add before the closing `];`:

```php
    'pipeline' => [
        'delivery_retention_hours' => (int) env('PIPELINE_DELIVERY_RETENTION_HOURS', 48),
    ],
```

In `bootstrap/app.php` inside `withSchedule` add `$schedule->command('pipeline:prune-deliveries')->hourly();`.

- [ ] **Step 4: Run to verify it passes** — `vendor/bin/pest --compact tests/Feature/PipelineDeliveryTest.php`. Expected: 5 passed.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent   # (inside Sail)
git add database/migrations/2026_09_21_300000_create_pipeline_deliveries_table.php app/Models/PipelineDelivery.php database/factories/PipelineDeliveryFactory.php app/Actions/Pipeline/RecordPipelineDelivery.php app/Console/Commands/PrunePipelineDeliveries.php config/wxnotify.php bootstrap/app.php tests/Feature/PipelineDeliveryTest.php
git commit -m "Record pipeline deliveries and prune them after 48 hours

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 2: Ingest controllers record deliveries

**Files:**
- Modify: `app/Http/Controllers/IngestEmwinProductController.php`
- Modify: `app/Http/Controllers/IngestNwwsProductController.php`
- Test: `tests/Feature/IngestEmwinProductControllerTest.php` (append), `tests/Feature/IngestNwwsProductControllerTest.php` (append)

**Interfaces:**
- Consumes: `RecordPipelineDelivery::__invoke(string $feed, ?string $wmoHeaderKey, string $filePath, bool $ingested): void` (Task 1); `IngestOncePerWmoHeader::__invoke(?string $key, Closure $ingest): bool` (returns true when ingested).
- Produces: a `pipeline_deliveries` row per accepted post: feed `nwws` / `emwin` / `emwin_http`, the header key, basename, outcome.

- [ ] **Step 1: Write the failing tests.** Append to `tests/Feature/IngestEmwinProductControllerTest.php` (uses the file's existing `emwinApiPost`/`emwinApiWarning` helpers and `beforeEach`; add `use App\Models\PipelineDelivery;` and `use App\Actions\Pipeline\RecordPipelineDelivery;` at the top if missing):

```php
test('an ingested byteblaster post records an ingested delivery for the emwin feed', function () {
    File::put($this->emwinRoot.'/delivered/kbou/a.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/a.txt')->assertNoContent();

    expect(PipelineDelivery::sole())
        ->feed->toBe('emwin')
        ->wmo_header_key->toBe('wfus53 kbou 191622 torbou')
        ->file_name->toBe('a.txt')
        ->outcome->toBe('ingested');
});

test('an http post records the emwin_http feed and a deduplicated one records a duplicate', function () {
    IngestedProduct::factory()->create(['file_path' => 'x/y.txt', 'wmo_header_key' => 'wfus53 kbou 191622 torbou']);
    File::put($this->emwinRoot.'/delivered/kbou/dup.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/dup.txt', 'http')->assertNoContent();

    expect(PipelineDelivery::sole())
        ->feed->toBe('emwin_http')
        ->outcome->toBe('duplicate');
});

test('a failure to record a delivery never fails the ingest request', function () {
    PipelineDelivery::creating(fn () => throw new RuntimeException('boom'));
    File::put($this->emwinRoot.'/delivered/kbou/a.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/a.txt')->assertNoContent();

    expect(IngestedProduct::count())->toBe(1);
});

test('a rejected path records no delivery', function () {
    emwinApiPost($this, 'missing.txt')->assertNotFound();

    expect(PipelineDelivery::count())->toBe(0);
});
```

Append to `tests/Feature/IngestNwwsProductControllerTest.php` (reuses its `beforeEach` temp products root; add `use App\Models\PipelineDelivery;`):

```php
test('an ingested nwws product records a delivery for the nwws feed', function () {
    File::put($this->productsRoot.'/kkci/kkci_ftus21-tafkord.221432_12345.txt', "FTUS21 KKCI 221432\nTAFORD\nsample");

    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'kkci/kkci_ftus21-tafkord.221432_12345.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    expect(PipelineDelivery::sole())
        ->feed->toBe('nwws')
        ->file_name->toBe('kkci_ftus21-tafkord.221432_12345.txt')
        ->outcome->toBe('ingested');
});
```

- [ ] **Step 2: Run to verify they fail** — `vendor/bin/pest --compact tests/Feature/IngestEmwinProductControllerTest.php tests/Feature/IngestNwwsProductControllerTest.php --filter="delivery|records"`. Expected: FAIL (no rows).

- [ ] **Step 3: Implement.** In `IngestEmwinProductController`: add `use App\Actions\Pipeline\RecordPipelineDelivery;`, inject `private readonly RecordPipelineDelivery $recordPipelineDelivery` into the constructor, compute `$feed` once and reuse it, and record after the ingest:

```php
        $feed = ($validated['feed'] ?? 'byteblaster') === 'http' ? 'emwin_http' : 'emwin';

        PipelineHeartbeat::record($feed);
        // ... path validation and $identity exactly as today ...

        $ingested = ($this->ingestOncePerWmoHeader)($identity['key'] ?? null, fn () => ($this->ingestProductText)(
            // ... arguments exactly as today ...
        ));

        ($this->recordPipelineDelivery)($feed, $identity['key'] ?? null, $validated['path'], $ingested);

        return response()->noContent();
```

In `IngestNwwsProductController`: same injection; change the ingest call to capture the boolean and record:

```php
        $ingested = ($this->ingestOncePerWmoHeader)($key, fn () => ($this->ingestProductText)($text, $validated['path'], $parsed, $isCap, ['source' => 'nwws', 'wmo_header_key' => $key]));

        ($this->recordPipelineDelivery)('nwws', $key, $validated['path'], $ingested);
```

Do not move the heartbeat calls or change any other behaviour.

- [ ] **Step 4: Run to verify they pass** — run both controller test files in full. Expected: all pass (existing tests unchanged).

- [ ] **Step 5: Commit** (Pint first; `git add` the two controllers and two test files; message "Record a pipeline delivery for every accepted NWWS and EMWIN post" with the trailer).

---

### Task 3: DockerEngineClient — container health and logs

**Files:**
- Modify: `app/Support/Pipeline/DockerEngineClient.php`
- Test: `tests/Feature/DockerEngineClientTest.php` (append; find the existing client test file with `grep -rl DockerEngineClient tests` and append there instead if it has a different name)

**Interfaces:**
- Produces: `DockerEngineClient::state(string $container): array{status: string, health: ?string}` — `status` is Docker's `State.Status` or `'not_found'` on 404; `health` is `State.Health.Status` (`starting|healthy|unhealthy`) or null when the container defines no healthcheck. Throws like `status()` does for an unexpected shape or HTTP error.
- Produces: `DockerEngineClient::logs(string $container, int $tail = 200): array<int, string>` — sanitized lines, at most `$tail`, each at most 500 characters plus `…`; `[]` for a missing container; throws on other HTTP errors.
- Keeps: `status()` (now `state($c)['status']`), `start()`, `stop()`, `restart()` unchanged.

- [ ] **Step 1: Write the failing tests:**

```php
use App\Support\Pipeline\DockerEngineClient;
use Illuminate\Support\Facades\Http;

function dockerLogFrame(int $stream, string $payload): string
{
    return pack('CxxxN', $stream, strlen($payload)).$payload;
}

test('state reports the docker health status when a healthcheck exists', function () {
    Http::fake(['*/containers/c1/json' => Http::response(['State' => ['Status' => 'running', 'Health' => ['Status' => 'unhealthy']]])]);

    expect(app(DockerEngineClient::class)->state('c1'))->toBe(['status' => 'running', 'health' => 'unhealthy']);
});

test('state has a null health when the container defines no healthcheck', function () {
    Http::fake(['*/containers/c1/json' => Http::response(['State' => ['Status' => 'exited']])]);

    expect(app(DockerEngineClient::class)->state('c1'))->toBe(['status' => 'exited', 'health' => null]);
});

test('state reports not_found for a missing container and status still works', function () {
    Http::fake(['*/containers/c1/json' => Http::response([], 404)]);

    expect(app(DockerEngineClient::class)->state('c1'))->toBe(['status' => 'not_found', 'health' => null])
        ->and(app(DockerEngineClient::class)->status('c1'))->toBe('not_found');
});

test('logs demultiplexes the docker stream frames into lines', function () {
    $body = dockerLogFrame(1, "first line\nsecond line\n").dockerLogFrame(2, "error line\n");
    Http::fake(['*/containers/c1/logs*' => Http::response($body)]);

    expect(app(DockerEngineClient::class)->logs('c1'))->toBe(['first line', 'second line', 'error line']);
});

test('logs accepts a raw stream from a container running a tty', function () {
    Http::fake(['*/containers/c1/logs*' => Http::response("alpha\nbeta\n")]);

    expect(app(DockerEngineClient::class)->logs('c1'))->toBe(['alpha', 'beta']);
});

test('logs strips ansi sequences and control characters and truncates long lines', function () {
    $long = str_repeat('x', 600);
    $body = dockerLogFrame(1, "\e[31mred\e[0m\x07bell\n{$long}\n");
    Http::fake(['*/containers/c1/logs*' => Http::response($body)]);

    $lines = app(DockerEngineClient::class)->logs('c1');

    expect($lines[0])->toBe('redbell')
        ->and(mb_strlen($lines[1]))->toBe(501)
        ->and($lines[1])->toEndWith('…');
});

test('logs keeps only the last requested lines', function () {
    $body = dockerLogFrame(1, implode("\n", range(1, 10))."\n");
    Http::fake(['*/containers/c1/logs*' => Http::response($body)]);

    expect(app(DockerEngineClient::class)->logs('c1', 3))->toBe(['8', '9', '10']);
});

test('logs returns an empty list for a missing container and throws on other errors', function () {
    Http::fake(['*/containers/gone/logs*' => Http::response('', 404), '*/containers/bad/logs*' => Http::response('', 500)]);

    expect(app(DockerEngineClient::class)->logs('gone'))->toBe([]);
    expect(fn () => app(DockerEngineClient::class)->logs('bad'))->toThrow(Illuminate\Http\Client\RequestException::class);
});
```

- [ ] **Step 2: Run to verify they fail** — expected FAIL (`state`/`logs` undefined).

- [ ] **Step 3: Implement** — in `DockerEngineClient` replace `status()` with the following and add the helpers:

```php
    /**
     * @return array{status: string, health: ?string}
     */
    public function state(string $container): array
    {
        $response = $this->request('get', "/containers/{$container}/json");

        if ($response->status() === 404) {
            return ['status' => 'not_found', 'health' => null];
        }

        $response->throw();

        $status = $response->json('State.Status');

        if (! is_string($status) || $status === '') {
            throw new RuntimeException("Docker returned an unexpected response shape for container [{$container}].");
        }

        $health = $response->json('State.Health.Status');

        return ['status' => $status, 'health' => is_string($health) && $health !== '' ? $health : null];
    }

    public function status(string $container): string
    {
        return $this->state($container)['status'];
    }

    /**
     * @return array<int, string>
     */
    public function logs(string $container, int $tail = 200): array
    {
        $response = $this->request('get', "/containers/{$container}/logs?stdout=1&stderr=1&tail={$tail}");

        if ($response->status() === 404) {
            return [];
        }

        $response->throw();

        $lines = [];

        foreach (explode("\n", $this->demultiplex($response->body())) as $line) {
            $line = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $line) ?? '';
            $line = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $line) ?? '';
            $line = mb_convert_encoding($line, 'UTF-8', 'UTF-8');
            $lines[] = mb_strlen($line) > 500 ? mb_substr($line, 0, 500).'…' : $line;
        }

        if (end($lines) === '') {
            array_pop($lines);
        }

        return array_slice($lines, -$tail);
    }

    private function demultiplex(string $body): string
    {
        if (strlen($body) < 8 || ! in_array(ord($body[0]), [0, 1, 2], true) || substr($body, 1, 3) !== "\0\0\0") {
            return $body;
        }

        $output = '';
        $offset = 0;
        $length = strlen($body);

        while ($offset + 8 <= $length) {
            $size = unpack('N', substr($body, $offset + 4, 4))[1];
            $output .= substr($body, $offset + 8, $size);
            $offset += 8 + $size;
        }

        return $output;
    }
```

Update the class docblock sentence "Controls one fixed, known sidecar container" to say it controls the fixed, known pipeline containers.

- [ ] **Step 4: Run to verify they pass** — the whole client test file plus `tests/Feature/PipelineControlTest.php` (still passing: its fakes return `State.Status` only, so `health` is null). Expected: pass.

- [ ] **Step 5: Commit** ("Expose container health and sanitized logs on DockerEngineClient", Pint first, stage only the client and its test file).

---

### Task 4: PipelineFeed, FeedHealth and PipelineSummary

**Files:**
- Create: `app/Enums/PipelineFeed.php`
- Create: `app/Support/Pipeline/FeedHealth.php`
- Create: `app/Support/Pipeline/PipelineSummary.php`
- Modify: `config/wxnotify.php` (add `stale_minutes` under `pipeline`)
- Test: `tests/Feature/FeedHealthTest.php`

**Interfaces:**
- Produces: `App\Enums\PipelineFeed` (string-backed: `Nwws='nwws'`, `Emwin='emwin'`, `EmwinHttp='emwin_http'`) with `label(): string` ('NWWS' / 'EMWIN Byteblaster' / 'EMWIN HTTP backup'), `container(): string`, `isPrimary(): bool` (Nwws and Emwin true), `role(): string`, `icon(): Heroicon`, `staleMinutes(): int` (from config).
- Produces: `App\Support\Pipeline\FeedHealth` — `public readonly string $state`, `$label`, `$color` (`success|warning|danger|gray`); `static evaluate(string $dockerStatus, ?string $dockerHealth, ?CarbonInterface $lastProductAt, int $staleMinutes): self`. States: `unknown, not_found, stopped, down, restarting, unhealthy, starting, stale, healthy`.
- Produces: `App\Support\Pipeline\PipelineSummary` — `public readonly string $color` (`success|warning|danger`), `$headline`, `array $notes`; `static evaluate(array $healthByFeed): self` where the array is keyed by feed value (`nwws`, `emwin`, `emwin_http`) with `FeedHealth` values.

- [ ] **Step 1: Write the failing tests** — `tests/Feature/FeedHealthTest.php`:

```php
<?php

use App\Enums\PipelineFeed;
use App\Support\Pipeline\FeedHealth;
use App\Support\Pipeline\PipelineSummary;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setTestNow('2026-09-21 12:00:00'));

afterEach(fn () => Carbon::setTestNow());

test('docker state maps to the expected health state', function (string $docker, ?string $health, string $state, string $color) {
    $result = FeedHealth::evaluate($docker, $health, now(), 15);

    expect($result->state)->toBe($state)->and($result->color)->toBe($color);
})->with([
    'unreachable' => ['unknown', null, 'unknown', 'warning'],
    'missing' => ['not_found', null, 'not_found', 'gray'],
    'exited' => ['exited', null, 'stopped', 'gray'],
    'dead' => ['dead', null, 'down', 'danger'],
    'restarting' => ['restarting', null, 'restarting', 'warning'],
    'paused' => ['paused', null, 'restarting', 'warning'],
    'created' => ['created', null, 'restarting', 'warning'],
    'unhealthy' => ['running', 'unhealthy', 'unhealthy', 'danger'],
    'starting' => ['running', 'starting', 'starting', 'warning'],
    'healthy with healthcheck' => ['running', 'healthy', 'healthy', 'success'],
    'healthy without healthcheck' => ['running', null, 'healthy', 'success'],
    'unrecognised docker status' => ['removing', null, 'unknown', 'warning'],
]);

test('a running feed with no product ever is stale', function () {
    expect(FeedHealth::evaluate('running', null, null, 15))->state->toBe('stale')->label->toBe('Stale');
});

test('staleness is judged at the threshold boundary', function () {
    expect(FeedHealth::evaluate('running', null, now()->subMinutes(15), 15)->state)->toBe('healthy')
        ->and(FeedHealth::evaluate('running', null, now()->subMinutes(15)->subSecond(), 15)->state)->toBe('stale');
});

test('unhealthy beats stale and a stopped feed is never stale', function () {
    expect(FeedHealth::evaluate('running', 'unhealthy', null, 15)->state)->toBe('unhealthy')
        ->and(FeedHealth::evaluate('exited', null, null, 15)->state)->toBe('stopped');
});

test('the feed enum exposes labels, containers, roles and configured thresholds', function () {
    expect(PipelineFeed::Emwin->label())->toBe('EMWIN Byteblaster')
        ->and(PipelineFeed::EmwinHttp->container())->toBe('wxnotify-emwin-http')
        ->and(PipelineFeed::Nwws->container())->toBe('wxnotify-nwws')
        ->and(PipelineFeed::Emwin->container())->toBe('wxnotify-emwin-byteblaster')
        ->and(PipelineFeed::Nwws->isPrimary())->toBeTrue()
        ->and(PipelineFeed::EmwinHttp->isPrimary())->toBeFalse()
        ->and(PipelineFeed::Nwws->staleMinutes())->toBe(15)
        ->and(PipelineFeed::Emwin->staleMinutes())->toBe(15)
        ->and(PipelineFeed::EmwinHttp->staleMinutes())->toBe(30);
});

function healthMap(string $nwws, string $emwin, string $http): array
{
    $make = fn (string $state) => match ($state) {
        'healthy' => FeedHealth::evaluate('running', null, now(), 15),
        'stale' => FeedHealth::evaluate('running', null, null, 15),
        'unhealthy' => FeedHealth::evaluate('running', 'unhealthy', now(), 15),
        'stopped' => FeedHealth::evaluate('exited', null, null, 15),
    };

    return ['nwws' => $make($nwws), 'emwin' => $make($emwin), 'emwin_http' => $make($http)];
}

test('the summary is green when every feed is healthy', function () {
    $summary = PipelineSummary::evaluate(healthMap('healthy', 'healthy', 'healthy'));

    expect($summary->color)->toBe('success')->and($summary->headline)->toBe('All feeds healthy')->and($summary->notes)->toBe([]);
});

test('a stopped backup is a note and does not change the color', function () {
    $summary = PipelineSummary::evaluate(healthMap('healthy', 'healthy', 'stopped'));

    expect($summary->color)->toBe('success')
        ->and($summary->headline)->toBe('All running feeds healthy')
        ->and($summary->notes)->toBe(['EMWIN HTTP backup is stopped']);
});

test('a stale feed makes the summary amber and names the feed', function () {
    $summary = PipelineSummary::evaluate(healthMap('healthy', 'healthy', 'stale'));

    expect($summary->color)->toBe('warning')->and($summary->headline)->toBe('Needs attention: EMWIN HTTP backup (Stale)');
});

test('an unhealthy feed or a stopped primary makes the summary red', function () {
    expect(PipelineSummary::evaluate(healthMap('unhealthy', 'healthy', 'healthy'))->color)->toBe('danger')
        ->and(PipelineSummary::evaluate(healthMap('healthy', 'stopped', 'healthy'))->color)->toBe('danger')
        ->and(PipelineSummary::evaluate(healthMap('healthy', 'stopped', 'healthy'))->headline)->toBe('Needs attention: EMWIN Byteblaster (Stopped)');
});
```

- [ ] **Step 2: Run to verify it fails** — expected FAIL (classes missing).

- [ ] **Step 3: Implement.** Add to `config/wxnotify.php` `pipeline`:

```php
        'stale_minutes' => [
            'nwws' => (int) env('PIPELINE_STALE_MINUTES_NWWS', 15),
            'emwin' => (int) env('PIPELINE_STALE_MINUTES_EMWIN', 15),
            'emwin_http' => (int) env('PIPELINE_STALE_MINUTES_EMWIN_HTTP', 30),
        ],
```

`app/Enums/PipelineFeed.php` (confirm the three `Heroicon::Outlined*` cases exist under `vendor/filament/support` — swap for similar existing ones if not):

```php
<?php

namespace App\Enums;

use Filament\Support\Icons\Heroicon;

enum PipelineFeed: string
{
    case Nwws = 'nwws';
    case Emwin = 'emwin';
    case EmwinHttp = 'emwin_http';

    public function label(): string
    {
        return match ($this) {
            self::Nwws => 'NWWS',
            self::Emwin => 'EMWIN Byteblaster',
            self::EmwinHttp => 'EMWIN HTTP backup',
        };
    }

    public function container(): string
    {
        return match ($this) {
            self::Nwws => 'wxnotify-nwws',
            self::Emwin => 'wxnotify-emwin-byteblaster',
            self::EmwinHttp => 'wxnotify-emwin-http',
        };
    }

    public function isPrimary(): bool
    {
        return $this !== self::EmwinHttp;
    }

    public function role(): string
    {
        return match ($this) {
            self::Nwws => 'Primary · NWWS-OI XMPP feed',
            self::Emwin => 'Primary · satellite EMWIN via Byteblaster',
            self::EmwinHttp => 'Backup · NOAA EMWIN HTTP polling',
        };
    }

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Nwws => Heroicon::OutlinedSignal,
            self::Emwin => Heroicon::OutlinedRss,
            self::EmwinHttp => Heroicon::OutlinedCloudArrowDown,
        };
    }

    public function staleMinutes(): int
    {
        return (int) config("wxnotify.pipeline.stale_minutes.{$this->value}");
    }
}
```

`app/Support/Pipeline/FeedHealth.php`:

```php
<?php

namespace App\Support\Pipeline;

use Carbon\CarbonInterface;

final class FeedHealth
{
    public function __construct(
        public readonly string $state,
        public readonly string $label,
        public readonly string $color,
    ) {}

    public static function evaluate(string $dockerStatus, ?string $dockerHealth, ?CarbonInterface $lastProductAt, int $staleMinutes): self
    {
        return match (true) {
            $dockerStatus === 'unknown' => new self('unknown', 'Unknown', 'warning'),
            $dockerStatus === 'not_found' => new self('not_found', 'Not found', 'gray'),
            $dockerStatus === 'exited' => new self('stopped', 'Stopped', 'gray'),
            $dockerStatus === 'dead' => new self('down', 'Down', 'danger'),
            in_array($dockerStatus, ['restarting', 'paused', 'created'], true) => new self('restarting', 'Restarting', 'warning'),
            $dockerStatus !== 'running' => new self('unknown', 'Unknown', 'warning'),
            $dockerHealth === 'unhealthy' => new self('unhealthy', 'Unhealthy', 'danger'),
            $dockerHealth === 'starting' => new self('starting', 'Starting', 'warning'),
            $lastProductAt === null || $lastProductAt->lt(now()->subMinutes($staleMinutes)) => new self('stale', 'Stale', 'warning'),
            default => new self('healthy', 'Healthy', 'success'),
        };
    }
}
```

`app/Support/Pipeline/PipelineSummary.php`:

```php
<?php

namespace App\Support\Pipeline;

use App\Enums\PipelineFeed;

final class PipelineSummary
{
    /**
     * @param  array<int, string>  $notes
     */
    public function __construct(
        public readonly string $color,
        public readonly string $headline,
        public readonly array $notes,
    ) {}

    /**
     * @param  array<string, FeedHealth>  $healthByFeed  keyed by PipelineFeed value
     */
    public static function evaluate(array $healthByFeed): self
    {
        $attention = [];
        $notes = [];
        $color = 'success';

        foreach (PipelineFeed::cases() as $feed) {
            $health = $healthByFeed[$feed->value];
            $stoppedLike = in_array($health->state, ['stopped', 'not_found'], true);

            if ($stoppedLike && ! $feed->isPrimary()) {
                $notes[] = $feed->label().' is '.strtolower($health->label);

                continue;
            }

            $isRed = $health->color === 'danger' || ($stoppedLike && $feed->isPrimary());
            $isAmber = $health->color === 'warning';

            if ($isRed || $isAmber) {
                $attention[] = $feed->label().' ('.$health->label.')';
                $color = $isRed ? 'danger' : ($color === 'danger' ? 'danger' : 'warning');
            }
        }

        $headline = match (true) {
            $attention !== [] => 'Needs attention: '.implode(', ', $attention),
            $notes !== [] => 'All running feeds healthy',
            default => 'All feeds healthy',
        };

        return new self($color, $headline, $notes);
    }
}
```

- [ ] **Step 4: Run to verify it passes** — `tests/Feature/FeedHealthTest.php`. Expected: all pass.

- [ ] **Step 5: Commit** ("Add feed health, summary and feed definitions"; Pint; stage the enum, the two support classes, config and the test).

---

### Task 5: PipelineActivity queries

**Files:**
- Create: `app/Support/Pipeline/PipelineActivity.php`
- Test: `tests/Feature/PipelineActivityTest.php`

**Interfaces:**
- Consumes: `PipelineDelivery` (Task 1); `PipelineFeed` (Task 4).
- Produces: `PipelineActivity` with:
  - `hourlyCounts(PipelineFeed $feed): array<int, int>` — exactly 24 counts, oldest hour first, the last being the current hour.
  - `recent(PipelineFeed $feed, int $limit = 8): Collection<int, PipelineDelivery>` — newest first.
  - `emwinComparison(int $limit = 10): Collection<int, array{label: string, comparable: bool, first_seen: Carbon, cells: array{emwin: array{state: string, at: ?Carbon}, emwin_http: array{state: string, at: ?Carbon}}}>` — cell `state` is `delivered`, `waiting` (missing and the product first seen under 10 minutes ago), `missing` (missing and older), or `unmatched` (a null-key product, on the feed that did not deliver it).
  - `ingestedToday(): int`.
  - Every method returns empty/zero (never throws) when the `pipeline_deliveries` table does not exist.

- [ ] **Step 1: Write the failing tests** — `tests/Feature/PipelineActivityTest.php`:

```php
<?php

use App\Enums\PipelineFeed;
use App\Models\PipelineDelivery;
use App\Support\Pipeline\PipelineActivity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => Carbon::setTestNow('2026-09-21 12:30:00'));

afterEach(fn () => Carbon::setTestNow());

test('hourly counts returns 24 buckets oldest first for one feed only', function () {
    PipelineDelivery::factory()->count(2)->create(['received_at' => now()]);
    PipelineDelivery::factory()->create(['received_at' => now()->subHours(3)]);
    PipelineDelivery::factory()->create(['received_at' => now()->subHours(23)]);
    PipelineDelivery::factory()->create(['received_at' => now()->subHours(24)->subMinute()]);
    PipelineDelivery::factory()->forFeed('emwin')->create(['received_at' => now()]);

    $counts = app(PipelineActivity::class)->hourlyCounts(PipelineFeed::Nwws);

    expect($counts)->toHaveCount(24)
        ->and($counts[23])->toBe(2)
        ->and($counts[20])->toBe(1)
        ->and($counts[0])->toBe(1)
        ->and(array_sum($counts))->toBe(4);
});

test('recent returns the newest deliveries for the feed limited to the count', function () {
    foreach (range(1, 10) as $i) {
        PipelineDelivery::factory()->create(['file_name' => "n{$i}.txt", 'received_at' => now()->subMinutes(20 - $i)]);
    }
    PipelineDelivery::factory()->forFeed('emwin')->create(['file_name' => 'other.txt']);

    $recent = app(PipelineActivity::class)->recent(PipelineFeed::Nwws, 8);

    expect($recent)->toHaveCount(8)
        ->and($recent->first()->file_name)->toBe('n10.txt')
        ->and($recent->pluck('file_name'))->not->toContain('other.txt');
});

test('the emwin comparison shows a product delivered by both feeds with both times', function () {
    PipelineDelivery::factory()->forFeed('emwin')->create(['wmo_header_key' => 'k1', 'received_at' => now()->subMinutes(30)]);
    PipelineDelivery::factory()->forFeed('emwin_http')->duplicate()->create(['wmo_header_key' => 'k1', 'received_at' => now()->subMinutes(28)]);

    $rows = app(PipelineActivity::class)->emwinComparison();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['label'])->toBe('K1')
        ->and($rows[0]['comparable'])->toBeTrue()
        ->and($rows[0]['cells']['emwin']['state'])->toBe('delivered')
        ->and($rows[0]['cells']['emwin_http']['state'])->toBe('delivered')
        ->and($rows[0]['cells']['emwin_http']['at']->equalTo(now()->subMinutes(28)))->toBeTrue();
});

test('a product on one feed is waiting for ten minutes and missing after that', function () {
    PipelineDelivery::factory()->forFeed('emwin')->create(['wmo_header_key' => 'young', 'received_at' => now()->subMinutes(9)]);
    PipelineDelivery::factory()->forFeed('emwin')->create(['wmo_header_key' => 'old', 'received_at' => now()->subMinutes(11)]);

    $rows = app(PipelineActivity::class)->emwinComparison()->keyBy('label');

    expect($rows['YOUNG']['cells']['emwin_http']['state'])->toBe('waiting')
        ->and($rows['OLD']['cells']['emwin_http']['state'])->toBe('missing')
        ->and($rows['OLD']['cells']['emwin']['state'])->toBe('delivered');
});

test('a product without a header key is listed on its own feed and unmatched on the other', function () {
    PipelineDelivery::factory()->forFeed('emwin_http')->create(['wmo_header_key' => null, 'file_name' => 'nohdr.txt']);

    $rows = app(PipelineActivity::class)->emwinComparison();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['label'])->toBe('nohdr.txt')
        ->and($rows[0]['comparable'])->toBeFalse()
        ->and($rows[0]['cells']['emwin_http']['state'])->toBe('delivered')
        ->and($rows[0]['cells']['emwin']['state'])->toBe('unmatched');
});

test('the emwin comparison is ordered newest first, capped, and ignores nwws', function () {
    foreach (range(1, 12) as $i) {
        PipelineDelivery::factory()->forFeed('emwin')->create(['wmo_header_key' => "k{$i}", 'received_at' => now()->subMinutes(60 - $i)]);
    }
    PipelineDelivery::factory()->create(['wmo_header_key' => 'nwwsonly']);

    $rows = app(PipelineActivity::class)->emwinComparison(10);

    expect($rows)->toHaveCount(10)
        ->and($rows[0]['label'])->toBe('K12')
        ->and($rows->pluck('label'))->not->toContain('NWWSONLY');
});

test('a second-feed delivery outside the newest window still matches its product', function () {
    PipelineDelivery::factory()->forFeed('emwin')->create(['wmo_header_key' => 'k1', 'received_at' => now()->subMinutes(40)]);
    PipelineDelivery::factory()->forFeed('emwin_http')->create(['wmo_header_key' => 'k1', 'received_at' => now()->subMinutes(38)]);
    foreach (range(1, 30) as $i) {
        PipelineDelivery::factory()->forFeed('emwin')->create(['wmo_header_key' => "n{$i}", 'received_at' => now()->subMinutes(20)->addSeconds($i)]);
    }

    $rows = app(PipelineActivity::class)->emwinComparison(40)->keyBy('label');

    expect($rows['K1']['cells']['emwin_http']['state'])->toBe('delivered');
});

test('ingested today counts only ingested deliveries since midnight', function () {
    PipelineDelivery::factory()->count(2)->create(['received_at' => now()]);
    PipelineDelivery::factory()->duplicate()->create(['received_at' => now()]);
    PipelineDelivery::factory()->create(['received_at' => now()->subDay()]);

    expect(app(PipelineActivity::class)->ingestedToday())->toBe(2);
});

test('every query degrades to empty when the table does not exist', function () {
    Schema::drop('pipeline_deliveries');
    $activity = app(PipelineActivity::class);

    expect($activity->hourlyCounts(PipelineFeed::Nwws))->toBe(array_fill(0, 24, 0))
        ->and($activity->recent(PipelineFeed::Nwws))->toHaveCount(0)
        ->and($activity->emwinComparison())->toHaveCount(0)
        ->and($activity->ingestedToday())->toBe(0);
});
```

- [ ] **Step 2: Run to verify it fails** — expected FAIL (class missing).

- [ ] **Step 3: Implement** `app/Support/Pipeline/PipelineActivity.php`. Notes for the implementer: the project database is PostgreSQL, so `date_trunc` is fine. Wrap each public method's query in the table-exists guard (`Schema::hasTable` checked once per instance) and `try/catch Throwable` returning the empty value.

```php
<?php

namespace App\Support\Pipeline;

use App\Enums\PipelineFeed;
use App\Models\PipelineDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PipelineActivity
{
    private const WAITING_MINUTES = 10;

    private ?bool $tableExists = null;

    /**
     * @return array<int, int>
     */
    public function hourlyCounts(PipelineFeed $feed): array
    {
        $empty = array_fill(0, 24, 0);

        if (! $this->available()) {
            return $empty;
        }

        try {
            $start = now()->startOfHour()->subHours(23);

            $rows = PipelineDelivery::query()
                ->where('feed', $feed->value)
                ->where('received_at', '>=', $start)
                ->selectRaw("date_trunc('hour', received_at) as bucket, count(*) as total")
                ->groupBy('bucket')
                ->pluck('total', 'bucket');
        } catch (Throwable) {
            return $empty;
        }

        $counts = [];

        for ($i = 0; $i < 24; $i++) {
            $key = $start->copy()->addHours($i)->format('Y-m-d H:i:s');
            $counts[] = (int) ($rows[$key] ?? 0);
        }

        return $counts;
    }

    /**
     * @return Collection<int, PipelineDelivery>
     */
    public function recent(PipelineFeed $feed, int $limit = 8): Collection
    {
        if (! $this->available()) {
            return collect();
        }

        try {
            return PipelineDelivery::query()
                ->where('feed', $feed->value)
                ->orderByDesc('received_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    /**
     * @return Collection<int, array{label: string, comparable: bool, first_seen: Carbon, cells: array<string, array{state: string, at: ?Carbon}>}>
     */
    public function emwinComparison(int $limit = 10): Collection
    {
        if (! $this->available()) {
            return collect();
        }

        try {
            $feeds = [PipelineFeed::Emwin->value, PipelineFeed::EmwinHttp->value];
            $since = now()->subDay();

            $keyed = PipelineDelivery::query()
                ->whereIn('feed', $feeds)->whereNotNull('wmo_header_key')->where('received_at', '>=', $since)
                ->selectRaw('wmo_header_key, min(received_at) as first_seen')
                ->groupBy('wmo_header_key')->orderByDesc('first_seen')->limit($limit)
                ->pluck('first_seen', 'wmo_header_key');

            $unkeyed = PipelineDelivery::query()
                ->whereIn('feed', $feeds)->whereNull('wmo_header_key')->where('received_at', '>=', $since)
                ->orderByDesc('received_at')->limit($limit)->get();

            $deliveries = PipelineDelivery::query()
                ->whereIn('feed', $feeds)->whereIn('wmo_header_key', $keyed->keys())->where('received_at', '>=', $since)
                ->get()->groupBy('wmo_header_key');
        } catch (Throwable) {
            return collect();
        }

        $rows = collect();

        foreach ($keyed as $key => $firstSeen) {
            $firstSeen = Carbon::parse($firstSeen);
            $byFeed = $deliveries[$key]->groupBy('feed')->map(fn (Collection $group) => $group->min('received_at'));
            $cells = [];

            foreach ($feeds as $feed) {
                $at = $byFeed[$feed] ?? null;
                $cells[$feed] = [
                    'state' => $at !== null ? 'delivered' : ($firstSeen->gt(now()->subMinutes(self::WAITING_MINUTES)) ? 'waiting' : 'missing'),
                    'at' => $at,
                ];
            }

            $rows->push(['label' => strtoupper((string) $key), 'comparable' => true, 'first_seen' => $firstSeen, 'cells' => $cells]);
        }

        foreach ($unkeyed as $delivery) {
            $cells = [];

            foreach ($feeds as $feed) {
                $cells[$feed] = $feed === $delivery->feed
                    ? ['state' => 'delivered', 'at' => $delivery->received_at]
                    : ['state' => 'unmatched', 'at' => null];
            }

            $rows->push(['label' => $delivery->file_name, 'comparable' => false, 'first_seen' => $delivery->received_at, 'cells' => $cells]);
        }

        return $rows->sortByDesc('first_seen')->take($limit)->values();
    }

    public function ingestedToday(): int
    {
        if (! $this->available()) {
            return 0;
        }

        try {
            return PipelineDelivery::query()
                ->where('outcome', PipelineDelivery::OUTCOME_INGESTED)
                ->where('received_at', '>=', now()->startOfDay())
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function available(): bool
    {
        return $this->tableExists ??= Schema::hasTable('pipeline_deliveries');
    }
}
```

Note the first-seen values from `pluck` are strings; the `min('received_at')` in the per-feed grouping returns Carbon because it goes through the model cast — if it does not on the executing environment, wrap with `Carbon::parse`. If the "table does not exist" test misbehaves inside the `RefreshDatabase` transaction on Postgres, keep the guard and drop only that one test, noting it in the report.

- [ ] **Step 4: Run to verify it passes** — `tests/Feature/PipelineActivityTest.php`. Expected: all pass. If a bucket-key mismatch appears in `hourlyCounts` (timezone), confirm `config('app.timezone')` and keep both sides in the same naive `Y-m-d H:i:s` app-timezone representation.

- [ ] **Step 5: Commit** ("Add PipelineActivity queries for sparklines, recent lists and the EMWIN comparison"; Pint; stage the class and its test).

---

### Task 6: Rewrite the Pipeline Control page

**Files:**
- Modify: `app/Filament/Admin/Pages/PipelineControl.php`
- Modify: `resources/views/filament/admin/pages/pipeline-control.blade.php`
- Create: `resources/views/components/pipeline/sparkline.blade.php`
- Test: `tests/Feature/PipelineControlTest.php` (update + append)

**Interfaces:**
- Consumes: `DockerEngineClient::state()` (Task 3), `PipelineFeed`, `FeedHealth`, `PipelineSummary` (Task 4), `PipelineActivity` (Task 5), `PipelineHeartbeat::lastReceivedAt(string $feed): ?Carbon`.
- Produces (public on the page, used by tests and the view): `summary(): PipelineSummary`, `ingestedToday(): int`, `cards(): array` (one entry per `PipelineFeed`: `['feed' => PipelineFeed, 'health' => FeedHealth, 'lastProduct' => ?string, 'counts' => array<int,int>, 'recent' => Collection|null]`), `emwinComparison(): Collection`. Kept for compatibility and used by existing tests/actions: `nwwsStatus()`, `emwinStatus()`, `emwinHttpStatus()` (return Docker status strings), `emwinLastProductAt()`, `emwinHttpLastProductAt()`, and every existing action method (`startAction`, `stopAction`, `restartAction`, `startEmwinAction`, `stopEmwinAction`, `restartEmwinAction`, `startEmwinHttpAction`, `stopEmwinHttpAction`, `restartEmwinHttpAction`).

**Implementation guidance (read before coding):**
- Read `.ai/rules/filament.md` and `.ai/rules/pages.md`. The admin panel has no custom Vite theme, so only Tailwind classes already present in Filament's compiled CSS render. Build the layout from Filament components (`<x-filament::section>`, `<x-filament::badge>`, `<x-filament::icon>`) and the classes the current view already uses; where a needed utility class does not render, use a small inline `style` instead. Verify in the browser (Step 6).
- Replace the status cache so it stores the whole state: `private array $stateCache` of `['status' => string, 'health' => ?string]`; `resolveState()` returns `['status' => 'unknown', 'health' => null]` on any `Throwable`; `statusOf($container)` returns `stateOf($container)['status']`. Keep `runDockerAction()`'s `unset(...)` of the cache entry for the container.
- Remove the now-unused `colorFor`, `labelFor` and the `*StatusColor()` / `*StatusLabel()` methods (grep the tests first; update any test that calls them).
- Build all data lazily inside methods computed once per render (memoize `PipelineActivity` in a property).

- [ ] **Step 1: Update the existing tests that assert old wording, then write the new failing tests.** Run `grep -n "assertSee\|Running\|Exited" tests/Feature/PipelineControlTest.php`: the health label for an exited container is now "Stopped" (was "Exited") and a running feed with no heartbeat now shows "Stale" (was "Running"); update those assertions. Keep the literals `Last product: never` and the feed labels `EMWIN Byteblaster` / `EMWIN HTTP backup` in the markup so `pipelineLastProduct()` keeps working. Append:

```php
use App\Models\PipelineDelivery;

function fakeDockerRunning(array $overrides = []): void
{
    $default = ['State' => ['Status' => 'running', 'Health' => ['Status' => 'healthy']]];

    Http::fake(array_merge([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response($default),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin-byteblaster/json' => Http::response($default),
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin-http/json' => Http::response($default),
    ], $overrides));
}

test('the summary banner is green when every feed is healthy and fresh', function () {
    fakeDockerRunning();
    foreach (['nwws', 'emwin', 'emwin_http'] as $feed) {
        PipelineHeartbeat::record($feed);
    }

    livewire(PipelineControl::class)->assertSee('All feeds healthy');
});

test('a running feed with no product yet is stale and the banner asks for attention', function () {
    fakeDockerRunning();
    Cache::forget('pipeline.heartbeat.emwin_http');
    PipelineHeartbeat::record('nwws');
    PipelineHeartbeat::record('emwin');

    $component = livewire(PipelineControl::class)->assertSee('Stale')->assertSee('Needs attention: EMWIN HTTP backup (Stale)');

    expect($component->instance()->summary()->color)->toBe('warning');
});

test('an unhealthy primary feed turns the banner red', function () {
    fakeDockerRunning(['docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running', 'Health' => ['Status' => 'unhealthy']]])]);
    foreach (['nwws', 'emwin', 'emwin_http'] as $feed) {
        PipelineHeartbeat::record($feed);
    }

    $component = livewire(PipelineControl::class)->assertSee('Unhealthy');

    expect($component->instance()->summary()->color)->toBe('danger');
});

test('a stopped backup is a note and leaves the banner green', function () {
    fakeDockerRunning(['docker-socket-proxy:2375/v1.43/containers/wxnotify-emwin-http/json' => Http::response(['State' => ['Status' => 'exited']])]);
    PipelineHeartbeat::record('nwws');
    PipelineHeartbeat::record('emwin');

    livewire(PipelineControl::class)->assertSee('All running feeds healthy')->assertSee('EMWIN HTTP backup is stopped');
});

test('the page polls every ten seconds', function () {
    fakeDockerRunning();

    livewire(PipelineControl::class)->assertSeeHtml('wire:poll.10s');
});

test('each card shows how many products the feed received in the last 24 hours', function () {
    fakeDockerRunning();
    PipelineDelivery::factory()->count(3)->create(['received_at' => now()->subMinutes(5)]);

    livewire(PipelineControl::class)->assertSee('3 received in 24h');
});

test('the nwws card lists its recent deliveries', function () {
    fakeDockerRunning();
    PipelineDelivery::factory()->create(['wmo_header_key' => 'ftus21 kkci 221432 taford']);

    livewire(PipelineControl::class)->assertSee('FTUS21 KKCI 221432 TAFORD');
});

test('the shared emwin table shows both feeds delivery state per product', function () {
    fakeDockerRunning();
    PipelineDelivery::factory()->forFeed('emwin')->create(['wmo_header_key' => 'both', 'received_at' => now()->subMinutes(30)]);
    PipelineDelivery::factory()->forFeed('emwin_http')->create(['wmo_header_key' => 'both', 'received_at' => now()->subMinutes(29)]);
    PipelineDelivery::factory()->forFeed('emwin')->create(['wmo_header_key' => 'lonely', 'received_at' => now()->subMinutes(20)]);
    PipelineDelivery::factory()->forFeed('emwin')->create(['wmo_header_key' => 'fresh', 'received_at' => now()->subMinutes(2)]);
    PipelineDelivery::factory()->forFeed('emwin_http')->create(['wmo_header_key' => null, 'file_name' => 'nohdr.txt']);

    livewire(PipelineControl::class)
        ->assertSee('Recent EMWIN products')
        ->assertSee('BOTH')
        ->assertSee('Not received')
        ->assertSee('Waiting')
        ->assertSee('No header to compare')
        ->assertSee('nohdr.txt');
});

test('the page renders with no deliveries and without the deliveries table', function () {
    fakeDockerRunning();
    livewire(PipelineControl::class)->assertSuccessful();

    Schema::drop('pipeline_deliveries');
    livewire(PipelineControl::class)->assertSuccessful()->assertSee('0 received in 24h');
});
```

(add `use Illuminate\Support\Facades\Schema;` to the test file imports.)

- [ ] **Step 2: Run to verify they fail** — `tests/Feature/PipelineControlTest.php`. Expected: the new tests fail (missing `summary()`, wording).

- [ ] **Step 3: Implement the page class.** Add these members and adapt the cache as described above:

```php
    private ?PipelineActivity $activity = null;

    private function activity(): PipelineActivity
    {
        return $this->activity ??= app(PipelineActivity::class);
    }

    private function lastProductFor(PipelineFeed $feed): ?Carbon
    {
        return PipelineHeartbeat::lastReceivedAt($feed->value);
    }

    private function healthFor(PipelineFeed $feed): FeedHealth
    {
        $state = $this->stateOf($feed->container());

        return FeedHealth::evaluate($state['status'], $state['health'], $this->lastProductFor($feed), $feed->staleMinutes());
    }

    /**
     * @return array<int, array{feed: PipelineFeed, health: FeedHealth, lastProduct: ?string, counts: array<int, int>, recent: ?Collection}>
     */
    public function cards(): array
    {
        return array_map(fn (PipelineFeed $feed): array => [
            'feed' => $feed,
            'health' => $this->healthFor($feed),
            'lastProduct' => $this->lastProductFor($feed)?->diffForHumans(),
            'counts' => $this->activity()->hourlyCounts($feed),
            'recent' => $feed === PipelineFeed::Nwws ? $this->activity()->recent($feed) : null,
        ], PipelineFeed::cases());
    }

    public function summary(): PipelineSummary
    {
        $health = [];

        foreach (PipelineFeed::cases() as $feed) {
            $health[$feed->value] = $this->healthFor($feed);
        }

        return PipelineSummary::evaluate($health);
    }

    public function ingestedToday(): int
    {
        return $this->activity()->ingestedToday();
    }

    public function emwinComparison(): Collection
    {
        return $this->activity()->emwinComparison();
    }
```

Rewrite `nwwsStatus()` etc. to `statusOf(PipelineFeed::X->container())`, and `emwinLastProductAt()` / `emwinHttpLastProductAt()` to `$this->lastProductFor(PipelineFeed::X)?->diffForHumans()`. Replace the three `*_CONTAINER` constants with `PipelineFeed::X->container()` (or keep the constants pointing at them) so container names live in one place. Add the needed `use` statements.

Create `resources/views/components/pipeline/sparkline.blade.php`:

```blade
@props(['counts'])
@php
    $max = max(1, max($counts));
    $step = 120 / (count($counts) - 1);
    $points = collect($counts)
        ->map(fn ($count, $i) => round($i * $step, 1).','.round(26 - ($count / $max) * 24, 1))
        ->implode(' ');
@endphp
<svg viewBox="0 0 120 28" preserveAspectRatio="none" role="img" aria-label="Products received per hour over the last 24 hours" style="width:100%;height:28px;color:rgb(var(--primary-500, 14 107 116))">
    <polyline points="{{ $points }}" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" />
</svg>
```

Rewrite `resources/views/filament/admin/pages/pipeline-control.blade.php` with this structure (each card built with `x-filament::section`; keep every action call exactly as in the current view, keyed to the same action names):

```blade
<x-filament-panels::page>
    <div wire:poll.10s class="space-y-6">
        @php($summary = $this->summary())
        @php($cards = collect($this->cards())->keyBy(fn ($card) => $card['feed']->value))

        <x-filament::section>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <x-filament::badge :color="$summary->color" size="lg">{{ $summary->headline }}</x-filament::badge>
                    @foreach ($summary->notes as $note)
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ $note }}</span>
                    @endforeach
                </div>
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ number_format($this->ingestedToday()) }} products ingested today</span>
            </div>
        </x-filament::section>

        <div class="space-y-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Primary feeds</h3>
            @include('filament.admin.pages.partials.pipeline-feed-card', ['card' => $cards['nwws']])
            @include('filament.admin.pages.partials.pipeline-feed-card', ['card' => $cards['emwin']])
        </div>

        <div class="space-y-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Backup feed</h3>
            @include('filament.admin.pages.partials.pipeline-feed-card', ['card' => $cards['emwin_http']])
        </div>

        {{-- Recent EMWIN products: shared table, one row per product, a column per feed --}}
        <x-filament::section heading="Recent EMWIN products" description="Each product should arrive on both feeds; the second delivery is deduplicated.">
            @php($rows = $this->emwinComparison())
            @if ($rows->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">No EMWIN deliveries recorded yet.</p>
            @else
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="text-gray-500 dark:text-gray-400">
                            <th class="py-2 pr-4 font-medium">Product</th>
                            <th class="py-2 pr-4 font-medium">EMWIN Byteblaster</th>
                            <th class="py-2 font-medium">EMWIN HTTP backup</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-gray-200 dark:border-white/10">
                                <td class="py-2 pr-4 font-mono text-xs">{{ $row['label'] }}
                                    @unless ($row['comparable'])
                                        <span class="ml-2 text-gray-400">No header to compare</span>
                                    @endunless
                                </td>
                                @foreach (['emwin', 'emwin_http'] as $feed)
                                    @php($cell = $row['cells'][$feed])
                                    <td class="py-2 {{ $feed === 'emwin' ? 'pr-4' : '' }}">
                                        @if ($cell['state'] === 'delivered')
                                            <x-filament::badge color="success">{{ $cell['at']->format('H:i:s') }}</x-filament::badge>
                                        @elseif ($cell['state'] === 'waiting')
                                            <x-filament::badge color="gray">Waiting</x-filament::badge>
                                        @elseif ($cell['state'] === 'missing')
                                            <x-filament::badge color="warning">Not received</x-filament::badge>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
```

Create the card partial `resources/views/filament/admin/pages/partials/pipeline-feed-card.blade.php`. Actions must be rendered per feed with that feed's own action objects; map via the enum value:

```blade
@php
    $feed = $card['feed'];
    $actions = match ($feed->value) {
        'nwws' => ['start' => $this->startAction, 'stop' => $this->stopAction, 'restart' => $this->restartAction],
        'emwin' => ['start' => $this->startEmwinAction, 'stop' => $this->stopEmwinAction, 'restart' => $this->restartEmwinAction],
        'emwin_http' => ['start' => $this->startEmwinHttpAction, 'stop' => $this->stopEmwinHttpAction, 'restart' => $this->restartEmwinHttpAction],
    };
@endphp
<x-filament::section>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <x-filament::icon :icon="$feed->icon()" class="h-8 w-8 text-gray-400" />
            <div>
                <div class="flex items-center gap-3">
                    <span class="font-medium text-gray-700 dark:text-gray-200">{{ $feed->label() }}</span>
                    <x-filament::badge :color="$card['health']->color">{{ $card['health']->label }}</x-filament::badge>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $feed->role() }}</p>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Last product: <span class="font-semibold text-gray-800 dark:text-gray-100">{{ $card['lastProduct'] ?? 'never' }}</span></p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            @if ($actions['start']->isVisible()) {{ $actions['start'] }} @endif
            @if ($actions['stop']->isVisible()) {{ $actions['stop'] }} @endif
            {{ $actions['restart'] }}
        </div>
    </div>

    <div class="mt-4">
        <x-pipeline.sparkline :counts="$card['counts']" />
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ array_sum($card['counts']) }} received in 24h</p>
    </div>

    @if ($card['recent'] !== null)
        <div class="mt-4 space-y-1">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Recent products</p>
            @forelse ($card['recent'] as $delivery)
                <div class="flex items-center justify-between text-xs">
                    <span class="font-mono">{{ $delivery->wmo_header_key ? strtoupper($delivery->wmo_header_key) : $delivery->file_name }}</span>
                    <span class="flex items-center gap-2 text-gray-500 dark:text-gray-400">
                        {{ $delivery->received_at->diffForHumans() }}
                        <x-filament::badge size="sm" :color="$delivery->outcome === 'ingested' ? 'success' : 'gray'">{{ $delivery->outcome === 'ingested' ? 'Ingested' : 'Duplicate' }}</x-filament::badge>
                    </span>
                </div>
            @empty
                <p class="text-xs text-gray-500 dark:text-gray-400">Nothing received yet.</p>
            @endforelse
        </div>
    @endif
</x-filament::section>
```

The literal `Last product: ` must be followed by text before the next `<` for the existing `pipelineLastProduct()` helper regex; here the value sits inside a `<span>`, so update the helper regex in the test file to `/Last product:\s*<span[^>]*>\s*([^<]+?)\s*</` (keeping the label-first lazy match), and update the `assertSee('Last product: never')` test to `assertSee('never')` plus the helper. Confirm each existing PipelineControl test still passes after these updates.

- [ ] **Step 4: Run to verify they pass** — `tests/Feature/PipelineControlTest.php`. Expected: all pass. Then run `tests/Feature/ResetToFactoryDefaultsPageTest.php`.

- [ ] **Step 5: Commit** ("Redesign Pipeline Control with health cards, summary, sparklines and the EMWIN comparison table"; Pint; stage the page class, the view, the partial, the component and the test).

- [ ] **Step 6: Verify in the browser.** Start the preview with `mcp__Claude_Browser__preview_start` at `http://localhost:8080/admin/pipeline-control` (the login page has one-click dev buttons; type no credentials). Check light and dark mode: the banner, three cards, sparklines (an empty table shows flat lines), and the EMWIN table render with sensible spacing; confirm the actions still open their confirmation modals; confirm the page auto-refreshes (network tab shows the Livewire poll). If some utility classes do not render (no custom admin theme), replace them with small inline styles. The `pipeline_deliveries` table will not exist on the dev DB until the user migrates — the page must render with zeros meanwhile. Report what you saw.

---

### Task 7: Logs slide-over, secrecy audit and rules

**Files:**
- Modify: `app/Filament/Admin/Pages/PipelineControl.php` (a `logs` action)
- Modify: `resources/views/filament/admin/pages/partials/pipeline-feed-card.blade.php` (Logs button)
- Create: `resources/views/filament/admin/pages/partials/pipeline-logs.blade.php`
- Modify: `.ai/rules/pages.md` (via the `record-rule` tool)
- Test: `tests/Feature/PipelineControlTest.php` (append)

**Interfaces:**
- Consumes: `DockerEngineClient::logs(string $container, int $tail = 200): array<int, string>` (Task 3); `PipelineFeed` (Task 4).
- Produces: a page action named `logs` taking the argument `['feed' => '<feed value>']`, rendered as a slide-over modal with no submit button; an unknown feed value renders nothing.

**Implementation guidance:** use `search-docs` (packages `filament/filament`) to confirm how a Filament v5 page action receives `->arguments()` / `$arguments`, how to render a slide-over with `->modalContent(fn (array $arguments) => view(...))`, `->slideOver()`, `->modalSubmitAction(false)` and `->modalCancelActionLabel('Close')`, and how to trigger it from Blade with arguments (`wire:click="mountAction('logs', { feed: '...' })"`). Adjust the code below to the documented API while keeping the tests' intent.

- [ ] **Step 1: Write the failing tests** (append to `tests/Feature/PipelineControlTest.php`; helper `fakeDockerRunning()` from Task 6):

```php
use App\Support\Pipeline\DockerEngineClient;

test('the logs action shows the sanitized container log lines for a feed', function () {
    fakeDockerRunning(['docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/logs*' => Http::response("connected\n<script>alert(1)</script>\n")]);

    livewire(PipelineControl::class)
        ->mountAction('logs', ['feed' => 'nwws'])
        ->assertSee('connected')
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

test('the logs action refuses an unknown feed and never calls docker', function () {
    fakeDockerRunning();

    livewire(PipelineControl::class)->mountAction('logs', ['feed' => 'not-a-feed']);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/logs'));
});

test('a docker log failure shows a message instead of crashing the page', function () {
    fakeDockerRunning(['docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/logs*' => Http::response('', 500)]);

    livewire(PipelineControl::class)->mountAction('logs', ['feed' => 'nwws'])->assertSee('Could not read the container logs');
});

test('logs are only fetched when the action is opened, never on a normal render', function () {
    fakeDockerRunning();

    livewire(PipelineControl::class)->assertSuccessful();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/logs'));
});
```

- [ ] **Step 2: Run to verify they fail** — expected FAIL (no `logs` action).

- [ ] **Step 3: Implement.** In `PipelineControl`:

```php
    public function logsAction(): Action
    {
        return Action::make('logs')
            ->label('Logs')
            ->color('gray')
            ->slideOver()
            ->modalHeading(fn (array $arguments): string => (PipelineFeed::tryFrom((string) ($arguments['feed'] ?? '')))?->label().' logs' ?? 'Logs')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(function (array $arguments): View {
                $feed = PipelineFeed::tryFrom((string) ($arguments['feed'] ?? ''));

                if ($feed === null) {
                    return view('filament.admin.pages.partials.pipeline-logs', ['lines' => [], 'error' => 'Unknown feed.']);
                }

                try {
                    return view('filament.admin.pages.partials.pipeline-logs', ['lines' => app(DockerEngineClient::class)->logs($feed->container()), 'error' => null]);
                } catch (Throwable) {
                    return view('filament.admin.pages.partials.pipeline-logs', ['lines' => [], 'error' => 'Could not read the container logs.']);
                }
            });
    }
```

(`use Illuminate\Contracts\View\View;`; simplify the heading closure to avoid the null-propagation precedence trap — compute the label into a variable first.) The partial `pipeline-logs.blade.php`:

```blade
<div class="space-y-2">
    @if ($error)
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $error }}</p>
    @elseif ($lines === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">No log output.</p>
    @else
        <pre class="max-h-[70vh] overflow-auto whitespace-pre-wrap break-words rounded-lg bg-gray-950 p-3 text-xs text-gray-100">@foreach ($lines as $line){{ $line }}
@endforeach</pre>
    @endif
</div>
```

In the card partial add, next to the restart button, `<x-filament::button color="gray" size="sm" wire:click="mountAction('logs', { feed: '{{ $feed->value }}' })">Logs</x-filament::button>` (the feed value is a fixed enum string, never user input).

- [ ] **Step 4: Run to verify they pass** — `tests/Feature/PipelineControlTest.php` in full. Expected: pass.

- [ ] **Step 5: Secrecy audit (read-only; do not start, stop or restart anything).** Confirm none of the three containers log a secret; this prints counts only, never the secret:

```bash
TOKEN=$(grep '^PIPELINE_WEBHOOK_TOKEN=' .env | cut -d= -f2-); PASS=$(grep '^NWWS_PASSWORD=' .env | cut -d= -f2-)
for c in wxnotify-nwws wxnotify-emwin-byteblaster wxnotify-emwin-http; do
  echo "$c token-hits=$(docker logs "$c" 2>&1 | grep -cF -- "$TOKEN") password-hits=$(docker logs "$c" 2>&1 | grep -cF -- "$PASS")"
done
```

Expected: all zeros (a missing container prints an error and 0). Record the result in your report. If any count is non-zero, STOP and report — do not ship the logs action.

- [ ] **Step 6: Record the rule.** Use the `record-rule` tool with glob `app/Filament/Admin/Pages/**`, title "Pipeline Control logs and deliveries", note: "Container logs are untrusted text: DockerEngineClient::logs strips ANSI/control characters and caps lines; the view renders escaped text, and logs are fetched only when the slide-over opens, never on the 10s poll. pipeline_deliveries is best-effort telemetry (RecordPipelineDelivery never throws, PipelineActivity degrades to empty without the table) and is deliberately not in WipePipelineData — it holds no subscriber data and is pruned after 48 hours. Feed stale thresholds live in config wxnotify.pipeline.stale_minutes."

- [ ] **Step 7: Commit** ("Add a container logs slide-over to Pipeline Control and record the page rules"; Pint; stage the page, the two partials, the test file and `.ai/rules/pages.md`). Then run the full affected set once: `tests/Feature/PipelineControlTest.php tests/Feature/PipelineActivityTest.php tests/Feature/FeedHealthTest.php tests/Feature/PipelineDeliveryTest.php tests/Feature/IngestEmwinProductControllerTest.php tests/Feature/IngestNwwsProductControllerTest.php tests/Feature/ResetToFactoryDefaultsPageTest.php tests/Feature/FactoryResetActionsTest.php`.

---

## Self-Review Notes

- **Spec coverage:** data table + recording + pruning → Tasks 1–2; Docker health + logs → Task 3; health model, thresholds, banner rules → Task 4; sparkline, recent lists, shared EMWIN table, today count, fail-soft → Task 5; banner, groups, cards, poll, actions kept → Task 6; logs slide-over, secrecy audit, rules → Task 7; migration note (user runs it; page renders without the table) → Tasks 5–6 tests.
- **Deliberate deviations recorded:** `pipeline_deliveries` is not added to `WipePipelineData` (spec silent; adding it would change the asserted `counts` shape and reset UI for telemetry with no subscriber data) — captured in the recorded rule.
- **Type consistency:** feed keys `nwws|emwin|emwin_http`, `FeedHealth::evaluate(string, ?string, ?CarbonInterface, int)`, `DockerEngineClient::state()` shape, `PipelineActivity` method names and the comparison row shape are identical across tasks.
