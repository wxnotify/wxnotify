<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyPipelineWebhookToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.pipeline.webhook_token');
        $given = $request->bearerToken();

        if (blank($expected) || blank($given) || ! hash_equals($expected, $given)) {
            abort(401);
        }

        return $next($request);
    }
}
