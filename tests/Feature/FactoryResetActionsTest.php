<?php

use App\Contracts\PipelineStack;
use App\Support\Pipeline\UnmanagedPipelineStack;

test('the default pipeline stack cannot stop services and refuses to pretend it did', function () {
    $stack = app(PipelineStack::class);

    expect($stack)->toBeInstanceOf(UnmanagedPipelineStack::class)
        ->and($stack->canStop())->toBeFalse()
        ->and(fn () => $stack->stop())->toThrow(LogicException::class);
});
