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
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;
use UnitEnum;

class PipelineControl extends Page
{
    protected string $view = 'filament.admin.pages.pipeline-control';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Pipeline Control';

    /**
     * @var array<string, array{status: string, health: ?string}>
     */
    private array $stateCache = [];

    /**
     * @var array<string, FeedHealth>
     */
    private array $healthCache = [];

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
        return $this->healthCache[$feed->container()] ??= $this->resolveHealth($feed);
    }

    private function resolveHealth(PipelineFeed $feed): FeedHealth
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
            ->action(fn () => $this->runDockerAction('NWWS', PipelineFeed::Nwws->container(), 'start', fn () => app(DockerEngineClient::class)->start(PipelineFeed::Nwws->container())));
    }

    public function stopAction(): Action
    {
        return Action::make('stop')
            ->label('Stop')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('This stops the live NWWS-OI feed connection until it is started again.')
            ->visible(fn (): bool => $this->nwwsStatus() === 'running')
            ->action(fn () => $this->runDockerAction('NWWS', PipelineFeed::Nwws->container(), 'stop', fn () => app(DockerEngineClient::class)->stop(PipelineFeed::Nwws->container())));
    }

    public function restartAction(): Action
    {
        return Action::make('restart')
            ->label('Restart')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('This briefly drops the live NWWS-OI feed connection.')
            ->action(fn () => $this->runDockerAction('NWWS', PipelineFeed::Nwws->container(), 'restart', fn () => app(DockerEngineClient::class)->restart(PipelineFeed::Nwws->container())));
    }

    public function startEmwinAction(): Action
    {
        return Action::make('startEmwin')
            ->label('Start')
            ->color('success')
            ->visible(fn (): bool => $this->emwinStatus() !== 'running')
            ->action(fn () => $this->runDockerAction('EMWIN', PipelineFeed::Emwin->container(), 'start', fn () => app(DockerEngineClient::class)->start(PipelineFeed::Emwin->container())));
    }

    public function stopEmwinAction(): Action
    {
        return Action::make('stopEmwin')
            ->label('Stop')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('This stops the live EMWIN Byteblaster feed connection until it is started again.')
            ->visible(fn (): bool => $this->emwinStatus() === 'running')
            ->action(fn () => $this->runDockerAction('EMWIN', PipelineFeed::Emwin->container(), 'stop', fn () => app(DockerEngineClient::class)->stop(PipelineFeed::Emwin->container())));
    }

    public function restartEmwinAction(): Action
    {
        return Action::make('restartEmwin')
            ->label('Restart')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('This briefly drops the live EMWIN Byteblaster feed connection.')
            ->action(fn () => $this->runDockerAction('EMWIN', PipelineFeed::Emwin->container(), 'restart', fn () => app(DockerEngineClient::class)->restart(PipelineFeed::Emwin->container())));
    }

    public function startEmwinHttpAction(): Action
    {
        return Action::make('startEmwinHttp')
            ->label('Start')
            ->color('success')
            ->visible(fn (): bool => $this->emwinHttpStatus() !== 'running')
            ->action(fn () => $this->runDockerAction('EMWIN HTTP', PipelineFeed::EmwinHttp->container(), 'start', fn () => app(DockerEngineClient::class)->start(PipelineFeed::EmwinHttp->container())));
    }

    public function stopEmwinHttpAction(): Action
    {
        return Action::make('stopEmwinHttp')
            ->label('Stop')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('This stops the EMWIN HTTP backup downloader until it is started again.')
            ->visible(fn (): bool => $this->emwinHttpStatus() === 'running')
            ->action(fn () => $this->runDockerAction('EMWIN HTTP', PipelineFeed::EmwinHttp->container(), 'stop', fn () => app(DockerEngineClient::class)->stop(PipelineFeed::EmwinHttp->container())));
    }

    public function restartEmwinHttpAction(): Action
    {
        return Action::make('restartEmwinHttp')
            ->label('Restart')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('This briefly interrupts the EMWIN HTTP backup downloader.')
            ->action(fn () => $this->runDockerAction('EMWIN HTTP', PipelineFeed::EmwinHttp->container(), 'restart', fn () => app(DockerEngineClient::class)->restart(PipelineFeed::EmwinHttp->container())));
    }

    public function logsAction(): Action
    {
        return Action::make('logs')
            ->label('Logs')
            ->color('gray')
            ->slideOver()
            ->modalHeading(function (array $arguments): string {
                $feed = PipelineFeed::tryFrom((string) ($arguments['feed'] ?? ''));

                return $feed === null ? 'Logs' : $feed->label().' logs';
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->mountUsing(function (array $arguments): void {
                $feed = PipelineFeed::tryFrom((string) ($arguments['feed'] ?? ''));

                if ($feed !== null) {
                    // Drop any entry left by an earlier open, then warm the cache once here.
                    // Removing the forget serves stale logs; removing the warm-up makes every
                    // 10s poll re-render re-fetch the logs from Docker.
                    Cache::forget($this->logsCacheKey($feed));
                    $this->logsFor($feed);
                }
            })
            ->modalContent(function (array $arguments): View {
                $feed = PipelineFeed::tryFrom((string) ($arguments['feed'] ?? ''));

                if ($feed === null) {
                    return view('filament.admin.pages.partials.pipeline-logs', ['lines' => [], 'error' => 'Unknown feed.']);
                }

                $lines = $this->logsFor($feed);

                return view('filament.admin.pages.partials.pipeline-logs', [
                    'lines' => $lines ?? [],
                    'notDeployed' => $this->stateOf($feed->container())['status'] === 'not_found',
                    'error' => $lines === null ? 'Could not read the container logs.' : null,
                ]);
            });
    }

    private function logsCacheKey(PipelineFeed $feed): string
    {
        return 'pipeline.logs.'.$this->getId().'.'.$feed->value;
    }

    /**
     * Fetched once when the slide-over mounts and held for its lifetime, so the
     * 10s page poll re-renders the open modal without hitting Docker again.
     * Null means the logs could not be read.
     *
     * @return array<int, string>|null
     */
    private function logsFor(PipelineFeed $feed): ?array
    {
        $entry = Cache::remember($this->logsCacheKey($feed), now()->addMinutes(2), function () use ($feed): array {
            try {
                return ['lines' => app(DockerEngineClient::class)->logs($feed->container())];
            } catch (Throwable) {
                return ['lines' => null];
            }
        });

        return $entry['lines'];
    }

    private function runDockerAction(string $label, string $container, string $verb, callable $callback): void
    {
        try {
            $callback();

            // The action's own ->visible() closures already populated the
            // per-request status cache from before this call ran (Filament
            // re-checks visibility as part of authorizing the action) — the
            // subsequent re-render needs a fresh read, not that stale value.
            unset($this->stateCache[$container], $this->healthCache[$container]);

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
