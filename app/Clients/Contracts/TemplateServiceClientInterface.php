<?php

namespace App\Clients\Contracts;

interface TemplateServiceClientInterface
{
    public function render(string $token, string $templateKey, array $variables): array;
}
