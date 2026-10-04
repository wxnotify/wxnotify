<?php

namespace App\Filament\Dashboard\Resources\TraccarServers\Pages;

use App\Actions\Traccar\PollTraccarServer;
use App\Filament\Dashboard\Resources\TraccarServers\TraccarServerResource;
use App\Models\TraccarServer;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateTraccarServer extends CreateRecord
{
    protected static string $resource = TraccarServerResource::class;

    protected function beforeCreate(): void
    {
        if (! auth()->user()->canCreateTraccarServer()) {
            Notification::make()
                ->title('Traccar server limit reached')
                ->body('Your plan allows '.auth()->user()->traccarServerLimit().' Traccar server(s). Upgrade to add more.')
                ->danger()
                ->send();

            $this->halt();

            return;
        }

        // Probe the server before it's ever persisted -- a server that
        // can't actually be reached/authenticated shouldn't be saved at
        // all. Uses an unsaved TraccarServer instance rather than the real
        // record (which doesn't exist yet at this point in the lifecycle);
        // the encrypted api_token cast round-trips correctly on an unsaved
        // model since it operates purely on the in-memory attributes.
        $data = $this->form->getState();

        $probe = new TraccarServer([
            'base_url' => $data['base_url'],
            'api_token' => $data['api_token'],
        ]);

        $probeResult = app(PollTraccarServer::class)->poll($probe);

        if ($probeResult['positions'] === null) {
            Notification::make()
                ->title('Couldn\'t connect to this Traccar server')
                ->body(($probeResult['error'] ?? 'Check the server URL and API token.').' Check the server URL and API token, then try again.')
                ->danger()
                ->send();

            $this->halt();
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        // The probe in beforeCreate() already proved this server is
        // reachable -- record that success immediately rather than making
        // the subscriber wait for the next traccar:poll-positions run
        // (up to 5 minutes) before "Last polled"/"Status" reflect reality.
        $this->record->forceFill([
            'last_polled_at' => now(),
            'last_poll_error' => null,
        ])->save();
    }
}
