<?php

namespace App\Support\Pipeline;

use App\Contracts\PipelineStack;
use LogicException;

/**
 * Placeholder until Downloader control can stop the whole Pipeline stack.
 * The factory-reset UI makes the admin acknowledge stopping services by hand.
 */
class UnmanagedPipelineStack implements PipelineStack
{
    public function canStop(): bool
    {
        return false;
    }

    public function stop(): void
    {
        throw new LogicException('The pipeline stack cannot be stopped from here; stop the services manually.');
    }
}
