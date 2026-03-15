<?php

namespace Tests\Feature;

use App\Clients\Contracts\MessagingServiceClientInterface;
use App\Clients\Contracts\TemplateServiceClientInterface;
use App\Clients\Contracts\UserServiceClientInterface;
use App\Models\Notification;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationApiTest extends TestCase
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

    public function test_health_returns_envelope_and_correlation(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertHeader('X-Correlation-Id')
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['correlation_id']);
    }

    public function test_notifications_require_auth(): void
    {
        $response = $this->postJson('/api/v1/notifications', []);

        $response->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'AUTH_INVALID')
            ->assertJsonStructure(['correlation_id']);
    }

    public function test_authenticated_admin_can_create_notification(): void
    {
        $token = $this->makeToken();
        $userUuid = (string) Str::uuid();

        // Mock service clients for orchestration
        $userMock = $this->mock(UserServiceClientInterface::class);
        $userMock->shouldReceive('fetchUser')->andReturn([
            'uuid' => $userUuid, 'is_active' => true, 'name' => 'Test', 'email' => 'test@example.com',
        ]);
        $userMock->shouldReceive('fetchPreferences')->andReturn(['channels' => ['email', 'push']]);

        $templateMock = $this->mock(TemplateServiceClientInterface::class);
        $templateMock->shouldReceive('render')->andReturn(['subject' => 'Welcome!', 'content' => 'Hello']);

        $messagingMock = $this->mock(MessagingServiceClientInterface::class);
        $messagingMock->shouldReceive('send')->andReturn(['message_id' => 'msg-1', 'status' => 'accepted']);

        $payload = [
            'user_uuid'       => $userUuid,
            'template_key'    => 'welcome_email',
            'channels'        => ['email', 'push'],
            'variables'       => ['name' => 'Alex'],
            'idempotency_key' => 'idem-123',
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/notifications', $payload);

        $response->assertCreated()
            ->assertHeader('X-Correlation-Id')
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.template_key', 'welcome_email')
            ->assertJsonStructure(['correlation_id']);

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
            ->getJson('/api/v1/notifications/'.$notification->uuid);

        $response->assertOk()
            ->assertJsonPath('data.uuid', $notification->uuid)
            ->assertJsonStructure(['correlation_id']);
    }

    public function test_validation_errors_return_422_envelope(): void
    {
        $token = $this->makeToken();

        $payload = [
            'user_uuid'    => (string) Str::uuid(),
            'template_key' => 'welcome_email',
            'channels'     => [], // invalid: min 1
            'variables'    => ['name' => 'Alex'],
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/notifications', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors', 'correlation_id']);
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
        $pub        = openssl_pkey_get_details($res);
        $publicKey  = $pub['key'];

        return [$privateKey, $publicKey];
    }
}
