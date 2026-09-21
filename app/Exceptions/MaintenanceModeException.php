<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

class MaintenanceModeException extends RuntimeException
{
    public function render(Request $request): Response
    {
        return response($this->getMessage(), 503, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
