# NWWS Ingestion Pipe Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Get real NWWS weather products flowing from `nwws-go-client` (a sidecar Docker container) into a searchable, browsable Laravel-side archive, replacing the static "Ingested Products" placeholder.

**Architecture:** `nwws-go-client` runs as a new `nwws` service in this repo's `compose.yaml`, sharing a Docker volume with the `laravel.test` container. Its built-in PAN (Product Arrival Notification) hook calls a tiny bash script on every new product, which POSTs the file's path to a bearer-token-authenticated Laravel API endpoint. That endpoint reads the file off the shared volume, decodes metadata from its filename, and stores it in a new `ingested_products` table. A read-only Filament resource makes the archive searchable in `/admin`.

**Tech Stack:** Laravel 13 / PHP 8.5 / Filament v5 / PostgreSQL / Pest, run via Laravel Sail (Docker). Go 1.27 (via a multi-stage Docker build, no host Go toolchain needed) for the unmodified `nwws-go-client`.

**Full design context:** `docs/pipeline-nwws-filament-blueprint.md` — read it before starting; this plan implements sections 1–4 of it (Compose service, Models, Pipeline Infrastructure, and the `IngestedProducts` half of Resources & Pages). Pipeline Control's Docker start/stop/restart buttons are a separate plan, built after this one so its manual verification has a real container to point at.

---

## File Structure

| File | Responsibility |
| --- | --- |
| `pipeline/nwws-go-client/` | Unmodified copy of the Go client's source (moved out of `import/`) |
| `docker/nwws/Dockerfile` | Multi-stage build: compiles the Go binary, bundles `curl` + the PAN script into a minimal runtime image |
| `docker/nwws/pan-notify.sh` | The PAN script — forwards a new product's path to Laravel, does no parsing itself |
| `compose.yaml` | Modified: new `nwws` service, `laravel.test` gets the shared volume + webhook token env var, new `nwws-products` named volume |
| `.env.example` | Modified: `NWWS_USERNAME`, `NWWS_PASSWORD`, `PIPELINE_WEBHOOK_TOKEN` placeholders |
| `database/migrations/..._create_ingested_products_table.php` | New `ingested_products` table |
| `app/Models/IngestedProduct.php` | The model, plus `parseFilename()` — the one piece of real parsing logic in this feature |
| `database/factories/IngestedProductFactory.php` | Factory for tests |
| `config/services.php` | Modified: adds a `pipeline` config block (webhook token + shared products path) |
| `app/Http/Middleware/VerifyPipelineWebhookToken.php` | Bearer-token auth for the ingestion endpoint — not Filament/session auth |
| `app/Http/Controllers/IngestNwwsProductController.php` | Reads the file, parses it, upserts the `IngestedProduct` row |
| `routes/api.php` | New — registers the ingestion route |
| `bootstrap/app.php` | Modified: registers `routes/api.php` and the `pipeline.webhook` middleware alias |
| `app/Filament/Admin/Resources/IngestedProducts/` | New read-only Filament resource (List + View, no Create/Edit/Delete) |
| `app/Filament/Admin/Pages/IngestedProducts.php` + its Blade view | Deleted — superseded by the real resource above |

---

### Task 1: Move `nwws-go-client` into the repo

