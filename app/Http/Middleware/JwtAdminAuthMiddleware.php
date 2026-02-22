<?php

namespace App\Http\Middleware;

use App\Domain\Auth\InvalidTokenException;
use App\Domain\Auth\JwtTokenServiceInterface;
use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JwtAdminAuthMiddleware
{
    public function __construct(
        private readonly JwtTokenServiceInterface $tokenService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return ApiResponse::unauthorized('Missing or malformed Authorization header.', 'AUTH_INVALID');
        }

        $token = substr($header, 7);

        try {
            $claims = $this->tokenService->validateToken($token);
        } catch (InvalidTokenException $e) {
            $code = str_contains($e->getMessage(), 'expired') ? 'TOKEN_EXPIRED' : 'AUTH_INVALID';

            return ApiResponse::unauthorized($e->getMessage(), $code);
        }

        if (($claims['typ'] ?? '') !== 'admin') {
            return ApiResponse::unauthorized('Token type is not admin.', 'AUTH_INVALID');
        }

        $request->attributes->set('auth_admin_uuid', $claims['sub']);
        $request->attributes->set('auth_admin_role', $claims['role'] ?? null);

        return $next($request);
    }
}
