<?php

namespace App\Clients\Concerns;

use App\Exceptions\ExternalServiceException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait MakesHttpRequests
{
    private function request(): PendingRequest
    {
        $correlationId = request()->header('X-Correlation-Id', '');

        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->withHeaders(['X-Correlation-Id' => $correlationId])
            ->timeout(10)
            ->connectTimeout(5);
    }

    private function authenticatedRequest(string $token): PendingRequest
    {
        return $this->request()->withToken($token);
    }

    private function timedRequest(callable $callback, string $endpoint, string $method): Response
    {
        $started = microtime(true);
        $response = $callback();

        $latencyMs = (microtime(true) - $started) * 1000;

        Log::info('http.outbound', [
            'service'        => $this->serviceName,
            'endpoint'       => $endpoint,
            'method'         => $method,
            'status_code'    => $response->status(),
            'latency_ms'     => round($latencyMs, 2),
            'correlation_id' => request()->header('X-Correlation-Id', ''),
        ]);

        return $response;
    }

    private function extractData(Response $response, string $fallbackMessage): array
    {
        $json = $response->json();

        if (($json['success'] ?? false) === true) {
            return $json['data'] ?? [];
        }

        $this->throwServiceException($response, $json, $fallbackMessage);
    }

    private function throwServiceException(Response $response, ?array $json, string $fallbackMessage): never
    {
        throw new ExternalServiceException(
            $json['message'] ?? $fallbackMessage,
            $response->status(),
            $json['errors'] ?? [],
            $json['error_code'] ?? null,
            $json['correlation_id'] ?? $response->header('X-Correlation-Id', ''),
        );
    }
}