**Files:**
- Create: `pipeline/nwws-go-client/` (copied from `import/nwws-go-client/`)
- Leave untouched: `import/nwws-go-client/` (may contain the user's real `config.json` with live credentials — do not delete it as part of this task)

- [ ] **Step 1: Copy only the source, not the nested git repo, sample output, or real credentials**

`import/nwws-go-client/` has its own `.git/` directory (a leftover from its life as a separate repo — `git mv`-ing it as-is would create a broken nested-repo gitlink instead of normal tracked files), a `products/` directory of sample output files (runtime data, not source), and a `config.json` that may hold real NWWS credentials (real credentials belong in `.env`, never in a committed file). Copy explicitly, skipping all three:

```bash
mkdir -p pipeline/nwws-go-client
cp -r import/nwws-go-client/cmd pipeline/nwws-go-client/cmd
cp -r import/nwws-go-client/internal pipeline/nwws-go-client/internal
cp -r import/nwws-go-client/third_party pipeline/nwws-go-client/third_party
cp import/nwws-go-client/go.mod pipeline/nwws-go-client/go.mod
cp import/nwws-go-client/go.sum pipeline/nwws-go-client/go.sum
cp import/nwws-go-client/README.md pipeline/nwws-go-client/README.md
cp import/nwws-go-client/CLAUDE.md pipeline/nwws-go-client/CLAUDE.md
cp import/nwws-go-client/LICENSE pipeline/nwws-go-client/LICENSE
cp import/nwws-go-client/.gitignore pipeline/nwws-go-client/.gitignore
cp import/nwws-go-client/config.example.json pipeline/nwws-go-client/config.example.json
```

- [ ] **Step 2: Verify the copy is complete**

Run: `diff <(cd import/nwws-go-client && find cmd internal third_party go.mod go.sum README.md CLAUDE.md LICENSE .gitignore config.example.json -type f | sort) <(cd pipeline/nwws-go-client && find cmd internal third_party go.mod go.sum README.md CLAUDE.md LICENSE .gitignore config.example.json -type f | sort)`

Expected: no output (the two file lists are identical).

- [ ] **Step 3: Stage and commit**

```bash
git add pipeline/nwws-go-client
git commit -m "Move nwws-go-client source into the repo

Copied out of import/nwws-go-client/ (staging location) into a
permanent home. Left the nested .git/, sample products/ output, and
config.json (may hold real credentials) behind deliberately — see
Task 1 of docs/superpowers/plans/2026-09-02-nwws-ingestion-pipe.md.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 2: `ingested_products` migration, model, factory, and filename parsing

**Files:**
- Create: `database/migrations/{timestamp}_create_ingested_products_table.php`
- Create: `app/Models/IngestedProduct.php`
- Create: `database/factories/IngestedProductFactory.php`
- Test: `tests/Unit/IngestedProductParseFilenameTest.php`

- [ ] **Step 1: Generate the migration**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:migration create_ingested_products_table --no-interaction"`

Expected: `INFO  Migration [database/migrations/..._create_ingested_products_table.php] created successfully.` — note the generated filename for the next step.

- [ ] **Step 2: Write the migration**

Replace the generated file's contents with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingested_products', function (Blueprint $table) {
            $table->id();
            $table->string('cccc')->nullable()->index();
            $table->string('ttaaii')->nullable()->index();
            $table->string('awipsid')->nullable()->index();
            $table->string('nwws_id')->nullable();
            $table->string('wmo_ddhhmm', 6)->nullable();
            $table->text('text');
            $table->string('file_path')->unique();
            $table->timestamp('received_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingested_products');
    }
};
```

- [ ] **Step 3: Run the migration**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan migrate --no-interaction"`

Expected: `..._create_ingested_products_table ... DONE`

- [ ] **Step 4: Write the failing unit test for `parseFilename`**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest --unit IngestedProductParseFilenameTest --no-interaction"`

Replace `tests/Unit/IngestedProductParseFilenameTest.php` with:

```php
<?php

use App\Models\IngestedProduct;

test('parses a well-formed filename', function () {
    $result = IngestedProduct::parseFilename('kkci_ftus21-tafkord.221432_12345.txt');

    expect($result)->toBe([
        'cccc' => 'kkci',
        'ttaaii' => 'ftus21',
        'awipsid' => 'tafkord',
        'wmo_ddhhmm' => '221432',
        'nwws_id' => '12345',
    ]);
});

test('returns all-null fields for an unparseable filename', function () {
    $result = IngestedProduct::parseFilename('not-a-valid-filename.txt');

    expect($result)->toBe([
        'cccc' => null,
        'ttaaii' => null,
        'awipsid' => null,
        'wmo_ddhhmm' => null,
        'nwws_id' => null,
    ]);
});

test('matches case-insensitively', function () {
    $result = IngestedProduct::parseFilename('KKCI_FTUS21-TAFKORD.221432_12345.TXT');

    expect($result)->toBe([
        'cccc' => 'kkci',
        'ttaaii' => 'ftus21',
        'awipsid' => 'tafkord',
        'wmo_ddhhmm' => '221432',
        'nwws_id' => '12345',
    ]);
});

test('handles an nwws_id containing dots and hyphens, since only the delimiters before it are ambiguous, not the trailing id itself', function () {
    $result = IngestedProduct::parseFilename('kkci_ftus21-tafkord.221432_nwws.processor-0000.txt');

    expect($result['nwws_id'])->toBe('nwws.processor-0000');
});
```

- [ ] **Step 5: Run the test, confirm it fails**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Unit/IngestedProductParseFilenameTest.php --compact"`

Expected: FAIL — `Class "App\Models\IngestedProduct" not found`.

- [ ] **Step 6: Create the model with `parseFilename()`**

Create `app/Models/IngestedProduct.php`:

