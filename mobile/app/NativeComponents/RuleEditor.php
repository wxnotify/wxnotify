<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Maintenance;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Rejected;
use App\Exceptions\Api\Unauthenticated;
use App\Exceptions\Api\ValidationFailed;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use App\Support\RuleFormPresenter;
use Native\Mobile\Edge\NativeComponent;

/**
 * Creates or edits one alert rule: a draft held here, nothing sent until Save. The category-driven
 * logic is RuleFormPresenter's; this screen only wires taps to the draft and the draft to the API.
 * The server re-validates everything and its field messages are shown on the field.
 */
class RuleEditor extends NativeComponent
{
    use RequiresSignIn;

    private const MAX_OFFICE_RESULTS = 20;

    private const FIX_SUMMARY = 'Some details need fixing.';

    /** Draft keys holding id or code lists, whose order carries no meaning. */
    private const LIST_FIELDS = ['product_ids', 'location_ids', 'channel_ids', 'county_zone_codes', 'risk_levels'];

    public ?int $ruleId = null;

    /** @var array<string, mixed> */
    public array $draft = [];

    /** @var array<string, mixed> */
    public array $original = [];

    /** @var array<string, mixed> */
    public array $options = [];

    /** @var array<string, string> */
    public array $errors = [];

    public ?string $banner = null;

    public bool $ready = false;

    public bool $canRetry = false;

    public bool $confirmingDelete = false;

    public bool $confirmingDiscard = false;

    public string $officeQuery = '';

    /** @var array<int, array{code: string, label: string}> */
    public array $offices = [];

    /** @var array<int, array{code: string, label: string}> */
    public array $officeResults = [];

    public string $zoneState = '';

    public string $zoneQuery = '';

    /** @var array<int, array{code: string, label: string}> */
    public array $zoneResults = [];

    public ?string $zoneNextAfter = null;

    /** The state filter of the search that produced the zone results ("More results" continues it). */
    public ?string $zoneSearchState = null;

    /** The name filter of the search that produced the zone results. */
    public ?string $zoneSearchQuery = null;

    public ?string $pickerMessage = null;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $id = $this->param('id');
        $this->ruleId = filled($id) ? (int) $id : null;

