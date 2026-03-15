<?php

namespace App\Clients;

use App\Clients\Concerns\MakesHttpRequests;
use App\Clients\Contracts\UserServiceClientInterface;

class UserServiceClient implements UserServiceClientInterface
{
    use MakesHttpRequests;

    private string $baseUrl;
    private string $serviceName = 'user_service';

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.user_service.base_url'), '/').'/';
    }

    public function fetchUser(string $token, string $uuid): array
    {
        $response = $this->timedRequest(
            fn () => $this->authenticatedRequest($token)->get("users/{$uuid}"),
            "users/{$uuid}",
            'GET',
        );

        return $this->extractData($response, 'Failed to fetch user');
    }

    public function fetchPreferences(string $token, string $uuid): array
    {
        $response = $this->timedRequest(
            fn () => $this->authenticatedRequest($token)->get("users/{$uuid}/preferences"),
            "users/{$uuid}/preferences",
            'GET',
        );

        return $this->extractData($response, 'Failed to fetch user preferences');
    }
}
