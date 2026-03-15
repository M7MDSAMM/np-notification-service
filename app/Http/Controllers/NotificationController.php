<?php

namespace App\Http\Controllers;

use App\Exceptions\ExternalServiceException;
use App\Http\Requests\NotificationCreateRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Contracts\NotificationOrchestratorInterface;
use App\Services\Contracts\NotificationServiceInterface;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationOrchestratorInterface $orchestrator,
        private readonly NotificationServiceInterface $notificationService,
    ) {}

    public function store(NotificationCreateRequest $request): JsonResponse
    {
        $result = $this->orchestrator->createNotification($request->validated());

        return ApiResponse::created($result, 'Notification created.');
    }

    public function show(string $uuid): JsonResponse
    {
        $notification = $this->notificationService->findByUuid($uuid);

        return ApiResponse::success($notification, 'Notification retrieved.');
    }

    public function retry(string $uuid): JsonResponse
    {
        $result = $this->notificationService->retry($uuid);

        return ApiResponse::success($result, 'Notification retry accepted.');
    }
}
