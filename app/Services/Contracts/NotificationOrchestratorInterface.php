<?php

namespace App\Services\Contracts;

interface NotificationOrchestratorInterface
{
    public function createNotification(array $payload): array;
}
