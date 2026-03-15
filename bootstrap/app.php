<?php

use App\Exceptions\ExternalServiceException;
use App\Http\Middleware\CorrelationIdMiddleware;
use App\Http\Middleware\JwtAdminAuthMiddleware;
use App\Http\Middleware\RequestTimingMiddleware;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: null,
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(CorrelationIdMiddleware::class);
        $middleware->append(RequestTimingMiddleware::class);
        $middleware->alias([
            'jwt.admin' => JwtAdminAuthMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::validation($e->errors(), $e->getMessage());
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::unauthorized($e->getMessage() ?: 'Unauthorized.');
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::forbidden($e->getMessage() ?: 'Forbidden.');
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::notFound('Resource not found.', 'NOT_FOUND');
            }
        });

        $exceptions->render(function (ExternalServiceException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error(
                    $e->getMessage(),
                    $e->errorCode ?? 'EXTERNAL_SERVICE_ERROR',
                    $e->statusCode,
                    $e->context,
                );
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::notFound('Resource not found.', 'NOT_FOUND');
            }
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $status  = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $message = $e->getMessage() ?: 'An error occurred.';

            if ($status >= 500) {
                return ApiResponse::serverError(app()->hasDebugModeEnabled() ? $message : 'Internal server error.');
            }

            return ApiResponse::error($message, 'ERROR', $status);
        });
    })->create();
