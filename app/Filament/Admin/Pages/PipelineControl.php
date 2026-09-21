<?php

namespace App\Filament\Admin\Pages;

use App\Support\Pipeline\DockerEngineClient;
use App\Support\Pipeline\PipelineHeartbeat;
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

    private const EMWIN_CONTAINER = 'wxnotify-emwin-byteblaster';

    private const EMWIN_HTTP_CONTAINER = 'wxnotify-emwin-http';

    /**
     * @var array<string, string>
     */
    private array $statusCache = [];

    private function statusOf(string $container): string
    {
        return $this->statusCache[$container] ??= $this->resolveStatus($container);
    }

    private function resolveStatus(string $container): string
    {
        try {
            return app(DockerEngineClient::class)->status($container);
        } catch (Throwable) {
            return 'unknown';
        }
    }

    private function colorFor(string $status): string
    {
        return match ($status) {
            'running' => 'success',
            'exited' => 'gray',
            'dead' => 'danger',
            default => 'warning',
        };
    }

    private function labelFor(string $status): string
    {
        return match ($status) {
            'not_found' => 'Not found',
            default => ucfirst($status),
        };
    }

    public function nwwsStatus(): string
    {
        return $this->statusOf(self::NWWS_CONTAINER);
    }

    public function nwwsStatusColor(): string
    {
        return $this->colorFor($this->nwwsStatus());
    }

    public function nwwsStatusLabel(): string
    {
        return $this->labelFor($this->nwwsStatus());
    }

    public function emwinStatus(): string
    {
        return $this->statusOf(self::EMWIN_CONTAINER);
    }

    public function emwinStatusColor(): string
    {
        return $this->colorFor($this->emwinStatus());
    }

    public function emwinStatusLabel(): string
    {
        return $this->labelFor($this->emwinStatus());
    }

    public function emwinLastProductAt(): ?string
    {
        return PipelineHeartbeat::lastReceivedAt('emwin')?->diffForHumans();
    }

    public function emwinHttpStatus(): string
    {
        return $this->statusOf(self::EMWIN_HTTP_CONTAINER);
    }

    public function emwinHttpStatusColor(): string
    {
        return $this->colorFor($this->emwinHttpStatus());
    }

    public function emwinHttpStatusLabel(): string
    {
        return $this->labelFor($this->emwinHttpStatus());
    }

    public function emwinHttpLastProductAt(): ?string
    {
        return PipelineHeartbeat::lastReceivedAt('emwin_http')?->diffForHumans();
    }

    public function startAction(): Action
    {
        return Action::make('start')
            ->label('Start')
            ->color('success')
            ->visible(fn (): bool => $this->nwwsStatus() !== 'running')
            ->action(fn () => $this->runDockerAction('NWWS', self::NWWS_CONTAINER, 'start', fn () => app(DockerEngineClient::class)->start(self::NWWS_CONTAINER)));
    }

    public function stopAction(): Action
    {
        return Action::make('stop')
            ->label('Stop')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('This stops the live NWWS-OI feed connection until it is started again.')
            ->visible(fn (): bool => $this->nwwsStatus() === 'running')
            ->action(fn () => $this->runDockerAction('NWWS', self::NWWS_CONTAINER, 'stop', fn () => app(DockerEngineClient::class)->stop(self::NWWS_CONTAINER)));
    }

    public function restartAction(): Action
    {
        return Action::make('restart')
            ->label('Restart')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('This briefly drops the live NWWS-OI feed connection.')
            ->action(fn () => $this->runDockerAction('NWWS', self::NWWS_CONTAINER, 'restart', fn () => app(DockerEngineClient::class)->restart(self::NWWS_CONTAINER)));
    }

    public function startEmwinAction(): Action
    {
        return Action::make('startEmwin')
            ->label('Start')
            ->color('success')
            ->visible(fn (): bool => $this->emwinStatus() !== 'running')
            ->action(fn () => $this->runDockerAction('EMWIN', self::EMWIN_CONTAINER, 'start', fn () => app(DockerEngineClient::class)->start(self::EMWIN_CONTAINER)));
    }

    public function stopEmwinAction(): Action
    {
        return Action::make('stopEmwin')
            ->label('Stop')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('This stops the live EMWIN Byteblaster feed connection until it is started again.')
            ->visible(fn (): bool => $this->emwinStatus() === 'running')
            ->action(fn () => $this->runDockerAction('EMWIN', self::EMWIN_CONTAINER, 'stop', fn () => app(DockerEngineClient::class)->stop(self::EMWIN_CONTAINER)));
    }

    public function restartEmwinAction(): Action
    {
        return Action::make('restartEmwin')
            ->label('Restart')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('This briefly drops the live EMWIN Byteblaster feed connection.')
            ->action(fn () => $this->runDockerAction('EMWIN', self::EMWIN_CONTAINER, 'restart', fn () => app(DockerEngineClient::class)->restart(self::EMWIN_CONTAINER)));
    }

    public function startEmwinHttpAction(): Action
    {
        return Action::make('startEmwinHttp')
            ->label('Start')
            ->color('success')
            ->visible(fn (): bool => $this->emwinHttpStatus() !== 'running')
            ->action(fn () => $this->runDockerAction('EMWIN HTTP', self::EMWIN_HTTP_CONTAINER, 'start', fn () => app(DockerEngineClient::class)->start(self::EMWIN_HTTP_CONTAINER)));
    }

    public function stopEmwinHttpAction(): Action
    {
        return Action::make('stopEmwinHttp')
            ->label('Stop')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('This stops the EMWIN HTTP backup downloader until it is started again.')
            ->visible(fn (): bool => $this->emwinHttpStatus() === 'running')
            ->action(fn () => $this->runDockerAction('EMWIN HTTP', self::EMWIN_HTTP_CONTAINER, 'stop', fn () => app(DockerEngineClient::class)->stop(self::EMWIN_HTTP_CONTAINER)));
    }

    public function restartEmwinHttpAction(): Action
    {
        return Action::make('restartEmwinHttp')
            ->label('Restart')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('This briefly interrupts the EMWIN HTTP backup downloader.')
            ->action(fn () => $this->runDockerAction('EMWIN HTTP', self::EMWIN_HTTP_CONTAINER, 'restart', fn () => app(DockerEngineClient::class)->restart(self::EMWIN_HTTP_CONTAINER)));
    }

    private function runDockerAction(string $label, string $container, string $verb, callable $callback): void
    {
        try {
            $callback();

            // The action's own ->visible() closures already populated the
            // per-request status cache from before this call ran (Filament
            // re-checks visibility as part of authorizing the action) — the
            // subsequent re-render needs a fresh read, not that stale value.
            unset($this->statusCache[$container]);

            Notification::make()
                ->title("{$label} {$verb} succeeded")
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title("Failed to {$verb} {$label}")
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
