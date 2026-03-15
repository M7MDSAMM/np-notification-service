<?php

namespace App\Services\Implementations;

use App\Models\Notification;
use App\Services\Contracts\NotificationServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class NotificationService implements NotificationServiceInterface
{
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Notification::with('attempts')->latest();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['user_uuid'])) {
            $query->where('user_uuid', $filters['user_uuid']);
        }

        if (! empty($filters['template_key'])) {
            $query->where('template_key', $filters['template_key']);
        }

        Log::info('notification.listed', [
            'filters'        => $filters,
            'correlation_id' => request()->header('X-Correlation-Id'),
        ]);

        return $query->paginate($perPage);
    }

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
        $notification = Notification::with('attempts')->where('uuid', $uuid)->firstOrFail();

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
