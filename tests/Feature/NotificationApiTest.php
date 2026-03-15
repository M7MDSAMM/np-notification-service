<?php

namespace Tests\Feature;

use App\Clients\Contracts\MessagingServiceClientInterface;
use App\Clients\Contracts\TemplateServiceClientInterface;
use App\Clients\Contracts\UserServiceClientInterface;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\AssertsApiEnvelope;
use Tests\Support\JwtHelper;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase, JwtHelper, AssertsApiEnvelope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJwt();
    }

    public function test_health_returns_envelope_and_correlation(): void
    {
        $response = $this->getJson('/api/v1/health');

        $this->assertApiSuccess($response);
        $response->assertHeader('X-Correlation-Id')
            ->assertJsonPath('data.service', 'notification-service');
    }

    public function test_notifications_require_auth(): void
    {
        $response = $this->postJson('/api/v1/notifications', []);

        $this->assertApiError($response, 401, 'AUTH_INVALID');
    }

    public function test_malformed_token_returns_401(): void
    {
        $response = $this->withToken('not-a-valid-jwt')
            ->postJson('/api/v1/notifications', []);

        $this->assertApiError($response, 401, 'AUTH_INVALID');
    }

    public function test_authenticated_admin_can_create_notification(): void
    {
        $token = $this->makeToken();
        $userUuid = (string) Str::uuid();

        $this->mockServiceClients($userUuid);

        $payload = [
            'user_uuid'       => $userUuid,
            'template_key'    => 'welcome_email',
            'channels'        => ['email', 'push'],
            'variables'       => ['name' => 'Alex'],
            'idempotency_key' => 'idem-123',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/notifications', $payload);

        $this->assertApiSuccess($response, 201);
        $response->assertJsonPath('data.template_key', 'welcome_email')
            ->assertHeader('X-Correlation-Id');

        $this->assertDatabaseHas('notifications', [
            'template_key' => 'welcome_email',
            'user_uuid'    => $userUuid,
        ]);
    }

    public function test_can_fetch_by_uuid(): void
    {
        $token = $this->makeToken();

        $notification = Notification::create([
            'user_uuid'    => (string) Str::uuid(),
            'template_key' => 'reset_password',
            'channels'     => ['email'],
            'variables'    => ['name' => 'Sam'],
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/notifications/' . $notification->uuid);

        $this->assertApiSuccess($response);
        $response->assertJsonPath('data.uuid', $notification->uuid);
    }

    public function test_fetch_nonexistent_returns_404(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->makeToken())
            ->getJson('/api/v1/notifications/nonexistent-uuid');

        $this->assertApiError($response, 404, 'NOT_FOUND');
    }

    public function test_validation_errors_return_422_envelope(): void
    {
        $token = $this->makeToken();

        $payload = [
            'user_uuid'    => (string) Str::uuid(),
            'template_key' => 'welcome_email',
            'channels'     => [],
            'variables'    => ['name' => 'Alex'],
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/notifications', $payload);

        $this->assertApiError($response, 422, 'VALIDATION_ERROR');
    }

    public function test_list_notifications_returns_data(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->makeToken())
            ->getJson('/api/v1/notifications');

        $this->assertApiSuccess($response);
        $this->assertIsArray($response->json('data'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function mockServiceClients(string $userUuid): void
    {
        $userMock = $this->mock(UserServiceClientInterface::class);
        $userMock->shouldReceive('fetchUser')->andReturn([
            'uuid' => $userUuid, 'is_active' => true, 'name' => 'Test', 'email' => 'test@example.com',
        ]);
        $userMock->shouldReceive('fetchPreferences')->andReturn(['channels' => ['email', 'push']]);

        $templateMock = $this->mock(TemplateServiceClientInterface::class);
        $templateMock->shouldReceive('render')->andReturn(['subject' => 'Welcome!', 'content' => 'Hello']);

        $messagingMock = $this->mock(MessagingServiceClientInterface::class);
        $messagingMock->shouldReceive('createDeliveries')->andReturn([
            'notification_uuid' => $userUuid,
            'deliveries'        => [['uuid' => 'del-1', 'channel' => 'email', 'status' => 'pending']],
        ]);
    }
}
