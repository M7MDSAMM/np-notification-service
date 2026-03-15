<?php

namespace App\Clients\Contracts;

interface MessagingServiceClientInterface
{
    public function createDeliveries(string $token, array $payload): array;
}
