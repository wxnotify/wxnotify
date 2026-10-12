<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Unauthenticated;
use App\Exceptions\Api\ValidationFailed;
use App\Services\SignOut;
use App\Services\WxNotifyApi;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use App\Support\ProductSearchPresenter;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;

/**
 * The Products tab: search the archive of weather products by name or ID, filter by product type, issuing
 * office and period, and open a result's public product page. Searches run on submit, never per keystroke.
 * Recent searches are kept on the phone; results and the filter lists are never cached.
 */
class ProductsScreen extends NativeComponent
{
    use RequiresSignIn;

    private const MIN_QUERY_LENGTH = 2;

    /** How long after a Search press a late text sync still completes that search. */
    private const LATE_TEXT_SYNC_SECONDS = 1;

    public string $query = '';

    public ?int $typeId = null;

    public string $wfo = '';

    public string $period = '';

    /** @var array<int, array{id: int, name: string, code: string}> */
    public array $types = [];

    /** @var array<int, array{code: string, label: string}> */
    public array $offices = [];

    /** @var array<int, array<string, mixed>>|null null until a search has run (the opening state) */
    public ?array $results = null;

    public ?string $nextCursor = null;

    /** @var array{q: ?string, type: ?int, wfo: ?string, period: ?string} the criteria of the last successful first-page search; the cursor belongs to it */
    public array $searched = [];

    /** @var array<int, array{q: string, type: ?int, type_label: ?string, wfo: string, period: string}> */
    public array $recents = [];

    public bool $offline = false;

    public ?string $error = null;

    public ?string $message = null;

    /** Whether the Type, Office and Period selects are shown. Hidden on every visit until the filters button is tapped. */
    public bool $filtersOpen = false;

    /** When Search was last pressed (Unix time), so a text sync that arrives just after the press can finish the search. */
    public ?int $searchPressedAt = null;

    /** The search this visit saved last, so changing only a filter replaces it in the recents instead of adding a second entry. */
    public ?array $lastSavedSearch = null;

    /** The text that press searched with, so a sync that only repeats it does not search again. */
    public string $searchPressedText = '';

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $this->recents = app(LocalCache::class)->recentSearches();

