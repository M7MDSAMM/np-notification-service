<?php

namespace Tests\Feature;

use App\Clients\Contracts\MessagingServiceClientInterface;
use App\Clients\Contracts\TemplateServiceClientInterface;
use App\Clients\Contracts\UserServiceClientInterface;
use App\Exceptions\ExternalServiceException;
use App\Models\IdempotencyKey;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\AssertsApiEnvelope;
use Tests\Support\JwtHelper;
use Tests\TestCase;

class NotificationOrchestrationTest extends TestCase
{
    use RefreshDatabase, JwtHelper, AssertsApiEnvelope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpJwt();
    }

    public function test_successful_full_orchestration(): void
    {
        $userUuid = (string) Str::uuid();

        $this->mockUserClient($userUuid, active: true, channels: ['email', 'push']);
        $this->mockTemplateClient();
        $this->mockMessagingClient();

        $payload = [
            'user_uuid'       => $userUuid,
            'template_key'    => 'welcome_email',
            'channels'        => ['email'],
            'variables'       => ['name' => 'Alex'],
            'idempotency_key' => 'idem-001',
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->makeToken())
            ->postJson('/api/v1/notifications', $payload);

        $this->assertApiSuccess($response, 201);
        $response->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.template_key', 'welcome_email');

        $this->assertDatabaseHas('notifications', [
            'user_uuid'    => $userUuid,
            'template_key' => 'welcome_email',
            'status'       => 'queued',
        ]);

        $this->assertDatabaseHas('idempotency_keys', [
            'user_uuid'       => $userUuid,
            'idempotency_key' => 'idem-001',
        ]);

        $this->assertDatabaseHas('notification_attempts', [
            'channel' => 'email',
            'status'  => 'pending',
        ]);

        $notification = Notification::where('user_uuid', $userUuid)->first();
        $this->assertNotNull($notification->delivery_references);
    }

    public function test_messaging_service_failure(): void
    {
        $userUuid = (string) Str::uuid();

        $this->mockUserClient($userUuid, active: true, channels: ['email']);
        $this->mockTemplateClient();

        $messagingMock = $this->mock(MessagingServiceClientInterface::class);
        $messagingMock->shouldReceive('createDeliveries')
            ->once()
            ->andThrow(new ExternalServiceException('Messaging service unavailable.', 502, [], 'SERVICE_UNAVAILABLE'));

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->makeToken())
            ->postJson('/api/v1/notifications', [
                'user_uuid'    => $userUuid,
                'template_key' => 'welcome_email',
                'channels'     => ['email'],
                'variables'    => ['name' => 'Alex'],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'failed');

        $this->assertDatabaseHas('notifications', [
            'user_uuid'  => $userUuid,
            'status'     => 'failed',
            'last_error' => 'Messaging service unavailable.',
        ]);

        $this->assertDatabaseHas('notification_attempts', [
            'status'        => 'failed',
            'error_message' => 'Messaging service unavailable.',
        ]);
    }

    public function test_idempotency_protection(): void
    {
        $userUuid = (string) Str::uuid();

        $this->mockUserClient($userUuid, active: true, channels: ['email']);
        $this->mockTemplateClient();
        $this->mockMessagingClient();

        $payload = [
            'user_uuid'       => $userUuid,
            'template_key'    => 'welcome_email',
            'channels'        => ['email'],
            'variables'       => ['name' => 'Alex'],
            'idempotency_key' => 'idem-dup',
        ];

        $response1 = $this->withHeader('Authorization', 'Bearer ' . $this->makeToken())
            ->postJson('/api/v1/notifications', $payload);

        $response1->assertCreated();
        $firstUuid = $response1->json('data.uuid');

        $response2 = $this->withHeader('Authorization', 'Bearer ' . $this->makeToken())
            ->postJson('/api/v1/notifications', $payload);

        $response2->assertCreated()
            ->assertJsonPath('data.uuid', $firstUuid);

        $this->assertSame(1, Notification::where('user_uuid', $userUuid)->count());
        $this->assertSame(1, IdempotencyKey::where('user_uuid', $userUuid)->count());
    }

    public function test_user_not_found(): void
    {
        $userUuid = (string) Str::uuid();

        $mock = $this->mock(UserServiceClientInterface::class);
        $mock->shouldReceive('fetchUser')
            ->once()
            ->andThrow(new ExternalServiceException('User not found.', 404, [], 'NOT_FOUND'));

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->makeToken())
            ->postJson('/api/v1/notifications', [
                'user_uuid'    => $userUuid,
                'template_key' => 'welcome_email',
                'channels'     => ['email'],
                'variables'    => ['name' => 'Alex'],
            ]);

        $this->assertApiError($response, 404, 'NOT_FOUND');
        $this->assertDatabaseMissing('notifications', ['user_uuid' => $userUuid]);
    }

    public function test_inactive_user_returns_error(): void
    {
        $userUuid = (string) Str::uuid();

        $mock = $this->mock(UserServiceClientInterface::class);
        $mock->shouldReceive('fetchUser')
            ->andReturn([
                'uuid' => $userUuid, 'is_active' => false,
                'name' => 'Inactive', 'email' => 'inactive@example.com',
            ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->makeToken())
            ->postJson('/api/v1/notifications', [
                'user_uuid'    => $userUuid,
                'template_key' => 'welcome_email',
                'channels'     => ['email'],
                'variables'    => ['name' => 'Alex'],
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('notifications', ['user_uuid' => $userUuid]);
    }

    public function test_disabled_channel_returns_error(): void
    {
        $userUuid = (string) Str::uuid();

        $this->mockUserClient($userUuid, active: true, channels: ['push']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->makeToken())
            ->postJson('/api/v1/notifications', [
                'user_uuid'    => $userUuid,
                'template_key' => 'welcome_email',
                'channels'     => ['email'],
                'variables'    => ['name' => 'Alex'],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'NO_CHANNELS_AVAILABLE');
    }

    public function test_rate_limit_exceeded(): void
    {
        $userUuid = (string) Str::uuid();

        Cache::put("notifications:user:{$userUuid}", 5, now()->addMinute());

        $this->mockUserClient($userUuid, active: true, channels: ['email']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->makeToken())
            ->postJson('/api/v1/notifications', [
                'user_uuid'    => $userUuid,
                'template_key' => 'welcome_email',
                'channels'     => ['email'],
                'variables'    => ['name' => 'Alex'],
            ]);

        $response->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'RATE_LIMIT_EXCEEDED');
    }

    public function test_delivery_payload_includes_recipient_from_user_data(): void
    {
        $userUuid = (string) Str::uuid();

        $this->mockUserClient($userUuid, active: true, channels: ['email']);
        $this->mockTemplateClient();

        $messagingMock = $this->mock(MessagingServiceClientInterface::class);
        $messagingMock->shouldReceive('createDeliveries')
            ->once()
            ->withArgs(function (string $t, array $payload) {
                return isset($payload['deliveries'][0]['recipient'])
                    && $payload['deliveries'][0]['recipient'] === 'test@example.com'
                    && $payload['deliveries'][0]['channel'] === 'email'
                    && isset($payload['deliveries'][0]['payload']['template_key']);
            })
            ->andReturn([
                'notification_uuid' => $userUuid,
                'deliveries'        => [['uuid' => 'del-1', 'channel' => 'email', 'status' => 'pending']],
            ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->makeToken())
            ->postJson('/api/v1/notifications', [
                'user_uuid'    => $userUuid,
                'template_key' => 'welcome_email',
                'channels'     => ['email'],
                'variables'    => ['name' => 'Alex'],
            ]);

        $response->assertCreated();
    }

    public function test_template_service_failure_returns_error(): void
    {
        $userUuid = (string) Str::uuid();

        $this->mockUserClient($userUuid, active: true, channels: ['email']);

        $templateMock = $this->mock(TemplateServiceClientInterface::class);
        $templateMock->shouldReceive('render')
            ->once()
            ->andThrow(new ExternalServiceException('Template not found.', 404, [], 'NOT_FOUND'));

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->makeToken())
            ->postJson('/api/v1/notifications', [
                'user_uuid'    => $userUuid,
                'template_key' => 'nonexistent_template',
                'channels'     => ['email'],
                'variables'    => ['name' => 'Alex'],
            ]);

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function mockUserClient(string $userUuid, bool $active = true, array $channels = ['email']): void
    {
        $mock = $this->mock(UserServiceClientInterface::class);

        $mock->shouldReceive('fetchUser')
            ->andReturn([
                'uuid'       => $userUuid,
                'is_active'  => $active,
                'name'       => 'Test User',
                'email'      => 'test@example.com',
                'phone_e164' => '+1234567890',
            ]);

        $mock->shouldReceive('fetchPreferences')
            ->andReturn(['channels' => $channels]);
    }

    private function mockTemplateClient(): void
    {
        $mock = $this->mock(TemplateServiceClientInterface::class);
        $mock->shouldReceive('render')
            ->andReturn([
                'subject' => 'Welcome!',
                'content' => 'Hello Alex, welcome aboard.',
            ]);
    }

    private function mockMessagingClient(): void
    {
        $mock = $this->mock(MessagingServiceClientInterface::class);
        $mock->shouldReceive('createDeliveries')
            ->andReturn([
                'notification_uuid' => 'notif-uuid',
                'deliveries'        => [
                    ['uuid' => 'del-1', 'channel' => 'email', 'status' => 'pending'],
                ],
            ]);
    }
}
