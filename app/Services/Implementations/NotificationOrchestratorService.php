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
        $userUuid = $payload['user_uuid'];

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

        // 6. Create notification
        $notification = Notification::create([
            'user_uuid'       => $userUuid,
            'template_key'    => $payload['template_key'],
            'channels'        => $channels,
            'variables'       => $payload['variables'] ?? [],
            'status'          => 'queued',
            'idempotency_key' => $payload['idempotency_key'] ?? null,
        ]);

        Log::info('notification.created', [
            'notification_uuid' => $notification->uuid,
            'user_uuid'         => $userUuid,
            'template_key'      => $notification->template_key,
            'correlation_id'    => $correlationId,
            'acting_admin_uuid' => request()->attributes->get('auth_admin_uuid'),
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

        // 8. Create attempts for each channel
        $attempts = [];
        foreach ($channels as $channel) {
            $attempts[] = NotificationAttempt::create([
                'notification_uuid' => $notification->uuid,
                'channel'           => $channel,
                'status'            => 'pending',
            ]);
        }

        // 9. Dispatch to Messaging Service
        try {
            $messagingPayload = [
                'notification_uuid' => $notification->uuid,
                'user_uuid'         => $userUuid,
                'channels'          => $channels,
                'subject'           => $rendered['subject'] ?? '',
                'content'           => $rendered['content'] ?? '',
                'template_key'      => $payload['template_key'],
            ];

            $this->messagingClient->send($token, $messagingPayload);

            // Update notification and attempts to sent
            $notification->update(['status' => 'sent']);

            NotificationAttempt::where('notification_uuid', $notification->uuid)
                ->update(['status' => 'sent']);
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

            Log::warning('notification.send_failed', [
                'notification_uuid' => $notification->uuid,
                'error'             => $e->getMessage(),
                'correlation_id'    => $correlationId,
            ]);
        }

        // 10. Return notification with attempts
        $notification->refresh();

        return $notification->load('attempts')->toArray();
    }
}
