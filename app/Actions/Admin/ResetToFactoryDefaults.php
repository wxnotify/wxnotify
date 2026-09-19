<?php

namespace App\Actions\Admin;

use App\Actions\Admin\FactoryReset\ResetCatalogs;
use App\Actions\Admin\FactoryReset\WipePipelineData;
use App\Actions\Admin\FactoryReset\WipeSubscriberData;
use App\Contracts\PipelineStack;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Activitylog\Support\CauserResolver;
use Throwable;

class ResetToFactoryDefaults
{
    public function __construct(private readonly PipelineStack $pipelineStack) {}

    /**
     * @param  array{pipeline?: bool, catalogs?: bool, subscribers?: bool}  $toggles
     * @return array<string, array<string, int>> deleted row counts per group
     */
    public function __invoke(array $toggles, User $by, bool $pipelineStopped): array
    {
        $selected = array_keys(array_filter($toggles));

        if ($selected === []) {
            throw new InvalidArgumentException('Select at least one group to reset.');
        }

        $wipesPipeline = in_array('pipeline', $selected, true);

        if ($wipesPipeline && ! $this->pipelineStack->canStop() && ! $pipelineStopped) {
            throw new DomainException('Stop the pipeline services and confirm before wiping pipeline data.');
        }

        if ($wipesPipeline && $this->pipelineStack->canStop()) {
            $this->pipelineStack->stop();
        }

        $result = DB::transaction(function () use ($selected): array {
            $result = [];

            if (in_array('pipeline', $selected, true)) {
                $result['pipeline'] = app(WipePipelineData::class)();
            }
            if (in_array('catalogs', $selected, true)) {
                $result['catalogs'] = app(ResetCatalogs::class)();
            }
            if (in_array('subscribers', $selected, true)) {
                $result['subscribers'] = app(WipeSubscriberData::class)();
            }

            return $result;
        });

        // The app's CauserResolver override ignores an explicit causedBy() argument
        // (it always returns the authenticated/impersonating user), so pin the owner.
        // The data is already gone at this point, so a failed audit write must be
        // reported loudly but must not make the completed reset look like it failed.
        try {
            app(CauserResolver::class)->withCauser($by, fn () => activity()
                ->withProperties(['toggles' => $selected, 'deleted' => $result])
                ->log('Reset to factory defaults'));
        } catch (Throwable $e) {
            report($e);
        }

        return $result;
    }
}