```php
<?php

namespace App\Models;

use Database\Factories\IngestedProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['cccc', 'ttaaii', 'awipsid', 'nwws_id', 'wmo_ddhhmm', 'text', 'file_path', 'received_at'])]
class IngestedProduct extends Model
{
    /** @use HasFactory<IngestedProductFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }

    /**
     * Decodes cccc/ttaaii/awipsid/wmo_ddhhmm/nwws_id from an nwws-go-client
     * archive filename: [cccc]_[ttaaii]-[awipsid].[ddHHMM]_[id].txt — see
     * pipeline/nwws-go-client/internal/product/product.go's Filename().
     *
     * wmo_ddhhmm is day/hour/minute only (no month or year — a property of
     * the WMO header convention the filename mirrors, not a bug here), so
     * it's a display-only field, never treated as a real sortable date —
     * see IngestNwwsProductController for what *is* used for that.
     *
     * Returns all-null fields rather than throwing when the filename
     * doesn't match the expected shape — an unparseable filename still gets
     * ingested with its raw text, just without decoded metadata.
     *
     * @return array{cccc: ?string, ttaaii: ?string, awipsid: ?string, wmo_ddhhmm: ?string, nwws_id: ?string}
     */
    public static function parseFilename(string $filename): array
    {
        $pattern = '/^([a-z0-9._-]+)_([a-z0-9._-]+)-([a-z0-9._-]+)\.(\d{6})_(.+)\.txt$/';

        if (! preg_match($pattern, strtolower($filename), $matches)) {
            return [
                'cccc' => null,
                'ttaaii' => null,
                'awipsid' => null,
                'wmo_ddhhmm' => null,
                'nwws_id' => null,
            ];
        }

        return [
            'cccc' => $matches[1],
            'ttaaii' => $matches[2],
            'awipsid' => $matches[3],
            'wmo_ddhhmm' => $matches[4],
            'nwws_id' => $matches[5],
        ];
    }
}
```

- [ ] **Step 7: Run the test again, confirm it passes**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Unit/IngestedProductParseFilenameTest.php --compact"`

Expected: `Tests: 4 passed`

- [ ] **Step 8: Create the factory**

Create `database/factories/IngestedProductFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\IngestedProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngestedProduct>
 */
class IngestedProductFactory extends Factory
{
    public function definition(): array
    {
        $cccc = strtolower(fake()->lexify('????'));
        $ttaaii = strtolower(fake()->lexify('??????'));
        $awipsid = strtolower(fake()->lexify('??????'));
        $issued = fake()->dateTimeBetween('-1 week', 'now');
        $id = (string) fake()->unique()->numberBetween(1, 99999);
        $ddhhmm = $issued->format('dHi');

        return [
            'cccc' => $cccc,
            'ttaaii' => $ttaaii,
            'awipsid' => $awipsid,
            'nwws_id' => $id,
            'wmo_ddhhmm' => $ddhhmm,
            'text' => fake()->paragraphs(3, true),
            'file_path' => "{$cccc}/{$cccc}_{$ttaaii}-{$awipsid}.{$ddhhmm}_{$id}.txt",
            'received_at' => $issued,
        ];
    }
}
```

- [ ] **Step 9: Verify the factory works**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan tinker --execute 'echo App\Models\IngestedProduct::factory()->make()->file_path;'"`

Expected: a path like `abcd/abcd_efghij-klmnop.221432_4821.txt` (exact letters/numbers vary — this is just confirming the factory runs without error and produces the expected shape).

- [ ] **Step 10: Pint and commit**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"
git add database/migrations database/factories/IngestedProductFactory.php app/Models/IngestedProduct.php tests/Unit/IngestedProductParseFilenameTest.php
git commit -m "Add IngestedProduct model, migration, factory, and filename parsing

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 3: Pipeline config + `.env.example`

**Files:**
- Modify: `config/services.php`
- Modify: `.env.example`

- [ ] **Step 1: Add the `pipeline` block to `config/services.php`**

Read the file first to find where to insert (append as the last array entry, matching the existing `'google'`/`'turnstile'`-style blocks already there), then add:

```php
    'pipeline' => [
        'webhook_token' => env('PIPELINE_WEBHOOK_TOKEN'),
        'products_path' => env('PIPELINE_PRODUCTS_PATH', '/products'),
    ],
```

- [ ] **Step 2: Add the new env vars to `.env.example`**

Append to the end of `.env.example` (matching its existing blank-line-separated grouping style):

```
NWWS_USERNAME=
NWWS_PASSWORD=

PIPELINE_WEBHOOK_TOKEN=
```

- [ ] **Step 3: Generate a real webhook token for local `.env` (not `.env.example`)**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan tinker --execute 'echo Str::random(40);'"`

Copy the output into this project's real `.env` file (not committed) as `PIPELINE_WEBHOOK_TOKEN=<generated value>`. Leave `NWWS_USERNAME`/`NWWS_PASSWORD` for the user to fill in with their real NWWS-OI credentials — this plan doesn't have them and shouldn't guess or leave a fake value that looks real.

- [ ] **Step 4: Verify the config resolves**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan config:clear && php artisan tinker --execute 'echo config(\"services.pipeline.products_path\");'"`

Expected: `/products`

- [ ] **Step 5: Commit**

