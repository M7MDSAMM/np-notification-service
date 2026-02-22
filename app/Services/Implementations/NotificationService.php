<?php

namespace App\Services\Implementations;

use App\Models\Notification;
use App\Services\Contracts\NotificationServiceInterface;
use Illuminate\Support\Facades\Log;

class NotificationService implements NotificationServiceInterface
{
    public function create(array $payload): Notification
    {
        $notification = Notification::create($payload);

        Log::info('notification.created', [
            'notification_uuid' => $notification->uuid,
            'user_uuid'         => $notification->user_uuid,
            'template_key'      => $notification->template_key,
            'correlation_id'    => request()->header('X-Correlation-Id'),
            'acting_admin_uuid' => request()->attributes->get('auth_admin_uuid'),
        ]);

        return $notification;
    }

    public function findByUuid(string $uuid): Notification
    {
        $notification = Notification::where('uuid', $uuid)->firstOrFail();

        Log::info('notification.viewed', [
            'notification_uuid' => $notification->uuid,
            'correlation_id'    => request()->header('X-Correlation-Id'),
            'acting_admin_uuid' => request()->attributes->get('auth_admin_uuid'),
        ]);

        return $notification;
    }

    public function retry(string $uuid): array
    {
        $notification = Notification::where('uuid', $uuid)->firstOrFail();

        Log::info('notification.retry_requested', [
            'notification_uuid' => $notification->uuid,
            'correlation_id'    => request()->header('X-Correlation-Id'),
            'acting_admin_uuid' => request()->attributes->get('auth_admin_uuid'),
        ]);

        return [
            'notification_uuid' => $notification->uuid,
            'status'            => 'retry_accepted',
        ];
    }
}
