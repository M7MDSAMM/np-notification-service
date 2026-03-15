<?php

namespace App\Providers;

use App\Clients\Contracts\MessagingServiceClientInterface;
use App\Clients\Contracts\TemplateServiceClientInterface;
use App\Clients\Contracts\UserServiceClientInterface;
use App\Clients\MessagingServiceClient;
use App\Clients\TemplateServiceClient;
use App\Clients\UserServiceClient;
use App\Domain\Auth\JwtTokenServiceInterface;
use App\Infrastructure\Auth\Rs256JwtTokenService;
use App\Services\Contracts\NotificationOrchestratorInterface;
use App\Services\Contracts\NotificationServiceInterface;
use App\Services\Implementations\NotificationOrchestratorService;
use App\Services\Implementations\NotificationService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(JwtTokenServiceInterface::class, Rs256JwtTokenService::class);
        $this->app->bind(NotificationServiceInterface::class, NotificationService::class);

        // Service clients
        $this->app->bind(UserServiceClientInterface::class, UserServiceClient::class);
        $this->app->bind(TemplateServiceClientInterface::class, TemplateServiceClient::class);
        $this->app->bind(MessagingServiceClientInterface::class, MessagingServiceClient::class);

        // Orchestrator
        $this->app->bind(NotificationOrchestratorInterface::class, NotificationOrchestratorService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