```bash
git add config/services.php .env.example
git commit -m "Add pipeline webhook token and products-path config

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 4: Ingestion endpoint (middleware, controller, routes)

**Files:**
- Create: `app/Http/Middleware/VerifyPipelineWebhookToken.php`
- Create: `app/Http/Controllers/IngestNwwsProductController.php`
- Create: `routes/api.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/IngestNwwsProductControllerTest.php`

- [ ] **Step 1: Register `routes/api.php` and the middleware alias in `bootstrap/app.php`**

This project has no `routes/api.php` yet — `->withRouting()` currently only registers `web`/`commands`/`health` (confirmed by reading the file; `withExceptions()` already has an `api/*` JSON-response check with nothing routed under that prefix yet). Replace `bootstrap/app.php`'s contents with:

```php
<?php

use App\Http\Middleware\VerifyPipelineWebhookToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'pipeline.webhook' => VerifyPipelineWebhookToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
```

- [ ] **Step 2: Create the middleware**

Create `app/Http/Middleware/VerifyPipelineWebhookToken.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyPipelineWebhookToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.pipeline.webhook_token');
        $given = $request->bearerToken();

        if (blank($expected) || blank($given) || ! hash_equals($expected, $given)) {
            abort(401);
        }

        return $next($request);
    }
}
```

- [ ] **Step 3: Create an empty `routes/api.php` and the controller stub**

Create `routes/api.php`:

```php
<?php

use App\Http\Controllers\IngestNwwsProductController;
use Illuminate\Support\Facades\Route;

Route::post('/pipeline/nwws-products', IngestNwwsProductController::class)
    ->middleware('pipeline.webhook');
```

Create `app/Http/Controllers/IngestNwwsProductController.php` as an empty stub for now so the route resolves:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class IngestNwwsProductController extends Controller
{
    public function __invoke(Request $request): Response
    {
        abort(500, 'not implemented yet');
    }
}
```

- [ ] **Step 4: Write the failing feature tests**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest IngestNwwsProductControllerTest --no-interaction"`

Replace `tests/Feature/IngestNwwsProductControllerTest.php` with:

```php
<?php

use App\Models\IngestedProduct;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    Config::set('services.pipeline.webhook_token', 'test-webhook-token');

    // A real temp directory standing in for the shared Docker volume — the
    // controller must never assume /products exists or is writable in a
    // test environment.
    $this->productsRoot = sys_get_temp_dir().'/pipeline-products-test-'.uniqid();
    File::makeDirectory($this->productsRoot.'/kkci', recursive: true);
    Config::set('services.pipeline.products_path', $this->productsRoot);
});

afterEach(function () {
    File::deleteDirectory($this->productsRoot);
});

test('rejects a request with no bearer token', function () {
    $this->postJson('/api/pipeline/nwws-products', ['path' => 'kkci/kkci_ftus21-tafkord.221432_12345.txt'])
        ->assertUnauthorized();

    expect(IngestedProduct::count())->toBe(0);
});

test('rejects a request with the wrong bearer token', function () {
    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'kkci/kkci_ftus21-tafkord.221432_12345.txt'],
        ['Authorization' => 'Bearer wrong-token'],
    )->assertUnauthorized();

    expect(IngestedProduct::count())->toBe(0);
});

test('ingests a well-formed product and decodes its filename', function () {
    File::put($this->productsRoot.'/kkci/kkci_ftus21-tafkord.221432_12345.txt', 'TAF KORD 221432Z sample text');

    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'kkci/kkci_ftus21-tafkord.221432_12345.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    $product = IngestedProduct::sole();

    expect($product->cccc)->toBe('kkci')
        ->and($product->ttaaii)->toBe('ftus21')
        ->and($product->awipsid)->toBe('tafkord')
        ->and($product->wmo_ddhhmm)->toBe('221432')
        ->and($product->nwws_id)->toBe('12345')
        ->and($product->text)->toBe('TAF KORD 221432Z sample text')
        ->and($product->file_path)->toBe('kkci/kkci_ftus21-tafkord.221432_12345.txt')
        ->and($product->received_at)->not->toBeNull();
});

test('still ingests a product with an unparseable filename, with metadata fields left null', function () {
    File::put($this->productsRoot.'/kkci/not-a-valid-filename.txt', 'some text');

    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'kkci/not-a-valid-filename.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    $product = IngestedProduct::sole();

    expect($product->cccc)->toBeNull()
        ->and($product->ttaaii)->toBeNull()
        ->and($product->text)->toBe('some text');
});

test('returns 404 for a path that does not exist on disk', function () {
    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'kkci/does-not-exist.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNotFound();

    expect(IngestedProduct::count())->toBe(0);
});

test('rejects a path that escapes the configured products root', function () {
    File::put($this->productsRoot.'/../outside.txt', 'should not be readable');

    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => '../outside.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNotFound();

    expect(IngestedProduct::count())->toBe(0);

    File::delete($this->productsRoot.'/../outside.txt');
});

test('is idempotent for the same path', function () {
    File::put($this->productsRoot.'/kkci/kkci_ftus21-tafkord.221432_12345.txt', 'original text');
    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'kkci/kkci_ftus21-tafkord.221432_12345.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    File::put($this->productsRoot.'/kkci/kkci_ftus21-tafkord.221432_12345.txt', 'updated text');
    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'kkci/kkci_ftus21-tafkord.221432_12345.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    expect(IngestedProduct::count())->toBe(1)
        ->and(IngestedProduct::sole()->text)->toBe('updated text');
});
```

- [ ] **Step 5: Run the tests, confirm the ones hitting real logic fail**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/IngestNwwsProductControllerTest.php --compact"`

Expected: the two 401 tests PASS already (the middleware is fully implemented), every other test FAILS (the controller stub always aborts 500).

- [ ] **Step 6: Implement the real controller**

Replace `app/Http/Controllers/IngestNwwsProductController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\IngestedProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class IngestNwwsProductController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'path' => ['required', 'string'],
        ]);

        $root = realpath(config('services.pipeline.products_path'));
        $absolutePath = $root !== false
            ? realpath($root.DIRECTORY_SEPARATOR.$validated['path'])
            : false;

        if ($root === false || $absolutePath === false || ! str_starts_with($absolutePath, $root.DIRECTORY_SEPARATOR)) {
            abort(404);
        }

        $parsed = IngestedProduct::parseFilename(basename($absolutePath));

        if ($parsed['cccc'] === null) {
            Log::warning('Ingested an NWWS product with an unparseable filename', [
                'path' => $validated['path'],
            ]);
        }

        IngestedProduct::updateOrCreate(
            ['file_path' => $validated['path']],
            [
                ...$parsed,
                'text' => file_get_contents($absolutePath),
                'received_at' => now(),
            ],
        );

        return response()->noContent();
    }
}
```

- [ ] **Step 7: Run the tests again, confirm they all pass**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/IngestNwwsProductControllerTest.php --compact"`

Expected: `Tests: 7 passed`

- [ ] **Step 8: Confirm the route is registered**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan route:list --path=api/pipeline"`

Expected: one row, `POST api/pipeline/nwws-products`.

- [ ] **Step 9: Run the full suite, Pint, and commit**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent && vendor/bin/pest --compact"
git add app/Http/Middleware/VerifyPipelineWebhookToken.php app/Http/Controllers/IngestNwwsProductController.php routes/api.php bootstrap/app.php tests/Feature/IngestNwwsProductControllerTest.php
git commit -m "Add the NWWS product ingestion endpoint

Bearer-token-authenticated (not Filament/session auth — the caller is
the nwws container's PAN script, which has no user session). Reads a
product file off the shared volume by path, decodes metadata from its
filename, upserts an IngestedProduct row keyed on file_path.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 5: Docker infrastructure (Dockerfile, PAN script, compose)

**Files:**
- Create: `docker/nwws/Dockerfile`
- Create: `docker/nwws/pan-notify.sh`
- Modify: `compose.yaml`

This task has no automated tests — it's infrastructure, verified by actually building and starting the container. Requires real NWWS-OI credentials in `.env` for the final connectivity check (Step 6); everything before that is verifiable without them.

- [ ] **Step 1: Write the PAN script**

Create `docker/nwws/pan-notify.sh`:

```bash
#!/bin/sh
set -eu

# $1 is the absolute path nwws-go-client just wrote, e.g. /products/kkci/kkci_....txt
# Strip the /products/ prefix nwws-go-client's own NWWS_ARCHIVEDIR always
# adds, since the ingestion endpoint resolves paths relative to its own
# configured products root, not as an absolute filesystem path.
path="${1#/products/}"

curl -sf -X POST "http://laravel.test/api/pipeline/nwws-products" \
    -H "Authorization: Bearer ${PIPELINE_WEBHOOK_TOKEN}" \
    -H "Content-Type: application/json" \
    -d "{\"path\":\"${path}\"}"
```

- [ ] **Step 2: Make it executable and verify its syntax**

```bash
chmod +x docker/nwws/pan-notify.sh
sh -n docker/nwws/pan-notify.sh
```

Expected: no output from `sh -n` (syntax OK — it only checks parsing, doesn't run the script).

- [ ] **Step 3: Write the Dockerfile**

Create `docker/nwws/Dockerfile`:

```dockerfile
FROM golang:1.27 AS build
WORKDIR /src
COPY pipeline/nwws-go-client/go.mod pipeline/nwws-go-client/go.sum ./
COPY pipeline/nwws-go-client/third_party ./third_party
RUN go mod download
COPY pipeline/nwws-go-client/cmd ./cmd
COPY pipeline/nwws-go-client/internal ./internal
RUN CGO_ENABLED=0 go build -o /nwws-go-client ./cmd/nwws-go-client

FROM alpine:3.20
RUN apk add --no-cache curl ca-certificates
COPY --from=build /nwws-go-client /nwws-go-client
COPY docker/nwws/pan-notify.sh /pan-notify.sh
RUN chmod +x /pan-notify.sh
ENTRYPOINT ["/nwws-go-client"]
```

(`ca-certificates` is needed alongside `curl` — without it, the PAN script's HTTPS-capable `curl` would have no trust store; the ingestion call here is plain `http://` over the internal compose network so this is precautionary, but cheap and avoids a confusing failure if that ever changes. `CGO_ENABLED=0` produces a static binary that runs on Alpine's musl libc without needing glibc compatibility shims.)

- [ ] **Step 4: Add the `nwws` service to `compose.yaml`**

Read `compose.yaml` first to find the right insertion point (alongside the other service definitions, e.g. after the `queue` service), then add:

```yaml
    nwws:
        build:
            context: .
            dockerfile: docker/nwws/Dockerfile
        container_name: wxnotify-nwws
        environment:
            NWWS_USERNAME: '${NWWS_USERNAME}'
            NWWS_PASSWORD: '${NWWS_PASSWORD}'
            NWWS_ARCHIVEDIR: /products
            NWWS_PAN_RUN: /pan-notify.sh
            NWWS_RETRY: 'true'
            PIPELINE_WEBHOOK_TOKEN: '${PIPELINE_WEBHOOK_TOKEN}'
        volumes:
            - 'nwws-products:/products'
        networks:
            - sail
        restart: unless-stopped
```

(`context: .` — the repo root, not `./pipeline/nwws-go-client` — is required here because the Dockerfile's `COPY docker/nwws/pan-notify.sh` and `COPY pipeline/nwws-go-client/...` lines both need paths relative to the repo root.)

- [ ] **Step 5: Add the shared volume + Docker socket + webhook token to `laravel.test`, and declare the new named volume**

In `compose.yaml`'s `laravel.test` service, add to its existing `volumes:` list:

```yaml
            - 'nwws-products:/products'
            - '/var/run/docker.sock:/var/run/docker.sock'
```

And to its existing `environment:` block, add:

```yaml
            PIPELINE_WEBHOOK_TOKEN: '${PIPELINE_WEBHOOK_TOKEN}'
```

At the bottom-level `volumes:` key (alongside the existing `sail-pgsql`/`sail-redis`/etc. entries), add:

```yaml
    nwws-products:
        driver: local
```

(The Docker socket mount is for the *next* plan's Pipeline Control start/stop buttons, not used by anything in this plan — it's added here because it belongs on the same `laravel.test` service edit, and there's no reason to touch this file twice for two adjacent lines.)

- [ ] **Step 6: Build the image**

Run: `docker compose build nwws`

Expected: builds successfully, ending in something like `=> => naming to ... wxnotify-dashboard-nwws`. If it fails on `go mod download` or the compile step, the error will name the missing/broken piece — don't guess, read it.

- [ ] **Step 7: Start the container and check it actually connects**

Requires real `NWWS_USERNAME`/`NWWS_PASSWORD` in `.env` (from a real NWWS-OI account) — if the user hasn't provided these yet, stop here and ask for them rather than starting a container that will just loop on auth failure.

```bash
docker compose up -d nwws
docker compose logs nwws --tail 50
```

Expected: log lines showing a successful connection and MUC join (something like `"joining MUC room"` followed by no immediate reconnect-loop errors). If you see `"unknown namespace"` right after joining, that's the exact bug the vendored `gosrc.io/xmpp` patch (`pipeline/nwws-go-client/third_party/gosrc.io-xmpp/stanza/parser.go`, search `PATCHED`) already fixes — if it's still happening, the patch didn't make it into the built image; re-check Step 3's Dockerfile `COPY` lines.

- [ ] **Step 8: Commit**

```bash
git add docker/nwws compose.yaml
git commit -m "Add the nwws sidecar service (Dockerfile, PAN script, compose wiring)

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 6: End-to-end ingestion smoke test

**Files:** none — this task only verifies Tasks 4 and 5 actually work together, using a real file instead of mocks.

- [ ] **Step 1: Manually drop a product file where `nwws-go-client` would**

```bash
docker exec wxnotify-nwws sh -c 'mkdir -p /products/ktst && printf "TEST PRODUCT TEXT\n" > /products/ktst/ktst_ftus99-testabc.021530_99999.txt'
```

- [ ] **Step 2: Manually fire the PAN script exactly as `nwws-go-client` would**

```bash
docker exec wxnotify-nwws /pan-notify.sh /products/ktst/ktst_ftus99-testabc.021530_99999.txt
```

Expected: no output (the script is silent on success — `curl -sf` suppresses progress and fails silently only on a non-2xx response, which would show as a non-zero exit code from this `docker exec`, visible via `echo $?` if you want to double check).

- [ ] **Step 3: Confirm the product landed in the database**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan tinker --execute 'App\Models\IngestedProduct::where(\"cccc\", \"ktst\")->get([\"cccc\",\"ttaaii\",\"awipsid\",\"text\"])->each(fn(\$p) => print(json_encode(\$p) . PHP_EOL));'"`

Expected: one row printed with `cccc: "ktst"`, `ttaaii: "ftus99"`, `awipsid: "testabc"`, `text: "TEST PRODUCT TEXT\n"`.

- [ ] **Step 4: Clean up the test row**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan tinker --execute 'App\Models\IngestedProduct::where(\"cccc\", \"ktst\")->delete();'"`

No commit for this task — it's a verification pass, not a code change.

---

### Task 7: `IngestedProducts` Filament resource

**Files:**
- Delete: `app/Filament/Admin/Pages/IngestedProducts.php`
- Delete: `resources/views/filament/admin/pages/ingested-products.blade.php`
- Create: `app/Filament/Admin/Resources/IngestedProducts/IngestedProductResource.php`
- Create: `app/Filament/Admin/Resources/IngestedProducts/Tables/IngestedProductsTable.php`
- Create: `app/Filament/Admin/Resources/IngestedProducts/Schemas/IngestedProductInfolist.php`
- Create: `app/Filament/Admin/Resources/IngestedProducts/Pages/ListIngestedProducts.php`
- Create: `app/Filament/Admin/Resources/IngestedProducts/Pages/ViewIngestedProduct.php`
- Test: `tests/Feature/IngestedProductResourceTest.php`

This mirrors `app/Filament/Admin/Resources/ActivityLogs/` exactly — this project's existing precedent for a read-only archive resource (List + View/Infolist, `canCreate()`/`canEdit()`/`canDelete()` all `false`). Read that directory's four files before starting if anything below is unclear; don't improvise a different shape.

- [ ] **Step 1: Remove the static placeholder page**

```bash
rm app/Filament/Admin/Pages/IngestedProducts.php
rm resources/views/filament/admin/pages/ingested-products.blade.php
```

(The `AdminPanelProvider`'s `discoverPages()`/`discoverResources()` calls pick up the new Resource automatically once its class exists — no manual registration needed, matching how every other Admin Tool resource in this project was wired in.)

- [ ] **Step 2: Write the failing feature test**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan make:test --pest IngestedProductResourceTest --no-interaction"`

Replace `tests/Feature/IngestedProductResourceTest.php` with:

```php
<?php

use App\Filament\Admin\Resources\IngestedProducts\Pages\ListIngestedProducts;
use App\Models\IngestedProduct;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
});

test('staff can list ingested products', function () {
    $product = IngestedProduct::factory()->create(['cccc' => 'kkci']);

    livewire(ListIngestedProducts::class)
        ->assertCanSeeTableRecords([$product]);
});

test('the cccc filter narrows results to the selected office', function () {
    $kkci = IngestedProduct::factory()->create(['cccc' => 'kkci']);
    $ktae = IngestedProduct::factory()->create(['cccc' => 'ktae']);

    livewire(ListIngestedProducts::class)
        ->filterTable('cccc', 'kkci')
        ->assertCanSeeTableRecords([$kkci])
        ->assertCanNotSeeTableRecords([$ktae]);
});

test('the received_at range filter narrows results to the selected window', function () {
    $inRange = IngestedProduct::factory()->create(['received_at' => now()->subDays(2)]);
    $outOfRange = IngestedProduct::factory()->create(['received_at' => now()->subDays(10)]);

    livewire(ListIngestedProducts::class)
        ->filterTable('received_at', ['from' => now()->subDays(5)->toDateString()])
        ->assertCanSeeTableRecords([$inRange])
        ->assertCanNotSeeTableRecords([$outOfRange]);
});

test('there are no create, edit, or delete actions', function () {
    IngestedProduct::factory()->create();

    livewire(ListIngestedProducts::class)
        ->assertActionDoesNotExist('create')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');
});
```

- [ ] **Step 3: Run the tests, confirm they fail**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/IngestedProductResourceTest.php --compact"`

Expected: FAIL — `Class "App\Filament\Admin\Resources\IngestedProducts\Pages\ListIngestedProducts" not found`.

- [ ] **Step 4: Create the Resource class**

Create `app/Filament/Admin/Resources/IngestedProducts/IngestedProductResource.php`:

```php
<?php

namespace App\Filament\Admin\Resources\IngestedProducts;

use App\Filament\Admin\Resources\IngestedProducts\Pages\ListIngestedProducts;
use App\Filament\Admin\Resources\IngestedProducts\Pages\ViewIngestedProduct;
use App\Filament\Admin\Resources\IngestedProducts\Schemas\IngestedProductInfolist;
use App\Filament\Admin\Resources\IngestedProducts\Tables\IngestedProductsTable;
use App\Models\IngestedProduct;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class IngestedProductResource extends Resource
{
    protected static ?string $model = IngestedProduct::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Ingested Products';

    protected static ?string $modelLabel = 'ingested product';

    public static function infolist(Schema $schema): Schema
    {
        return IngestedProductInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IngestedProductsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIngestedProducts::route('/'),
            'view' => ViewIngestedProduct::route('/{record}'),
        ];
    }
}
```

- [ ] **Step 5: Create the Table class**

Create `app/Filament/Admin/Resources/IngestedProducts/Tables/IngestedProductsTable.php`:

```php
<?php

namespace App\Filament\Admin\Resources\IngestedProducts\Tables;

use App\Models\IngestedProduct;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IngestedProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cccc')
                    ->label('Office')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): string => $state ?? '—'),
                TextColumn::make('ttaaii')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('awipsid')
                    ->label('AWIPS ID')
                    ->searchable(),
                TextColumn::make('wmo_ddhhmm')
                    ->label('Issued (DDHHMM)')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn (?string $state): string => $state ?? '—'),
                TextColumn::make('received_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('received_at', 'desc')
            ->filters([
                SelectFilter::make('cccc')
                    ->label('Office')
                    ->options(fn (): array => IngestedProduct::query()
                        ->whereNotNull('cccc')
                        ->distinct()
                        ->orderBy('cccc')
                        ->pluck('cccc', 'cccc')
                        ->all()),
                Filter::make('received_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('received_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('received_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
```

- [ ] **Step 6: Create the Infolist class**

Create `app/Filament/Admin/Resources/IngestedProducts/Schemas/IngestedProductInfolist.php`:

```php
<?php

namespace App\Filament\Admin\Resources\IngestedProducts\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class IngestedProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextEntry::make('cccc')->label('Office')->formatStateUsing(fn (?string $state): string => $state ?? '—'),
                TextEntry::make('ttaaii')->formatStateUsing(fn (?string $state): string => $state ?? '—'),
                TextEntry::make('awipsid')->label('AWIPS ID')->formatStateUsing(fn (?string $state): string => $state ?? '—'),
                TextEntry::make('wmo_ddhhmm')->label('Issued (DDHHMM)')->formatStateUsing(fn (?string $state): string => $state ?? '—'),
                TextEntry::make('received_at')->dateTime(),
                TextEntry::make('text')
                    ->label('Product text')
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'whitespace-pre-wrap font-mono text-xs']),
            ]);
    }
}
```

- [ ] **Step 7: Create the Pages**

Create `app/Filament/Admin/Resources/IngestedProducts/Pages/ListIngestedProducts.php`:

```php
<?php

namespace App\Filament\Admin\Resources\IngestedProducts\Pages;

use App\Filament\Admin\Resources\IngestedProducts\IngestedProductResource;
use Filament\Resources\Pages\ListRecords;

class ListIngestedProducts extends ListRecords
{
    protected static string $resource = IngestedProductResource::class;
}
```

Create `app/Filament/Admin/Resources/IngestedProducts/Pages/ViewIngestedProduct.php`:

```php
<?php

namespace App\Filament\Admin\Resources\IngestedProducts\Pages;

use App\Filament\Admin\Resources\IngestedProducts\IngestedProductResource;
use Filament\Resources\Pages\ViewRecord;

class ViewIngestedProduct extends ViewRecord
{
    protected static string $resource = IngestedProductResource::class;
}
```

- [ ] **Step 8: Run the tests, confirm they pass**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest tests/Feature/IngestedProductResourceTest.php --compact"`

Expected: `Tests: 4 passed`

- [ ] **Step 9: Confirm the route is registered and the old placeholder route is gone**

Run: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && php artisan route:list --path=admin/ingested-products"`

Expected: two rows — `GET admin/ingested-products` (index) and `GET admin/ingested-products/{record}` (view) — both under the resource, none pointing at the deleted static Page class.

- [ ] **Step 10: Full suite, Pint, commit**

```bash
docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent && vendor/bin/pest --compact"
git add app/Filament/Admin/Resources/IngestedProducts tests/Feature/IngestedProductResourceTest.php
git add -u app/Filament/Admin/Pages/IngestedProducts.php resources/views/filament/admin/pages/ingested-products.blade.php
git commit -m "Add the Ingested Products archive resource

Replaces the static placeholder page with a real, read-only Filament
resource (List + View, mirroring ActivityLogs' existing precedent) over
the ingested_products table the previous two tasks now actually
populate.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Final Verification

- [ ] Full Pest suite passes: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pest --compact"`
- [ ] Pint clean: `docker exec wxnotify-dashboard-laravel.test-1 bash -lc "cd /var/www/html && vendor/bin/pint --dirty --format agent"`
- [ ] `docker compose ps` shows `nwws` running (assuming real credentials were supplied in Task 5) and `docker compose logs nwws` shows no reconnect-loop errors.
- [ ] Browser check as a seeded staff user: `/admin/ingested-products` loads, shows real rows once the live feed has been running a few minutes, the `cccc` and date-range filters both work, and clicking a row shows its full product text.
- [ ] `import/nwws-go-client/` is still present and untouched — tell the user it's now safe to delete once they've confirmed `pipeline/nwws-go-client/` has everything they need (don't delete it yourself; it may hold their real `config.json`).
