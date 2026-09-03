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

    public function nwwsStatus(): string
    {
        return app(DockerEngineClient::class)->status(self::NWWS_CONTAINER);
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
