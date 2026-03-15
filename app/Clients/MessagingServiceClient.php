<?php

namespace App\Clients;

use App\Clients\Concerns\MakesHttpRequests;
use App\Clients\Contracts\MessagingServiceClientInterface;

class MessagingServiceClient implements MessagingServiceClientInterface
{
    use MakesHttpRequests;

    private string $baseUrl;
    private string $serviceName = 'messaging_service';

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.messaging_service.base_url'), '/').'/';
    }

    public function send(string $token, array $payload): array
    {
        $response = $this->timedRequest(
            fn () => $this->authenticatedRequest($token)->post('messages/send', $payload),
            'messages/send',
            'POST',
        );

        return $this->extractData($response, 'Failed to send message');
    }
}
