<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = '',
        array $meta = [],
        int $status = 200,
    ): JsonResponse {
        return response()->json([
            'success'        => true,
            'message'        => $message,
            'data'           => $data,
            'meta'           => $meta ?: (object) [],
            'correlation_id' => request()->headers->get('X-Correlation-Id', ''),
        ], $status)->header('X-Correlation-Id', request()->headers->get('X-Correlation-Id', ''));
    }

    public static function created(mixed $data = null, string $message = 'Resource created.'): JsonResponse
    {
        return self::success($data, $message, [], 201);
    }

    public static function list(
        array $data,
        string $message = '',
        ?array $pagination = null,
    ): JsonResponse {
        $meta = $pagination ? ['pagination' => $pagination] : (object) [];

        return response()->json([
            'success'        => true,
            'message'        => $message,
            'data'           => $data,
            'meta'           => $meta,
            'correlation_id' => request()->headers->get('X-Correlation-Id', ''),
        ])->header('X-Correlation-Id', request()->headers->get('X-Correlation-Id', ''));
    }

    public static function error(
        string $message,
        string $errorCode,
        int $status = 400,
        array $errors = [],
    ): JsonResponse {
        return response()->json([
            'success'        => false,
            'message'        => $message,
            'errors'         => $errors ?: (object) [],
            'error_code'     => $errorCode,
            'correlation_id' => request()->headers->get('X-Correlation-Id', ''),
            'meta'           => (object) [],
        ], $status)
            ->header('X-Correlation-Id', request()->headers->get('X-Correlation-Id', ''));
    }

    public static function unauthorized(string $message = 'Unauthorized.', string $errorCode = 'AUTH_INVALID'): JsonResponse
    {
        return self::error($message, $errorCode, 401);
    }

    public static function forbidden(string $message = 'Forbidden.', string $errorCode = 'FORBIDDEN'): JsonResponse
    {
        return self::error($message, $errorCode, 403);
    }

    public static function notFound(string $message = 'Resource not found.', string $errorCode = 'NOT_FOUND'): JsonResponse
    {
        return self::error($message, $errorCode, 404);
    }

    public static function validation(array $errors, string $message = 'Validation failed.'): JsonResponse
    {
        return self::error($message, 'VALIDATION_ERROR', 422, $errors);
    }

    public static function conflict(string $message = 'Conflict.', string $errorCode = 'CONFLICT', array $errors = []): JsonResponse
    {
        return self::error($message, $errorCode, 409, $errors);
    }

    public static function serverError(string $message = 'Internal server error.'): JsonResponse
    {
        return self::error($message, 'SERVER_ERROR', 500);
    }
}
