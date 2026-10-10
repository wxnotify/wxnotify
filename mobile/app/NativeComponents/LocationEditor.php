<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Conflict;
use App\Exceptions\Api\LocationLimitReached;
use App\Exceptions\Api\Maintenance;
use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Rejected;
use App\Exceptions\Api\Unauthenticated;
use App\Exceptions\Api\ValidationFailed;
use App\Services\WxNotifyApi;
use App\Support\Concerns\EditsADraft;
use App\Support\Concerns\RequiresSignIn;
use App\Support\LocalCache;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Geolocation\LocationReceived;
use Native\Mobile\Facades\Geolocation;
use Native\Mobile\Facades\System;

/**
 * Adds or edits one location. A stationary location gets its position from the phone's current
 * position or an address search (no map yet); a mobile location edits only its name and update
 * interval. A draft is held here and nothing is sent until Save.
 *
 * The server resolves a timezone only when it creates a location; editing never re-resolves one,
 * so an existing location keeps its timezone (and sends it again when moved) unless another is chosen.
 */
class LocationEditor extends NativeComponent
{
    use EditsADraft;
    use RequiresSignIn;

    private const POSITION_REQUEST = 'wxnotify-place';

    /** @var array<string, string> label => IANA zone: the zones WxNotify's US alerts use */
    private const TIMEZONES = [
        'Eastern (New York)' => 'America/New_York',
        'Central (Chicago)' => 'America/Chicago',
        'Mountain (Denver)' => 'America/Denver',
        'Arizona (Phoenix)' => 'America/Phoenix',
        'Pacific (Los Angeles)' => 'America/Los_Angeles',
        'Alaska (Anchorage)' => 'America/Anchorage',
        'Hawaii (Honolulu)' => 'Pacific/Honolulu',
        'Puerto Rico' => 'America/Puerto_Rico',
        'Guam' => 'Pacific/Guam',
    ];

    /** A new location's empty timezone: the server sets it from the position when it creates the location. */
    private const AUTOMATIC = 'Automatic (from the position)';

    /** An existing location saved without a timezone: editing leaves it unset unless one is chosen. */
    private const NOT_SET = 'Not set';

    private const INTERVALS = [5, 10, 15, 20, 25, 30];

    /** The summary shown beside the buttons when the server refuses a detail the page has no field for. */
    private const FIX_SUMMARY = 'Some details need fixing.';

    private const PERMISSION_OFF = 'Location permission is off. Allow it in your phone\'s settings, or search for an address instead.';

    public ?int $locationId = null;

    /** @var array<string, mixed> the location as loaded (empty for a new one) */
    public array $location = [];

    /** @var array<string, string> */
    public array $draft = ['label' => '', 'address' => '', 'lat' => '', 'lng' => '', 'timezone' => '', 'update_interval_minutes' => '15'];

    /** @var array<string, string> */
    public array $original = [];

    /** @var array<string, string> */
    public array $errors = [];

    public ?string $banner = null;

    public bool $ready = false;

    public bool $canRetry = false;

    public string $placeQuery = '';

    /** @var array<int, array{label: string, lat: float, lng: float}> */
    public array $placeResults = [];

    /** The query that produced the place results (choosing one uses those results, whatever the box says now). */
    public ?string $placeSearch = null;

    public ?string $placeMessage = null;

    public ?string $positionMessage = null;

    public bool $locating = false;

    public bool $permissionDenied = false;

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $id = $this->param('id');
        $this->locationId = filled($id) ? (int) $id : null;

