<?php

namespace Tests\Feature;

use App\Clients\Contracts\MessagingServiceClientInterface;
use App\Clients\Contracts\TemplateServiceClientInterface;
use App\Clients\Contracts\UserServiceClientInterface;
use App\Exceptions\ExternalServiceException;
use App\Models\IdempotencyKey;
use App\Models\Notification;
use App\Models\NotificationAttempt;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    private string $privateKey;

    protected function setUp(): void
    {
        parent::setUp();

        [$private, $public] = $this->generateKeyPair();
        $this->privateKey = $private;

        config([
            'jwt.keys.public_content' => $public,
            'jwt.keys.public'         => null,
            'jwt.issuer'              => 'user-service',
            'jwt.audience'            => 'notification-platform',
        ]);
    }

    public function test_successful_full_orchestration(): void
    {
        $userUuid = (string) Str::uuid();
        $token = $this->makeToken();

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

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/notifications', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'queued')
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

        // Verify delivery_references stored
        $notification = Notification::where('user_uuid', $userUuid)->first();
        $this->assertNotNull($notification->delivery_references);
    }

    public function test_messaging_service_failure(): void
    {
        $userUuid = (string) Str::uuid();
        $token = $this->makeToken();

        $this->mockUserClient($userUuid, active: true, channels: ['email']);
        $this->mockTemplateClient();

        $messagingMock = $this->mock(MessagingServiceClientInterface::class);
        $messagingMock->shouldReceive('createDeliveries')
            ->once()
            ->andThrow(new ExternalServiceException('Messaging service unavailable.', 502, [], 'SERVICE_UNAVAILABLE'));

        $payload = [
            'user_uuid'    => $userUuid,
            'template_key' => 'welcome_email',
            'channels'     => ['email'],
            'variables'    => ['name' => 'Alex'],
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/notifications', $payload);

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
        $token = $this->makeToken();

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

        // First request
        $response1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/notifications', $payload);

        $response1->assertCreated();
        $firstUuid = $response1->json('data.uuid');

        // Second request — same idempotency key
        $response2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/notifications', $payload);

        $response2->assertCreated()
            ->assertJsonPath('data.uuid', $firstUuid);

        // Only one notification in DB
        $this->assertSame(1, Notification::where('user_uuid', $userUuid)->count());
        $this->assertSame(1, IdempotencyKey::where('user_uuid', $userUuid)->count());
    }

    public function test_user_not_found(): void
    {
        $userUuid = (string) Str::uuid();
        $token = $this->makeToken();

        $mock = $this->mock(UserServiceClientInterface::class);
        $mock->shouldReceive('fetchUser')
            ->once()
            ->andThrow(new ExternalServiceException('User not found.', 404, [], 'NOT_FOUND'));

        $payload = [
            'user_uuid'    => $userUuid,
            'template_key' => 'welcome_email',
            'channels'     => ['email'],
            'variables'    => ['name' => 'Alex'],
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/notifications', $payload);

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->assertDatabaseMissing('notifications', ['user_uuid' => $userUuid]);
    }

    public function test_disabled_channel_returns_error(): void
    {
        $userUuid = (string) Str::uuid();
        $token = $this->makeToken();

        // User only allows 'push', but we request 'email'
        $this->mockUserClient($userUuid, active: true, channels: ['push']);

        $payload = [
            'user_uuid'    => $userUuid,
            'template_key' => 'welcome_email',
            'channels'     => ['email'],
            'variables'    => ['name' => 'Alex'],
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/notifications', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'NO_CHANNELS_AVAILABLE');
    }

    public function test_rate_limit_exceeded(): void
    {
        $userUuid = (string) Str::uuid();
        $token = $this->makeToken();

        Cache::put("notifications:user:{$userUuid}", 5, now()->addMinute());

        $this->mockUserClient($userUuid, active: true, channels: ['email']);

        $payload = [
            'user_uuid'    => $userUuid,
            'template_key' => 'welcome_email',
            'channels'     => ['email'],
            'variables'    => ['name' => 'Alex'],
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/notifications', $payload);

        $response->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'RATE_LIMIT_EXCEEDED');
    }

    public function test_delivery_payload_includes_recipient_from_user_data(): void
    {
        $userUuid = (string) Str::uuid();
        $token = $this->makeToken();

        $this->mockUserClient($userUuid, active: true, channels: ['email']);
        $this->mockTemplateClient();

        // Capture the payload sent to messaging service
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

        $payload = [
            'user_uuid'    => $userUuid,
            'template_key' => 'welcome_email',
            'channels'     => ['email'],
            'variables'    => ['name' => 'Alex'],
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/notifications', $payload);

        $response->assertCreated();
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
            ->andReturn([
                'channels' => $channels,
            ]);
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

    private function makeToken(string $role = 'admin'): string
    {
        $now = time();
        $payload = [
            'iss'  => 'user-service',
            'aud'  => 'notification-platform',
            'sub'  => 'admin-uuid',
            'typ'  => 'admin',
            'role' => $role,
            'iat'  => $now,
            'exp'  => $now + 3600,
        ];

        return JWT::encode($payload, $this->privateKey, 'RS256');
    }

    private function generateKeyPair(): array
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($res, $privateKey);
        $pub       = openssl_pkey_get_details($res);
        $publicKey = $pub['key'];

        return [$privateKey, $publicKey];
    }
}
