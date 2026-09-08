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

## A Select action's createOptionForm() can't conditionally require its own fields
Inside a single Select::createOptionForm() modal, a field whose ->required() (or presence) is gated on another field in that SAME schema (e.g. a type picker with `->required(fn (Get $get) => $get('type') === 'x')`) does not validate reliably — Filament rejects the correct, present value as missing, confirmed via a failing test where $get() showed the right values but validation still failed. This is a variant of the dynamically-built-Wizard-step bug already recorded above, but happening inside an Action's modal schema instead of a Wizard step.

Workaround: don't put a type-picker + type-specific fields in one createOptionForm(). Instead give the Select one ->suffixActions() entry per type (via Filament\Actions\Action, each with its own fixed, unconditional ->schema() and ->action() that does the create and appends the new id into the Select's state with Set $set / Get $get). See AlertRuleForm::channelsSelect()/connectChannelAction() for the working pattern.

## EditRecord's default save bypasses non-Fillable fields — override handleRecordUpdate() for legitimate admin writes
A model attribute deliberately excluded from `#[Fillable(...)]` (e.g. `User::tier_override` — kept out so a subscriber can't self-grant a tier) also gets silently dropped by an ADMIN's own legitimate edit form, because `EditRecord`'s default `handleRecordUpdate()` calls `$record->update($data)` — plain mass assignment, same guard either way. No error; the field just doesn't save. Caught by a browser test (saved a value, reloaded, field was still null), not by anything sooner.

Fix: override `handleRecordUpdate(Model $record, array $data): Model` on that specific Edit page to `$record->forceFill($data)->save(); return $record;` — bypasses the guard only on that one intentional, staff-only path. Every other write path to the field stays blocked.

Also: testing a resource that lives on a non-default panel (this app's default is `dashboard`; `admin` is not) needs `Filament::setCurrentPanel('admin')` in the test's `beforeEach()` — without it, `livewire()` throws `RouteNotFoundException` for `filament.dashboard.resources.*` since Filament resolves nav/breadcrumb URLs against the default panel unless told otherwise.

Found while building the Admin Tools UserResource (Support/impersonation).

## A TextColumn deriving a label from a nullable dot-relation attribute needs ->state(), not ->formatStateUsing() alone
`TextColumn::make('someRelation.attribute')->formatStateUsing(fn (?string $state) => $state !== null ? 'A' : 'B')`, intended to render one of two labels depending on whether a related row exists, silently never shows the "null" branch's label. Root cause, confirmed by reading `vendor/filament/support/src/Concerns/HasCellState.php`'s `getState()`: when the raw state resolved from the record (here, a `null` because the relation doesn't exist) is `blank()`, Filament substitutes `getDefaultState()` (the `->default()` value, or `null` if none was set) *before* `formatStateUsing()` ever runs — the formatter closure is never invoked for a blank raw state, only for a non-blank one.

Fix: compute the label from the record instead of a dot-relation path — `TextColumn::make('status')->state(fn (Model $record) => $record->someRelation !== null)->formatStateUsing(fn (bool $state) => $state ? 'A' : 'B')`. `->state()` supplies a state that's never blank in the "false" case (a real boolean), so the formatter always runs.

Found while building the ShapefileLayerResource list page (`Status` column showing "Pending review" vs "Up to date" based on whether a `pendingVersion` relation exists).
