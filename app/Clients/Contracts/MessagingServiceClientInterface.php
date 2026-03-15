<?php

namespace App\Clients\Contracts;

interface MessagingServiceClientInterface
{
    public function send(string $token, array $payload): array;
}
