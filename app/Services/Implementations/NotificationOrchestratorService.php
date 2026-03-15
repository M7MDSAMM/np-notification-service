<?php

namespace App\Services\Implementations;

use App\Clients\Contracts\MessagingServiceClientInterface;
use App\Clients\Contracts\TemplateServiceClientInterface;
use App\Clients\Contracts\UserServiceClientInterface;
use App\Exceptions\ExternalServiceException;
use App\Models\IdempotencyKey;
use App\Models\Notification;
use App\Models\NotificationAttempt;
use App\Services\Contracts\NotificationOrchestratorInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class NotificationOrchestratorService implements NotificationOrchestratorInterface
{
    public function __construct(
        private readonly UserServiceClientInterface $userClient,
        private readonly TemplateServiceClientInterface $templateClient,
        private readonly MessagingServiceClientInterface $messagingClient,
    ) {}

    public function createNotification(array $payload): array
    {
        $token = request()->bearerToken();
        $correlationId = request()->header('X-Correlation-Id', '');
        $adminUuid = request()->attributes->get('auth_admin_uuid');
        $userUuid = $payload['user_uuid'];

        Log::info('notification.orchestration.started', [
            'user_uuid'         => $userUuid,
            'template_key'      => $payload['template_key'],
            'channels'          => $payload['channels'],
            'correlation_id'    => $correlationId,
            'acting_admin_uuid' => $adminUuid,
        ]);

        // 1. Idempotency check
        if (! empty($payload['idempotency_key'])) {
            $existing = IdempotencyKey::where('user_uuid', $userUuid)
                ->where('idempotency_key', $payload['idempotency_key'])
                ->first();

            if ($existing) {
                $notification = Notification::where('uuid', $existing->notification_uuid)->firstOrFail();

                return $notification->load('attempts')->toArray();
            }
        }

        // 2. Validate user
        $user = $this->userClient->fetchUser($token, $userUuid);

        if (! ($user['is_active'] ?? false)) {
            throw new ExternalServiceException(
                'User is not active.',
                422,
                [],
                'USER_INACTIVE',
                $correlationId,
            );
        }

        Log::info('notification.user.validated', [
            'user_uuid'      => $userUuid,
            'correlation_id' => $correlationId,
        ]);

        // 3. Fetch preferences and filter channels
        $preferences = $this->userClient->fetchPreferences($token, $userUuid);
        $allowedChannels = $preferences['channels'] ?? [];
        $requestedChannels = $payload['channels'] ?? [];

        $channels = array_values(array_intersect($requestedChannels, $allowedChannels));

        if (empty($channels)) {
            throw new ExternalServiceException(
                'No channels available after applying user preferences.',
                422,
                [],
                'NO_CHANNELS_AVAILABLE',
                $correlationId,
            );
        }

        // 4. Rate limiting — 5 per minute per user
        $rateLimitKey = "notifications:user:{$userUuid}";
        $currentCount = (int) Cache::get($rateLimitKey, 0);

        if ($currentCount >= 5) {
            throw new ExternalServiceException(
                'Rate limit exceeded. Maximum 5 notifications per minute.',
                429,
                [],
                'RATE_LIMIT_EXCEEDED',
                $correlationId,
            );
        }

        Cache::put($rateLimitKey, $currentCount + 1, now()->addMinute());

        // 5. Render template
        $rendered = $this->templateClient->render(
            $token,
            $payload['template_key'],
            $payload['variables'] ?? [],
        );

        Log::info('notification.template.rendered', [
            'user_uuid'         => $userUuid,
            'template_key'      => $payload['template_key'],
            'correlation_id'    => $correlationId,
            'acting_admin_uuid' => $adminUuid,
        ]);

        // 6. Create notification
        $notification = Notification::create([
            'user_uuid'       => $userUuid,
            'template_key'    => $payload['template_key'],
            'channels'        => $channels,
            'variables'       => $payload['variables'] ?? [],
            'status'          => 'queued',
            'idempotency_key' => $payload['idempotency_key'] ?? null,
        ]);

        // 7. Store idempotency key
        if (! empty($payload['idempotency_key'])) {
            IdempotencyKey::create([
                'user_uuid'         => $userUuid,
                'idempotency_key'   => $payload['idempotency_key'],
                'request_hash'      => hash('sha256', json_encode($payload)),
                'notification_uuid' => $notification->uuid,
            ]);
        }

        // 8. Create notification attempts for each channel
        foreach ($channels as $channel) {
            NotificationAttempt::create([
                'notification_uuid' => $notification->uuid,
                'channel'           => $channel,
                'status'            => 'pending',
            ]);
        }

        // 9. Build delivery payload per channel
        $deliveryItems = $this->buildDeliveryItems($channels, $user, $rendered, $payload);

        $deliveryPayload = [
            'notification_uuid' => $notification->uuid,
            'user_uuid'         => $userUuid,
            'deliveries'        => $deliveryItems,
        ];

        // 10. Call Messaging Service
        try {
            $messagingResponse = $this->messagingClient->createDeliveries($token, $deliveryPayload);

            $notification->update([
                'status'              => 'queued',
                'delivery_references' => $messagingResponse['deliveries'] ?? [],
            ]);

            NotificationAttempt::where('notification_uuid', $notification->uuid)
                ->update(['status' => 'pending']);

            Log::info('notification.messaging.dispatched', [
                'notification_uuid' => $notification->uuid,
                'user_uuid'         => $userUuid,
                'channels'          => $channels,
                'correlation_id'    => $correlationId,
                'acting_admin_uuid' => $adminUuid,
            ]);
        } catch (ExternalServiceException $e) {
            $notification->update([
                'status'     => 'failed',
                'last_error' => $e->getMessage(),
            ]);

            NotificationAttempt::where('notification_uuid', $notification->uuid)
                ->update([
                    'status'        => 'failed',
                    'error_message' => $e->getMessage(),
                ]);

            Log::error('notification.failed', [
                'notification_uuid' => $notification->uuid,
                'user_uuid'         => $userUuid,
                'error'             => $e->getMessage(),
                'correlation_id'    => $correlationId,
                'acting_admin_uuid' => $adminUuid,
            ]);
        }

        // 11. Return notification with attempts
        $notification->refresh();

        return $notification->load('attempts')->toArray();
    }

    /**
     * Build per-channel delivery items using user data and rendered template.
     */
    private function buildDeliveryItems(array $channels, array $user, array $rendered, array $payload): array
    {
        $items = [];

        foreach ($channels as $channel) {
            $recipient = match ($channel) {
                'email'    => $user['email'] ?? null,
                'whatsapp' => $user['phone_e164'] ?? null,
                'push'     => $user['device_token'] ?? null,
                default    => null,
            };

            $items[] = [
                'channel'   => $channel,
                'recipient' => $recipient,
                'subject'   => $rendered['subject'] ?? '',
                'content'   => $rendered['content'] ?? '',
                'payload'   => [
                    'template_key' => $payload['template_key'],
                    'variables'    => $payload['variables'] ?? [],
                ],
            ];
        }

        return $items;
    }
}
