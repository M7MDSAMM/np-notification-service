<?php

namespace App\Providers;

use App\Domain\Auth\JwtTokenServiceInterface;
use App\Infrastructure\Auth\Rs256JwtTokenService;
use App\Services\Contracts\NotificationServiceInterface;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
