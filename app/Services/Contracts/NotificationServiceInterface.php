<?php

namespace App\Services\Contracts;

use App\Models\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface NotificationServiceInterface
{
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function create(array $payload): Notification;

    public function findByUuid(string $uuid): Notification;

    public function retry(string $uuid): array;
}
