<?php

namespace App\Contracts;

interface PipelineStack
{
    /** Whether stop() can really halt the pipeline services. */
    public function canStop(): bool;

    /** Stops every pipeline service so nothing writes during a wipe. */
    public function stop(): void;
}