        $this->load();
    }

    /**
     * Builds the draft: blank for a new location, otherwise from the location as the server (or,
     * offline, the remembered list) has it. With nothing to show the reason appears with a retry.
     */
    public function load(): void
    {
        $this->banner = null;
        $this->canRetry = false;

        if ($this->locationId !== null) {
            $location = $this->loadLocation($this->locationId);

            if ($location === null) {
                return;
            }

            $this->location = $location;
            $this->draft = [
                'label' => (string) $location['label'],
                'address' => (string) ($location['address'] ?? ''),
                'lat' => $location['lat'] === null ? '' : (string) $location['lat'],
                'lng' => $location['lng'] === null ? '' : (string) $location['lng'],
                'timezone' => (string) ($location['timezone'] ?? ''),
                'update_interval_minutes' => (string) ($location['update_interval_minutes'] ?? 15),
            ];
        }

        $this->original = $this->draft;
        $this->ready = true;
    }

    public function isMobile(): bool
    {
        return ($this->location['type'] ?? 'stationary') === 'mobile';
    }

    /**
     * Which Traccar device keeps this location current, or that it is missing, in one sentence.
     */
    public function traccarDescription(): string
    {
        $device = filled($this->location['device_name'] ?? null) ? (string) $this->location['device_name'] : null;

        if ($this->location['has_missing_device'] ?? false) {
            return ($device === null ? 'Its Traccar device is missing' : "Its Traccar device ({$device}) is missing").'. Choose another device on wxnotify.com.';
        }

        return ($device === null ? 'Tracked by a Traccar device.' : "Tracked by the Traccar device {$device}.").' Change the device on wxnotify.com.';
    }

    // ---- position ------------------------------------------------------------------------------

    public function useCurrentPosition(): void
    {
        $this->positionMessage = 'Finding your position…';
        $this->permissionDenied = false;
        $this->locating = true;

        Geolocation::getCurrentPosition()->id(self::POSITION_REQUEST)->get();
    }

    /**
     * A one-shot fix (or its failure). getCurrentPosition() already shows the system prompt when
     * nothing is decided yet, so a permission failure means access is refused: the screen points to
     * the phone's settings at once. (Asking again would wait forever on iOS, which never answers a
     * request once access is denied.)
     */
    #[On(LocationReceived::class)]
    public function receivePosition(bool $success, ?float $latitude = null, ?float $longitude = null, ?string $error = null, ?string $id = null): void
    {
        if (! $this->locating || ($id !== null && $id !== self::POSITION_REQUEST)) {
            return;
        }

        if ($success && $latitude !== null && $longitude !== null) {
            $this->locating = false;
            $this->setPosition(sprintf('%.6f', $latitude), sprintf('%.6f', $longitude), $this->lookUpAddress($latitude, $longitude));

            return;
        }

        $isPermission = stripos((string) $error, 'permission') !== false;

        $this->locating = false;
        $this->permissionDenied = $isPermission;
        $this->positionMessage = $isPermission ? self::PERMISSION_OFF : 'Couldn\'t get your position. Try again, or search for an address.';
    }

    public function openSettings(): void
    {
        System::appSettings();
    }

    public function searchPlaces(): void
    {
        $this->placeMessage = null;
        $this->placeResults = [];
        $this->placeSearch = null;

        $query = trim($this->placeQuery);

        if (mb_strlen($query) < 3) {
            $this->placeMessage = 'Type at least three characters to search.';

            return;
        }

        try {
            $this->placeResults = app(WxNotifyApi::class)->searchPlaces($query);
            $this->placeSearch = $query;
            $this->placeMessage = $this->placeResults === [] ? 'No places found. Try a fuller address.' : null;
        } catch (Unauthenticated) {
            $this->signOutLocally();
        } catch (Offline) {
            $this->placeMessage = 'You\'re offline. Reconnect to search.';
        } catch (ApiException) {
            $this->placeMessage = 'Search is busy or unavailable. Try again in a moment.';
        }
    }

    public function choosePlace(int $index): void
    {
        $place = $this->placeResults[$index] ?? null;

        if ($place === null) {
            return;
        }

        $this->placeResults = [];
        $this->placeSearch = null;
        $this->setPosition((string) $place['lat'], (string) $place['lng'], $place['label']);

        if (trim($this->draft['label']) === '') {
            $this->draft['label'] = trim(explode(',', $place['label'])[0]);
            $this->forgetError('label');
        }
    }

    // ---- other draft changes -------------------------------------------------------------------

    /**
     * @return array<int, string> the labels the timezone select offers
     */
    public function timezoneLabels(): array
    {
        return array_keys($this->timezoneMap());
    }

    public function selectedTimezoneLabel(): string
    {
        return (string) array_search($this->draft['timezone'], $this->timezoneMap(), true);
    }

    public function pickTimezone(string $label): void
    {
        $map = $this->timezoneMap();

        if (array_key_exists($label, $map)) {
            $this->draft['timezone'] = $map[$label];
            $this->forgetError('timezone');
        }
    }

    /**
     * @return array<int, string>
     */
    public function intervalLabels(): array
    {
        return array_map(fn (int $minutes): string => "{$minutes} minutes", self::INTERVALS);
    }

    public function selectedIntervalLabel(): string
    {
        return $this->draft['update_interval_minutes'].' minutes';
    }

    public function pickInterval(string $label): void
    {
        $minutes = (int) $label;

        if (in_array($minutes, self::INTERVALS, true)) {
            $this->draft['update_interval_minutes'] = (string) $minutes;
            $this->forgetError('update_interval_minutes');
        }
    }

    /**
     * The name, typed with native:model, changed: its message no longer applies.
     */
    public function updatedDraft(mixed $value, ?string $key = null): void
    {
        if ($key !== null) {
            $this->forgetError(explode('.', $key)[0]);
        }
    }

    // ---- saving and leaving ----------------------------------------------------------------------

    public function save(): void
    {
        $this->banner = null;
        $this->errors = $this->validateDraft();

        if ($this->errors !== []) {
            return;
        }

        try {
            $location = app(WxNotifyApi::class)->saveLocation($this->locationId, $this->payload());
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (LocationLimitReached) {
            $this->banner = 'Your plan\'s location limit is reached. Remove a location, or upgrade, to add another.';

            return;
        } catch (ValidationFailed $e) {
            $this->errors = $this->mapServerErrors($e->errors);

            return;
        } catch (Maintenance $e) {
            $this->banner = $e->getMessage();

            return;
        } catch (Offline) {
            $this->banner = 'You\'re offline. Reconnect to save.';

            return;
        } catch (ApiException) {
            $this->banner = 'Couldn\'t save the location. Please try again.';

            return;
        }

        $this->rememberSaved($location);
        $this->original = $this->draft;
        $this->back();
    }

    /**
     * A refusal keeps the location and shows the server's own summary (a location used by alerts
     * names only the first few of them, so the message, not the list, is what is shown).
     */
    public function delete(): void
    {
        if ($this->locationId === null) {
            return;
        }

        $this->banner = null;

        try {
            app(WxNotifyApi::class)->deleteLocation($this->locationId);
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return;
        } catch (Conflict $e) {
            $this->confirmingDelete = false;
            $this->banner = $e->getMessage() !== '' ? $e->getMessage() : 'This location can\'t be deleted right now.';

            return;
        } catch (Rejected) {
            // Already gone elsewhere: the list should drop it too.
        } catch (Maintenance $e) {
            $this->confirmingDelete = false;
            $this->banner = $e->getMessage();

            return;
        } catch (Offline) {
            $this->confirmingDelete = false;
            $this->banner = 'You\'re offline. Reconnect to delete this location.';

            return;
        } catch (ApiException) {
            $this->confirmingDelete = false;
            $this->banner = 'Couldn\'t delete the location. Please try again.';

            return;
        }

        $cache = app(LocalCache::class);
        $cache->rememberLocations(
            array_values(array_filter($cache->locations() ?? [], fn (array $location): bool => $location['id'] !== $this->locationId)),
            $cache->locationsMeta() ?? [],
        );

        $this->original = $this->draft;
        $this->back();
    }

    public function isDirty(): bool
    {
        return $this->draft !== $this->original;
    }

    /**
     * Whether a stationary location's position differs from the one it was opened with (always,
     * for a new one).
     */
    public function isMoved(): bool
    {
        return $this->locationId === null
            || $this->draft['lat'] !== ($this->original['lat'] ?? '')
            || $this->draft['lng'] !== ($this->original['lng'] ?? '');
    }

    // ---- helpers -------------------------------------------------------------------------------

    private function setPosition(string $lat, string $lng, string $address): void
    {
        $this->draft['lat'] = $lat;
        $this->draft['lng'] = $lng;
        $this->draft['address'] = $address;
        $this->forgetError('position');
    }

    /**
     * The address for a fix, or '' (with a message) when none can be found: the position is kept either way.
     */
    private function lookUpAddress(float $lat, float $lng): string
    {
        try {
            $address = app(WxNotifyApi::class)->reversePlace($lat, $lng);
            $this->positionMessage = $address === null ? 'Position found. We couldn\'t look up the address, but you can still save it.' : null;

            return $address ?? '';
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return '';
        } catch (ApiException) {
            $this->positionMessage = 'Position found. We couldn\'t look up the address, but you can still save it.';

            return '';
        }
    }

    /**
     * The location from the server, or the remembered copy when the server cannot be reached.
     *
     * @return array<string, mixed>|null the location, or null with a banner (or a sign-out) when it cannot be opened
     */
    private function loadLocation(int $id): ?array
    {
        $cache = app(LocalCache::class);
        $cached = collect($cache->locations() ?? [])->firstWhere('id', $id);

        try {
            $page = app(WxNotifyApi::class)->locations();
        } catch (Unauthenticated) {
            $this->signOutLocally();

            return null;
        } catch (ApiException $e) {
            if ($cached === null) {
                $this->banner = match (true) {
                    $e instanceof Offline => 'Connect to the internet to open this location.',
                    $e instanceof Maintenance => $e->getMessage(),
                    default => 'WxNotify could not load this location. Try again in a moment.',
                };
                $this->canRetry = true;
            }

            return $cached;
        }

        $cache->rememberLocations($page['data'], $page['meta']);
        $location = collect($page['data'])->firstWhere('id', $id);

        if ($location === null) {
            $this->banner = 'This location no longer exists.';
        }

        return $location;
    }

    /**
     * @return array<string, string>
     */
    private function validateDraft(): array
    {
        $errors = [];

        if (trim($this->draft['label']) === '') {
            $errors['label'] = 'Give this location a name.';
        }

        if (! $this->isMobile() && (! is_numeric($this->draft['lat']) || ! is_numeric($this->draft['lng']))) {
            $errors['position'] = 'Choose a position: use your current position or search for an address.';
        }

        return $errors;
    }

    /**
     * A mobile location sends its name and interval. A stationary one sends its name; plus, when it
     * is new or moved, its position with the address; plus the timezone when one is set and the
     * location is new, moved or has a different zone chosen. A moved location sends the zone it
     * kept, because the server never re-resolves the timezone of an existing location.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $body = ['label' => trim($this->draft['label'])];

        if ($this->isMobile()) {
            return [...$body, 'update_interval_minutes' => (int) $this->draft['update_interval_minutes']];
        }

        $moved = $this->isMoved();

        if ($moved) {
            $body += [
                'lat' => (float) $this->draft['lat'],
                'lng' => (float) $this->draft['lng'],
                'address' => filled($this->draft['address']) ? $this->draft['address'] : null,
            ];
        }

        if ($this->draft['timezone'] !== '' && ($moved || $this->draft['timezone'] !== $this->original['timezone'])) {
            $body['timezone'] = $this->draft['timezone'];
        }

        return $body;
    }

    /**
     * Puts each server message on the field it belongs to (latitude, longitude and the address on
     * the position); a message for a detail the page has no field for goes into the summary beside
     * the buttons, so no refusal is silent.
     *
     * @param  array<string, string>  $serverErrors
     * @return array<string, string>
     */
    private function mapServerErrors(array $serverErrors): array
    {
        $slots = $this->isMobile() ? ['label', 'update_interval_minutes'] : ['label', 'position', 'timezone'];
        $errors = [];
        $unplaced = [];

        foreach ($serverErrors as $field => $message) {
            $slot = in_array($field, ['lat', 'lng', 'address'], true) ? 'position' : $field;

            if (in_array($slot, $slots, true)) {
                $errors[$slot] ??= $message;
            } else {
                $unplaced[] = $message;
            }
        }

        if ($unplaced !== [] || $errors === []) {
            $this->banner = trim(self::FIX_SUMMARY.' '.implode(' ', $unplaced));
        }

        return $errors;
    }

    /**
     * @return array<string, string> label => zone. A new location starts on Automatic; an existing
     *                               one offers no Automatic (editing never re-resolves the zone),
     *                               only "Not set" when it was saved without one. A zone outside
     *                               the list is shown as itself.
     */
    private function timezoneMap(): array
    {
        $map = match (true) {
            $this->locationId === null => [self::AUTOMATIC => ''],
            ($this->original['timezone'] ?? '') === '' => [self::NOT_SET => ''],
            default => [],
        } + self::TIMEZONES;

        foreach ([$this->original['timezone'] ?? '', $this->draft['timezone']] as $zone) {
            if ($zone !== '' && ! in_array($zone, $map, true)) {
                $map[$zone] = $zone;
            }
        }

        return $map;
    }

    private function forgetError(string $field): void
    {
        unset($this->errors[$field]);
    }

    /**
     * @param  array<string, mixed>  $location
     */
    private function rememberSaved(array $location): void
    {
        $cache = app(LocalCache::class);
        $locations = $cache->locations() ?? [];
        $replaced = false;

        foreach ($locations as $index => $existing) {
            if ($existing['id'] === ($location['id'] ?? null)) {
                $locations[$index] = $location;
                $replaced = true;
            }
        }

        if (! $replaced && isset($location['id'])) {
            $locations[] = $location;
        }

        $cache->rememberLocations($locations, $cache->locationsMeta() ?? []);
    }
}
