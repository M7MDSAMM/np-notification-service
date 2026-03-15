<?php

namespace App\Clients;

use App\Clients\Concerns\MakesHttpRequests;
use App\Clients\Contracts\TemplateServiceClientInterface;

class TemplateServiceClient implements TemplateServiceClientInterface
{
    use MakesHttpRequests;

    private string $baseUrl;
    private string $serviceName = 'template_service';

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.template_service.base_url'), '/').'/';
    }

    public function render(string $token, string $templateKey, array $variables): array
    {
        $response = $this->timedRequest(
            fn () => $this->authenticatedRequest($token)->post('templates/render', [
                'template_key' => $templateKey,
                'variables'    => $variables,
            ]),
            'templates/render',
            'POST',
        );

        return $this->extractData($response, 'Failed to render template');
    }
}
