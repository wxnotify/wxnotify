<?php

namespace App\Filament\Admin\Pages;

use App\Enums\PipelineFeed;
use App\Support\Pipeline\DockerEngineClient;
use App\Support\Pipeline\FeedHealth;
use App\Support\Pipeline\PipelineActivity;
use App\Support\Pipeline\PipelineHeartbeat;
use App\Support\Pipeline\PipelineSummary;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
     * @var array<string, array{status: string, health: ?string}>
     */
    private array $stateCache = [];

    private ?PipelineActivity $activity = null;

    /**
     * @return array{status: string, health: ?string}
     */
    private function stateOf(string $container): array
    {
        return $this->stateCache[$container] ??= $this->resolveState($container);
    }

    private function statusOf(string $container): string
    {
        return $this->stateOf($container)['status'];
    }

    /**
     * @return array{status: string, health: ?string}
     */
    private function resolveState(string $container): array
    {
        try {
            return app(DockerEngineClient::class)->state($container);
        } catch (Throwable) {
            return ['status' => 'unknown', 'health' => null];
        }
    }

    private function activity(): PipelineActivity
    {
        return $this->activity ??= app(PipelineActivity::class);
    }

    private function lastProductFor(PipelineFeed $feed): ?Carbon
    {
        return PipelineHeartbeat::lastReceivedAt($feed->value);
    }

    private function healthFor(PipelineFeed $feed): FeedHealth
    {
        $state = $this->stateOf($feed->container());

        return FeedHealth::evaluate($state['status'], $state['health'], $this->lastProductFor($feed), $feed->staleMinutes());
    }

    /**
     * @return array<int, array{feed: PipelineFeed, health: FeedHealth, lastProduct: ?string, counts: array<int, int>, recent: ?Collection}>
     */
    public function cards(): array
    {
        return array_map(fn (PipelineFeed $feed): array => [
            'feed' => $feed,
            'health' => $this->healthFor($feed),
            'lastProduct' => $this->lastProductFor($feed)?->diffForHumans(),
            'counts' => $this->activity()->hourlyCounts($feed),
            'recent' => $feed === PipelineFeed::Nwws ? $this->activity()->recent($feed) : null,
        ], PipelineFeed::cases());
    }

    public function summary(): PipelineSummary
    {
        $health = [];

        foreach (PipelineFeed::cases() as $feed) {
            $health[$feed->value] = $this->healthFor($feed);
        }

        return PipelineSummary::evaluate($health);
    }

    public function ingestedToday(): int
    {
        return $this->activity()->ingestedToday();
    }

    /**
     * @return Collection<int, array{label: string, comparable: bool, first_seen: Carbon, cells: array<string, array{state: string, at: ?Carbon}>}>
     */
    public function emwinComparison(): Collection
    {
        return $this->activity()->emwinComparison();
    }

    public function nwwsStatus(): string
    {
        return $this->statusOf(PipelineFeed::Nwws->container());
    }

    public function emwinStatus(): string
    {
        return $this->statusOf(PipelineFeed::Emwin->container());
    }

    public function emwinLastProductAt(): ?string
    {
        return $this->lastProductFor(PipelineFeed::Emwin)?->diffForHumans();
    }

    public function emwinHttpStatus(): string
    {
        return $this->statusOf(PipelineFeed::EmwinHttp->container());
    }

    public function emwinHttpLastProductAt(): ?string
    {
        return $this->lastProductFor(PipelineFeed::EmwinHttp)?->diffForHumans();
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
            unset($this->stateCache[$container]);

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
