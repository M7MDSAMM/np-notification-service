<?php

namespace App\Services\Contracts;

use App\Models\Notification;

interface NotificationServiceInterface
{
    public function create(array $payload): Notification;

    public function findByUuid(string $uuid): Notification;

    public function retry(string $uuid): array;
}
