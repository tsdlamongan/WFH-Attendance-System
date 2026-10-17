<?php

namespace App\Http\Middleware;

use App\Enums\ActivityType;
use App\Services\ActivityLogService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogUserActivity
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Overtime actions are logged by OvertimeService with richer descriptions.
        if (str_contains($request->path(), 'toggle-disabled') || str_contains($request->path(), 'overtimes')) {
            return $response;
        }

        // Only log if user is authenticated
        if (auth()->check() && ($request->isMethod('post') || $request->isMethod('put') || $request->isMethod('patch') || $request->isMethod('delete'))) {
            $action = $this->getActionType($request);
            $description = $this->getDescription($request, $action);

            // Log activity after response is sent
            if ($response->isSuccessful()) {
                app(ActivityLogService::class)->logActivity(
                    auth()->user(),
                    $action,
                    $description,
                    $request
                );
            }
        }

        return $response;
    }

    /**
     * Get activity type from request.
     */
    private function getActionType(Request $request): ActivityType
    {
        $path = $request->path();
        $method = $request->method();

        if (str_contains($path, 'check-in')) {
            return ActivityType::CHECK_IN;
        }
        if (str_contains($path, 'check-out')) {
            return ActivityType::CHECK_OUT;
        }
        if (str_contains($path, 'tasks')) {
            return $method === 'POST' ? ActivityType::TASK_CREATED : ActivityType::TASK_UPDATED;
        }
        if (str_contains($path, 'users')) {
            return match ($method) {
                'POST' => ActivityType::USER_CREATED,
                'PUT', 'PATCH' => ActivityType::USER_UPDATED,
                'DELETE' => ActivityType::USER_DELETED,
            };
        }
        if (str_contains($path, 'attendances')) {
            return match ($method) {
                'POST' => ActivityType::ATTENDANCE_CREATED,
                'PUT', 'PATCH' => ActivityType::ATTENDANCE_EDITED,
                'DELETE' => ActivityType::ATTENDANCE_DELETED,
            };
        }
        if (str_contains($path, 'leaves')) {
            if (str_contains($path, 'approve')) {
                return ActivityType::LEAVE_APPROVED;
            }
            if (str_contains($path, 'reject')) {
                return ActivityType::LEAVE_REJECTED;
            }

            return ActivityType::LEAVE_REQUESTED;
        }
        if (str_contains($path, 'holidays')) {
            return match ($method) {
                'POST' => ActivityType::HOLIDAY_CREATED,
                'PUT', 'PATCH' => ActivityType::HOLIDAY_UPDATED,
                'DELETE' => ActivityType::HOLIDAY_DELETED,
            };
        }
        if (str_contains($path, 'projects')) {
            return match ($method) {
                'POST' => ActivityType::PROJECT_CREATED,
                'PUT', 'PATCH' => ActivityType::PROJECT_UPDATED,
                'DELETE' => ActivityType::PROJECT_DELETED,
            };
        }

        return ActivityType::TASK_UPDATED; // Default
    }

    /**
     * Get description for activity log.
     */
    private function getDescription(Request $request, ActivityType $action): string
    {
        $method = $request->method();
        $path = $request->path();

        return match ($action) {
            ActivityType::CHECK_IN => 'User checked in',
            ActivityType::CHECK_OUT => 'User checked out',
            ActivityType::TASK_CREATED => 'Task created',
            ActivityType::TASK_UPDATED => 'Task updated',
            ActivityType::ATTENDANCE_CREATED => 'Attendance created',
            ActivityType::ATTENDANCE_EDITED => 'Attendance edited',
            ActivityType::ATTENDANCE_DELETED => 'Attendance deleted',
            ActivityType::USER_CREATED => 'User created',
            ActivityType::USER_UPDATED => 'User updated',
            ActivityType::USER_DELETED => 'User deleted',
            ActivityType::LEAVE_REQUESTED => 'Leave requested',
            ActivityType::LEAVE_APPROVED => 'Leave approved',
            ActivityType::LEAVE_REJECTED => 'Leave rejected',
            ActivityType::HOLIDAY_CREATED => 'Holiday created',
            ActivityType::HOLIDAY_UPDATED => 'Holiday updated',
            ActivityType::HOLIDAY_DELETED => 'Holiday deleted',
            ActivityType::PROJECT_CREATED => 'Project created',
            ActivityType::PROJECT_UPDATED => 'Project updated',
            ActivityType::PROJECT_DELETED => 'Project deleted',
            default => 'Activity logged',
        };
    }
}
