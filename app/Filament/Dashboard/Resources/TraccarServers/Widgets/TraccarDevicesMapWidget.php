<?php

namespace App\Filament\Dashboard\Resources\TraccarServers\Widgets;

use App\Actions\Traccar\GetTraccarDeviceMap;
use App\Filament\Dashboard\Resources\TraccarServers\TraccarServerResource;
use Filament\Widgets\Widget;
use Livewire\Attributes\Renderless;

/**
 * The device map above the Traccar Servers table. The page renders the map
 * container immediately; the browser then calls devices() (which fetches live
 * from the subscriber's servers, cached 60 s per server) so one slow or dead
 * Traccar server can never delay the page itself. Only the signed-in
 * subscriber's own servers are ever queried.
 */
class TraccarDevicesMapWidget extends Widget
{
    protected string $view = 'filament.dashboard.widgets.traccar-devices-map';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    /**
     * Mirrors the resource's tier gate. Filament checks canView() on every
     * hydrate, so the devices() endpoint is unreachable below the Plus tier.
     */
    public static function canView(): bool
    {
        return TraccarServerResource::canAccess();
    }

    /**
     * @return array{devices: array<int, array<string, mixed>>, unplaced: int, unreachable: array<int, string>, has_servers: bool}
     */
    #[Renderless]
    public function devices(): array
    {
        $servers = auth()->user()->traccarServers()->get();

        return [
            ...app(GetTraccarDeviceMap::class)($servers),
            'has_servers' => $servers->isNotEmpty(),
        ];
    }
}
