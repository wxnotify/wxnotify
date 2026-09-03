<?php

namespace App\Filament\Admin\Pages;

use App\Support\Pipeline\DockerEngineClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Throwable;
use UnitEnum;

class PipelineControl extends Page
{
    protected string $view = 'filament.admin.pages.pipeline-control';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Pipeline Control';

    private const NWWS_CONTAINER = 'wxnotify-nwws';

    private ?string $nwwsStatusCache = null;

    public function nwwsStatus(): string
    {
        return $this->nwwsStatusCache ??= $this->resolveNwwsStatus();
    }

    private function resolveNwwsStatus(): string
    {
        try {
            return app(DockerEngineClient::class)->status(self::NWWS_CONTAINER);
        } catch (Throwable) {
            return 'unknown';
        }
    }

    public function nwwsStatusColor(): string
    {
        return match ($this->nwwsStatus()) {
            'running' => 'success',
            'exited' => 'gray',
            'dead' => 'danger',
            default => 'warning',
        };
    }

    public function nwwsStatusLabel(): string
    {
        return match ($status = $this->nwwsStatus()) {
            'not_found' => 'Not found',
            default => ucfirst($status),
        };
    }

    public function startAction(): Action
    {
        return Action::make('start')
            ->label('Start')
            ->color('success')
            ->visible(fn (): bool => $this->nwwsStatus() !== 'running')
            ->action(fn () => $this->runDockerAction('start', fn () => app(DockerEngineClient::class)->start(self::NWWS_CONTAINER)));
    }

    public function stopAction(): Action
    {
        return Action::make('stop')
            ->label('Stop')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('This stops the live NWWS-OI feed connection until it is started again.')
            ->visible(fn (): bool => $this->nwwsStatus() === 'running')
            ->action(fn () => $this->runDockerAction('stop', fn () => app(DockerEngineClient::class)->stop(self::NWWS_CONTAINER)));
    }

    public function restartAction(): Action
    {
        return Action::make('restart')
            ->label('Restart')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('This briefly drops the live NWWS-OI feed connection.')
            ->action(fn () => $this->runDockerAction('restart', fn () => app(DockerEngineClient::class)->restart(self::NWWS_CONTAINER)));
    }

    private function runDockerAction(string $verb, callable $callback): void
    {
        try {
            $callback();

            // The action's own ->visible() closures already populated the
            // per-request status cache from before this call ran (Filament
            // re-checks visibility as part of authorizing the action) — the
            // subsequent re-render needs a fresh read, not that stale value.
            $this->nwwsStatusCache = null;

            Notification::make()
                ->title("NWWS {$verb} succeeded")
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title("Failed to {$verb} NWWS")
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
