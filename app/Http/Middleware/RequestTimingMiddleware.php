<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RequestTimingMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);

        $response = $next($request);

        $latencyMs = (microtime(true) - $started) * 1000;

        Log::info('request.completed', [
            'method'         => $request->method(),
            'url'            => $request->fullUrl(),
            'status_code'    => $response->getStatusCode(),
            'latency_ms'     => round($latencyMs, 2),
            'ip'             => $request->ip(),
            'user_agent'     => $request->userAgent(),
            'correlation_id' => $request->header('X-Correlation-Id', ''),
        ]);

        return $response;
    }
}
