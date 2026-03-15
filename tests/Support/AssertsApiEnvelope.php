<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

trait AssertsApiEnvelope
{
    private function assertApiSuccess(TestResponse $response, int $status = 200): void
    {
        $response->assertStatus($status)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'data', 'meta', 'correlation_id']);
    }

    private function assertApiError(TestResponse $response, int $status, ?string $errorCode = null): void
    {
        $response->assertStatus($status)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'errors', 'error_code', 'correlation_id', 'meta']);

        if ($errorCode !== null) {
            $response->assertJsonPath('error_code', $errorCode);
        }
    }
}
