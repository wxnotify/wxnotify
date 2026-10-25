---
paths:
  - 'tests/Feature/**'
---

# Feature

## config(['app.env' => ...]) doesn't move app()->environment() in tests
`app()->environment('production')` reads the container's `'env'` binding, set once at boot from APP_ENV -- it does NOT re-read `config('app.env')`. Setting `config(['app.env' => 'production'])` in a test silently no-ops; `app()->environment('production')` still returns false. To actually flip it in a test, use `app()->instance('env', 'production')`.

Found while writing TestProductScenarioResourceAccessTest.php (Task 12 of the Test Product Generator plan) -- a resource gated with `shouldRegisterNavigation()`/`canAccess()` returning `! app()->environment('production')`.

## Feature tests run in parallel, and an unfaked HTTP request fails at once
`tests/Pest.php` calls `Http::preventStrayRequests()` for every Feature test, so a request no test faked throws immediately instead of reaching the network and waiting out a 10 s timeout (a page that merely lists a Traccar server's devices cost minutes before). The app treats a failed request as an unreachable service, so a test that only renders such a page still passes; a test that needs a response must `Http::fake()` it. Run the suite with `vendor/bin/pest --parallel --processes=6` (about 3 minutes instead of 13): paratest gives every worker its own database (`testing_test_N`, migrated at the start), cache/queue/session are per-process in tests, and temp directories use `uniqid()`. Parallel needs Postgres' `max_locks_per_transaction` raised (set to 256 in `compose.yaml`): each worker drops and recreates ~100 tables at startup, and the default 64 overflows when several do it at once. A test file in the global namespace must not `use` a class with a non-compound name (`use RuntimeException;`): the compile warning becomes an ErrorException in a worker. Never assert the order of an unordered relationship (e.g. a rule's `products` joined into a string); compare as a set.

## Testing unique jobs: Queue::fake() takes the lock and never releases it
In Laravel 13 `Queue::fake()` still takes `ShouldBeUnique` locks and never releases them (only `Bus::fake()` bypasses them), so a unique-job dedup test must use the real `database` queue driver plus `queue:work --tries=1` and count `jobs`/DeliveryLog rows; `phpunit.xml` uses the array cache, whose locks live per application instance, so there is no cross-test or cross-process leakage.

## Delayed-job tests: use the database queue driver and travel the clock
The suite runs on the `sync` queue by default, which the outlook pairing treats as delay 0. A test of a delayed job must switch to the `database` queue driver (`beforeEach`), run `queue:work --tries=1` and move the frozen clock past `available_at` (not run at 59 s, run at 61 s) instead of calling `handle()` directly (only the deleted-product and throwing cases may).
