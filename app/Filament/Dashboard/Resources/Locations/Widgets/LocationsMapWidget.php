<?php

namespace App\Filament\Dashboard\Resources\Locations\Widgets;

use App\Actions\Locations\GetLocationMap;
use App\Filament\Dashboard\Resources\Locations\LocationResource;
use Filament\Widgets\Widget;
use Livewire\Attributes\Renderless;

/**
 * The location map above the Locations table. The page renders the map
 * container immediately; the browser then calls locations() (database only,
 * the signed-in subscriber's own rows) now and every minute, so a mobile
 * location's pin follows the Traccar poller.
 */
class LocationsMapWidget extends Widget
{
    protected string $view = 'filament.dashboard.widgets.locations-map';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    /**
     * Mirrors the resource's gate, so it will start protecting this widget if
     * a LocationPolicy is added. Today there is no policy and
     * LocationResource::canAccess() returns true for everyone, so this is
     * effectively a no-op. What actually protects locations() is Filament's
     * Authenticate, registered as Livewire persistent middleware (the update
     * endpoint re-authenticates), the data being scoped to auth()->user(), and
     * the component having no public state to tamper with.
     */
    public static function canView(): bool
    {
        return LocationResource::canAccess();
    }

    /**
     * @return array{locations: list<array<string, mixed>>, unplaced: int}
     */
    #[Renderless]
    public function locations(): array
    {
        return app(GetLocationMap::class)(auth()->user()->locations()->get());
    }
}