        $this->loadFilters();
    }

    /**
     * The Search button and the keyboard's Search key (which passes the typed text).
     */
    public function search(?string $text = null): void
    {
        if ($text !== null) {
            $this->query = $text;
        }

        $this->searchPressedAt = now()->timestamp;
        $this->searchPressedText = trim($this->query);

        $this->runSearch();
    }

    /**
     * The search box syncs its text when it loses focus (native:model.blur). Pressing Search clears that focus, but
     * on Android the press can reach PHP before the text does, so the press would search the old text. When the
     * text arrives right after a press, finish the search the subscriber asked for.
     */
    public function updatedQuery(): void
    {
        $pressedJustNow = $this->searchPressedAt !== null
            && now()->timestamp - $this->searchPressedAt <= self::LATE_TEXT_SYNC_SECONDS;

        $this->searchPressedAt = null;

        if ($pressedJustNow && trim($this->query) !== $this->searchPressedText) {
            $this->runSearch();
        }
    }

    public function toggleFilters(): void
    {
        $this->filtersOpen = ! $this->filtersOpen;
    }

    public function pickType(string $label): void
    {
        $this->typeId = array_search($label, $this->typeOptions(), true) ?: null;

        $this->runSearch();
    }

    public function pickOffice(string $label): void
    {
        $this->wfo = (string) (collect($this->offices)->firstWhere('label', $label)['code'] ?? '');

        $this->runSearch();
    }

    public function pickPeriod(string $label): void
    {
        $this->period = (string) (array_search($label, ProductSearchPresenter::PERIODS, true) ?: '');

        $this->runSearch();
    }

    public function clearFilters(): void
    {
        $this->typeId = null;
        $this->wfo = '';
        $this->period = '';

        $this->runSearch();
    }

    public function loadMore(): void
    {
        if ($this->nextCursor === null || $this->results === null || $this->searched === []) {
            return;
        }

        try {
            $page = app(WxNotifyApi::class)->products($this->searched, $this->nextCursor);
        } catch (Unauthenticated) {
            $this->signOut();

            return;
        } catch (Offline) {
            $this->offline = true;

            return;
        } catch (ApiException) {
            $this->error = 'WxNotify could not load more products. Try again in a moment.';

            return;
        }

        $this->results = [...$this->results, ...$page['data']];
        $this->nextCursor = $page['meta']['next_cursor'] ?? null;
        $this->offline = false;
        $this->error = null;
    }

    public function open(int $id): void
    {
        $url = collect($this->results)->firstWhere('id', $id)['view_url'] ?? null;

        if (filled($url)) {
            Browser::inApp($url);
        }
    }

    public function useRecent(int $index): void
    {
        $recent = $this->recents[$index] ?? null;

        if ($recent === null) {
            return;
        }

        $this->query = $recent['q'];
        $this->typeId = $recent['type'];
        $this->wfo = $recent['wfo'];
        $this->period = $recent['period'];

        $this->runSearch(fromRecent: true);
    }

    public function clearRecent(): void
    {
        app(LocalCache::class)->forgetSearches();

        $this->recents = [];
    }

    /**
     * @return array<int, string> the Type select's options: "Any type", then each catalog product (a name shared by two products gets its code)
     */
    public function typeLabels(): array
    {
        return ['Any type', ...array_values($this->typeOptions())];
    }

    public function selectedTypeLabel(): string
    {
        return $this->typeOptions()[$this->typeId] ?? 'Any type';
    }

    /**
     * @return array<int, string>
     */
    public function officeLabels(): array
    {
        return ['Any office', ...array_column($this->offices, 'label')];
    }

    public function selectedOfficeLabel(): string
    {
        return collect($this->offices)->firstWhere('code', $this->wfo)['label'] ?? 'Any office';
    }

    /**
     * @return array<int, string>
     */
    public function periodLabels(): array
    {
        return array_values(ProductSearchPresenter::PERIODS);
    }

    public function selectedPeriodLabel(): string
    {
        return ProductSearchPresenter::PERIODS[$this->period] ?? 'Any time';
    }

    public function hasFilters(): bool
    {
        return $this->typeId !== null || $this->wfo !== '' || $this->period !== '';
    }

    /**
     * @param  array<string, mixed>  $product
     */
    public function subtitle(array $product): string
    {
        return ProductSearchPresenter::subtitle($product);
    }

    /**
     * @param  array{q: string, type: ?int, type_label: ?string, wfo: string, period: string}  $search
     */
    public function recentLabel(array $search): string
    {
        return ProductSearchPresenter::recentLabel($search);
    }

    private function runSearch(bool $fromRecent = false): void
    {
        $text = trim($this->query);
        $this->message = null;

        if ($text !== '' && mb_strlen($text) < self::MIN_QUERY_LENGTH) {
            $this->message = 'Type at least '.self::MIN_QUERY_LENGTH.' characters to search.';
            $this->results = null;
            $this->nextCursor = null;

            return;
        }

        if ($text === '' && ! $this->hasFilters()) {
            $this->results = null;
            $this->nextCursor = null;
            $this->offline = false;
            $this->error = null;

            return;
        }

        if ($this->types === [] || $this->offices === []) {
            $this->loadFilters();
        }

        try {
            $criteria = $this->criteria();
            $page = app(WxNotifyApi::class)->products($criteria);
        } catch (Unauthenticated) {
            $this->signOut();

            return;
        } catch (Offline) {
            $this->backToOpeningState();
            $this->offline = true;
            $this->error = null;

            return;
        } catch (ValidationFailed) {
            $this->backToOpeningState();
            $this->offline = false;
            $this->error = null;
            $this->message = 'That search could not be run. Check the text and filters.';

            return;
        } catch (ApiException) {
            $this->backToOpeningState();
            $this->offline = false;
            $this->error = 'WxNotify could not search products. Try again in a moment.';

            return;
        }

        $this->searched = $criteria;
        $this->results = $page['data'];
        $this->nextCursor = $page['meta']['next_cursor'] ?? null;
        $this->offline = false;
        $this->error = null;

        $search = [
            'q' => $text,
            'type' => $this->typeId,
            'type_label' => $this->typeId === null ? null : ($this->typeOptions()[$this->typeId] ?? null),
            'wfo' => $this->wfo,
            'period' => $this->period,
        ];

        // Each filter change runs a search, so building "afd · MOB · Past 7 days" would otherwise leave three recents.
        // The same text with another filter replaces the search this visit saved last; a recent that was tapped, and a
        // search saved in an earlier visit, are kept.
        $replacing = ! $fromRecent && $this->lastSavedSearch !== null && mb_strtolower($this->lastSavedSearch['q']) === mb_strtolower($text)
            ? $this->lastSavedSearch
            : null;

        $cache = app(LocalCache::class);
        $cache->rememberSearch($search, $replacing);
        $this->lastSavedSearch = $fromRecent ? null : $search;
        $this->recents = $cache->recentSearches();
    }

    /**
     * A failed search drops the old results (and their cursor) so the recent searches show again beside the message.
     */
    private function backToOpeningState(): void
    {
        $this->results = null;
        $this->nextCursor = null;
        $this->searched = [];
    }

    /**
     * @return array{q: ?string, type: ?int, wfo: ?string, period: ?string}
     */
    private function criteria(): array
    {
        return [
            'q' => trim($this->query) ?: null,
            'type' => $this->typeId,
            'wfo' => $this->wfo ?: null,
            'period' => $this->period ?: null,
        ];
    }

    /**
     * The filter lists. A failure leaves them empty (the selects still offer "Any"); the next search tries again.
     */
    private function loadFilters(): void
    {
        try {
            $api = app(WxNotifyApi::class);

            $this->types = collect($api->productTypes())->flatMap(fn (array $group): array => $group['products'])->values()->all();
            $this->offices = $api->nwsOffices();
        } catch (Unauthenticated) {
            $this->signOut();
        } catch (ApiException) {
            // Leave the lists as they are.
        }
    }

    /**
     * @return array<int, string> catalog product id => label, in alphabetical order (a name shared by two products is followed by the product's code)
     */
    private function typeOptions(): array
    {
        $counts = array_count_values(array_column($this->types, 'name'));
        $options = [];

        foreach ($this->types as $type) {
            $options[$type['id']] = $counts[$type['name']] > 1 ? "{$type['name']} ({$type['code']})" : $type['name'];
        }

        // A plain case-insensitive comparison, not a natural one: a natural sort skips spaces and puts "Airport" before "Air Quality".
        uasort($options, fn (string $a, string $b): int => strcmp(mb_strtolower($a), mb_strtolower($b)));

        return $options;
    }

    private function signOut(): void
    {
        app(SignOut::class)->clearLocal();
        $this->replace('/sign-in');
    }
}
