<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CorrelationIdMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header('X-Correlation-Id', (string) Str::uuid());

        $request->headers->set('X-Correlation-Id', $correlationId);
        $request->attributes->set('correlation_id', $correlationId);
        Log::shareContext(['correlation_id' => $correlationId]);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Correlation-Id', $correlationId);

        return $response;
    }
}
