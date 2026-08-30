---
paths:
  - 'app/Filament/**'
---

# Filament

## Filament Wizard dynamically-built steps: avoid non-native Select and goToNextWizardStep() in tests
A Wizard step built via `Step::schema(fn (Get $get) => ...)` (fields that only exist once an earlier field's value is known, e.g. a category picker) breaks two things:

1. Any non-native Select in that dynamic step — `->searchable()`, `->multiple()`, or `->relationship()` — throws a browser console error "Livewire Entangle Error: ... cannot be found on component" and silently desyncs its displayed value from the submitted state (looks selected, validates as empty). Fix: use a native `<select>` Select (drop searchable/multiple/relationship; add an explicit `->exists()` rule if you were relying on relationship() for scoping) or `CheckboxList` instead — both use plain `wire:model`, not `$wire.$entangle()`.
2. `Wizard::getCurrentStepIndex()` is a runtime-only PHP property, not part of Livewire's persisted state — it resets to the start step on every hydrate cycle. `goToNextWizardStep()` trusts that (reset) value, so chaining it more than once in a Pest/Livewire test silently re-validates the wrong step. For a `HasWizard` Create/Edit page, skip step navigation in tests entirely: `livewire(CreatePage::class)->fillForm([...all fields across all steps...])->call('create')` works in one shot regardless of step boundaries. If you must drive a hand-built Wizard page (not HasWizard) through real step transitions in a test, call `Wizard::nextStep()` directly with an explicit index instead of trusting `goToNextWizardStep()`'s auto-detection.

Found while building Notification Channels (ConnectChannel.php) and My Alerts (AlertRuleForm.php).
