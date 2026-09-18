---
paths:
  - 'tests/Feature/**'
---

# Feature

## config(['app.env' => ...]) doesn't move app()->environment() in tests
`app()->environment('production')` reads the container's `'env'` binding, set once at boot from APP_ENV -- it does NOT re-read `config('app.env')`. Setting `config(['app.env' => 'production'])` in a test silently no-ops; `app()->environment('production')` still returns false. To actually flip it in a test, use `app()->instance('env', 'production')`.

Found while writing TestProductScenarioResourceAccessTest.php (Task 12 of the Test Product Generator plan) -- a resource gated with `shouldRegisterNavigation()`/`canAccess()` returning `! app()->environment('production')`.
