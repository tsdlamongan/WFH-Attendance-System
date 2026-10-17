<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\User;
use App\Repositories\ActivityLogRepository;
use Illuminate\Http\Request;

class ActivityLogService
{
    public function __construct(
        private ActivityLogRepository $activityLogRepository
    ) {}

    /**
     * Log user activity with IP and User Agent from request.
     */
    public function logActivity(
        User $user,
        ActivityType $action,
        string $description,
        ?Request $request = null
    ): void {
        $ipAddress = $request?->ip();
        $userAgent = $request?->userAgent();

        $this->activityLogRepository->create(
            $user,
            $action,
            $description,
            $ipAddress,
            $userAgent
        );
    }

    /**
     * Log activity without request (for console commands).
     */
    public function logActivitySimple(
        User $user,
        ActivityType $action,
        string $description
    ): void {
        $this->activityLogRepository->create(
            $user,
            $action,
            $description,
            null,
            'System'
        );
    }
}