        $this->load();
    }

    /**
     * Fetches the form's choices (and the rule being edited) and builds the draft. Offline or during
     * maintenance the remembered choices and rule are used; with nothing remembered the form cannot
     * open and the reason shows with a retry.
     */
    public function load(): void
    {
        $this->banner = null;
        $this->canRetry = false;

        $cache = app(LocalCache::class);
        $this->options = $cache->options() ?? [];

        try {
            $this->options = app(WxNotifyApi::class)->alertRuleOptions();
            $cache->rememberOptions($this->options);
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (ApiException $e) {
            if ($this->options === []) {
                $this->cannotOpen($e, 'Connect to the internet to set up an alert rule.', 'WxNotify could not load the alert rule form. Try again in a moment.');

                return;
            }
        }

        $rule = null;

        if ($this->ruleId !== null) {
            $rule = $this->loadRule($this->ruleId);

            if ($rule === null) {
                return;
            }
        }

        $presenter = $this->presenter();
        $this->draft = $rule === null ? $presenter->blankDraft('') : $presenter->draftFromRule($rule);
        $this->original = $this->draft;
        $this->ready = true;
    }

    /**
     * Back from adding a location (or from Account): refresh the choices so a new location or
     * channel can be picked; the draft is untouched, and a failed refresh keeps the old choices.
     */
    public function onResume(): void
    {
        if (! $this->ready) {
            return;
        }

        try {
            $this->options = app(WxNotifyApi::class)->alertRuleOptions();
            app(LocalCache::class)->rememberOptions($this->options);
        } catch (Unauthenticated) {
            $this->signOutLocally();
        } catch (ApiException) {
            // Offline or a server hiccup: the choices already on screen stay.
        }
    }

    public function presenter(): RuleFormPresenter
    {
        return new RuleFormPresenter($this->options);
    }

    // ---- draft changes -------------------------------------------------------------------------

    public function chooseCategory(string $category): void
    {
        if ($category === $this->draft['category']) {
            return;
        }

        $this->draft = $this->presenter()->switchCategory($this->draft, $category);
        $this->errors = [];
        $this->banner = null;
        $this->officeResults = [];
        $this->zoneResults = [];
        $this->zoneNextAfter = null;
        $this->pickerMessage = null;
    }

    /**
     * A user-initiated product change is the only place (with a mode change) that reconciles the
     * draft: an untouched rule keeps what it stored even when a product it uses has been retired.
     */
    public function setProduct(int $id, bool $on): void
    {
        $this->toggleIn('product_ids', $id, $on);
        $this->draft = $this->presenter()->reconcile($this->draft);
    }

    public function setRiskLevel(string $level, bool $on): void
    {
        $this->toggleIn('risk_levels', $level, $on);
    }

    public function chooseMode(string $mode): void
    {
        $this->draft['matching_mode'] = $mode;
        $this->draft = $this->presenter()->reconcile($this->draft);
        $this->forgetError('matching_mode');
    }

    public function setLocation(int $id, bool $on): void
    {
        $this->toggleIn('location_ids', $id, $on);
    }

    public function setChannel(int $id, bool $on): void
    {
        $this->toggleIn('channel_ids', $id, $on);
    }

    public function setZone(string $code, bool $on): void
    {
        $this->toggleIn('county_zone_codes', $code, $on);
    }

    public function setTime(string $field, string $value): void
    {
        $this->draft[$field] = $value;
        $this->forgetError($field);
    }

    public function clearQuietHours(): void
    {
        $this->draft['quiet_hours_start'] = '';
        $this->draft['quiet_hours_end'] = '';
        $this->forgetError('quiet_hours_start', 'quiet_hours_end');
    }

    /**
     * A select element reports the chosen label, never an id: map it back through this field's own
     * label list (duplicate names are numbered, so every choice has a unique label).
     */
    public function pick(string $field, string $label): void
    {
        $map = $this->labelMap($field);

        if (! array_key_exists($label, $map)) {
            return;
        }

        $value = $map[$label];

        $this->draft[$field] = in_array($field, ['location_id', 'alert_template_id'], true)
            ? ($value === '' ? null : (int) $value)
            : (string) $value;

        $this->forgetError($field);
    }

    /**
     * @return array<int, string> the labels the select for a field offers, in order
     */
    public function selectLabels(string $field): array
    {
        return array_map('strval', array_keys($this->labelMap($field)));
    }

    public function selectedLabel(string $field): string
    {
        foreach ($this->labelMap($field) as $label => $value) {
            if ((string) $value === (string) ($this->draft[$field] ?? '')) {
                return (string) $label;
            }
        }

        return '';
    }

    /**
     * The stored matching mode's label when the selected products no longer offer it (a rule using
     * a retired product), so the screen still says how the rule matches; null otherwise.
     */
    public function unlistedModeLabel(): ?string
    {
        $mode = (string) ($this->draft['matching_mode'] ?? '');

        if ($mode === '' || in_array($mode, array_column($this->presenter()->allowedModes($this->draft), 'value'), true)) {
            return null;
        }

        return collect($this->options['matching_modes'] ?? [])->firstWhere('value', $mode)['label'] ?? $mode;
    }

    // ---- pickers -------------------------------------------------------------------------------

    public function searchOffices(): void
    {
        $this->pickerMessage = null;

        try {
            if ($this->offices === []) {
                $this->offices = app(WxNotifyApi::class)->nwsOffices();
            }
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (ApiException) {
            $this->pickerMessage = 'Couldn\'t load the offices. Check your connection and try again.';

            return;
        }

        $needle = mb_strtolower(trim($this->officeQuery));

        $this->officeResults = array_slice(array_values(array_filter(
            $this->offices,
            fn (array $office): bool => $needle === '' || str_contains(mb_strtolower($office['label']), $needle),
        )), 0, self::MAX_OFFICE_RESULTS);
    }

    public function chooseOffice(string $code): void
    {
        $this->draft['wfo'] = $code;
        $this->officeResults = [];
        $this->forgetError('wfo');
    }

    public function searchZones(): void
    {
        $this->pickerMessage = null;

        $state = $this->zoneStateFilter();
        $query = trim($this->zoneQuery);

        if ($state === null && mb_strlen($query) < 2) {
            $this->pickerMessage = 'Enter a two-letter state or at least two letters of a county or zone name.';

            return;
        }

        $this->fetchZones($state, $query === '' ? null : $query, null, false);
    }

    public function moreZones(): void
    {
        if ($this->zoneNextAfter === null) {
            return;
        }

        $this->pickerMessage = null;

        $this->fetchZones($this->zoneSearchState, $this->zoneSearchQuery, $this->zoneNextAfter, true);
    }

    // ---- saving and leaving ----------------------------------------------------------------------

    public function save(): void
    {
        $this->banner = null;
        $this->errors = $this->presenter()->validate($this->draft);

        if ($this->errors !== []) {
            $this->banner = self::FIX_SUMMARY;

            return;
        }

        try {
            $rule = app(WxNotifyApi::class)->saveAlertRule($this->ruleId, $this->presenter()->payload($this->draft));
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (ValidationFailed $e) {
            $this->errors = $e->errors;
            $this->banner = self::FIX_SUMMARY;

            return;
        } catch (Maintenance $e) {
            $this->banner = $e->getMessage();

            return;
        } catch (Offline) {
            $this->banner = 'You\'re offline. Reconnect to save.';

            return;
        } catch (ApiException) {
            $this->banner = 'Couldn\'t save the rule. Please try again.';

            return;
        }

        $this->rememberSaved($rule);
        $this->original = $this->draft;
        $this->back();
    }

    public function askDelete(): void
    {
        $this->confirmingDelete = true;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDelete = false;
    }

    public function delete(): void
    {
        if ($this->ruleId === null) {
            return;
        }

        $this->banner = null;

        try {
            app(WxNotifyApi::class)->deleteAlertRule($this->ruleId);
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (Rejected) {
            // Already gone (deleted elsewhere): the list should drop it too.
        } catch (Maintenance $e) {
            $this->confirmingDelete = false;
            $this->banner = $e->getMessage();

            return;
        } catch (Offline) {
            $this->confirmingDelete = false;
            $this->banner = 'You\'re offline. Reconnect to delete this rule.';

            return;
        } catch (ApiException) {
            $this->confirmingDelete = false;
            $this->banner = 'Couldn\'t delete the rule. Please try again.';

            return;
        }

        $cache = app(LocalCache::class);
        $cache->rememberRules(array_values(array_filter($cache->rules() ?? [], fn (array $rule): bool => $rule['id'] !== $this->ruleId)));

        $this->original = $this->draft;
        $this->back();
    }

    /**
     * Unsaved edits: the id and code lists compare as sets, so unchecking and re-checking a box
     * (which moves its id to the end) is not an edit.
     */
    public function isDirty(): bool
    {
        return $this->comparable($this->draft) !== $this->comparable($this->original);
    }

    /**
     * A typed field bound with native:model (value, radius, name, subject) changed: its message
     * no longer applies.
     */
    public function updatedDraft(mixed $value, ?string $key = null): void
    {
        if ($key !== null) {
            $this->forgetError(explode('.', $key)[0]);
        }
    }

    /**
     * The system back gesture: leave straight away unless there are unsaved edits.
     */
    public function onBackPressed(): void
    {
        if ($this->ready && $this->isDirty()) {
            $this->confirmingDiscard = true;

            return;
        }

        $this->back();
    }

    public function discard(): void
    {
        $this->original = $this->draft;
        $this->back();
    }

    public function keepEditing(): void
    {
        $this->confirmingDiscard = false;
    }

    public function openAccount(): void
    {
        $this->navigate('/account');
    }

    public function addLocation(): void
    {
        $this->navigate('/locations/new');
    }

    // ---- helpers -------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null the rule, or null with a banner (or a sign-out) when it cannot be opened
     */
    private function loadRule(int $id): ?array
    {
        $cached = collect(app(LocalCache::class)->rules() ?? [])->firstWhere('id', $id);

        try {
            return app(WxNotifyApi::class)->alertRule($id);
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return null;
        } catch (Rejected) {
            $this->banner = 'This rule no longer exists.';

            return null;
        } catch (ApiException $e) {
            if ($cached === null) {
                $this->cannotOpen($e, 'Connect to the internet to open this rule.', 'WxNotify could not load this rule. Try again in a moment.');
            }

            return $cached;
        }
    }

    /**
     * Says why the form could not open, with a retry: offline, maintenance (the server's own words)
     * or any other failure.
     */
    private function cannotOpen(ApiException $e, string $offline, string $otherwise): void
    {
        $this->banner = match (true) {
            $e instanceof Offline => $offline,
            $e instanceof Maintenance => $e->getMessage(),
            default => $otherwise,
        };
        $this->canRetry = true;
    }

    /**
     * Drops the messages for fields the subscriber just changed, and the "needs fixing" summary
     * once no field message is left.
     */
    private function forgetError(string ...$fields): void
    {
        foreach ($fields as $field) {
            unset($this->errors[$field]);
        }

        if ($this->errors === [] && $this->banner === self::FIX_SUMMARY) {
            $this->banner = null;
        }
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function comparable(array $draft): array
    {
        foreach (self::LIST_FIELDS as $field) {
            if (is_array($draft[$field] ?? null)) {
                sort($draft[$field]);
            }
        }

        return $draft;
    }

    private function toggleIn(string $field, int|string $value, bool $on): void
    {
        $list = array_values(array_filter($this->draft[$field], fn (mixed $item): bool => $item !== $value));

        if ($on) {
            $list[] = $value;
        }

        $this->draft[$field] = $list;
        $this->forgetError($field);
    }

    private function zoneStateFilter(): ?string
    {
        $state = strtoupper(trim($this->zoneState));

        return preg_match('/^[A-Z]{2}$/', $state) === 1 ? $state : null;
    }

    /**
     * @return array<int|string, string> value => label for a select field
     */
    private function choices(string $field): array
    {
        $options = $this->options;

        return match ($field) {
            'variable' => collect($options['thresholds']['variables'] ?? [])->pluck('label', 'value')->all(),
            'direction' => collect($options['thresholds']['directions'] ?? [])->pluck('label', 'value')->all(),
            'timeframe' => collect($options['thresholds']['timeframes'] ?? [])->pluck('label', 'value')->all(),
            'message_body_style' => ['' => 'Default'] + collect($options['message_body_styles'] ?? [])->pluck('label', 'value')->all(),
            'location_id' => collect($options['locations'] ?? [])->pluck('label', 'id')->all(),
            'alert_template_id' => ['' => 'None'] + collect($options['templates'][$this->draft['category']] ?? [])->pluck('name', 'id')->all(),
            default => [],
        };
    }

    /**
     * @return array<string, int|string> label => value, a repeated label numbered "Home (2)"
     */
    private function labelMap(string $field): array
    {
        $map = [];
        $seen = [];

        foreach ($this->choices($field) as $value => $label) {
            $label = (string) $label;
            $seen[$label] = ($seen[$label] ?? 0) + 1;
            $map[$seen[$label] === 1 ? $label : "{$label} ({$seen[$label]})"] = $value;
        }

        return $map;
    }

    private function fetchZones(?string $state, ?string $query, ?string $after, bool $append): void
    {
        try {
            $page = app(WxNotifyApi::class)->zones($state, $query, $after);
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (ApiException) {
            $this->pickerMessage = 'Couldn\'t search right now. Try again.';

            return;
        }

        $this->zoneResults = $append ? [...$this->zoneResults, ...$page['data']] : $page['data'];
        $this->zoneNextAfter = $page['next_after'];
        $this->zoneSearchState = $state;
        $this->zoneSearchQuery = $query;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function rememberSaved(array $rule): void
    {
        $cache = app(LocalCache::class);
        $rules = $cache->rules() ?? [];
        $replaced = false;

        foreach ($rules as $index => $existing) {
            if ($existing['id'] === $rule['id']) {
                $rules[$index] = $rule;
                $replaced = true;
            }
        }

        if (! $replaced) {
            array_unshift($rules, $rule);
        }

        $cache->rememberRules($rules);
    }

    private function signOutLocally(): void
    {
        app(SignOut::class)->clearLocal();
        $this->replace('/sign-in');
    }
}
