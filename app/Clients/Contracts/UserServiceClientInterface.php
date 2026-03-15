<?php

namespace App\Clients\Contracts;

interface UserServiceClientInterface
{
    public function fetchUser(string $token, string $uuid): array;

    public function fetchPreferences(string $token, string $uuid): array;
}
