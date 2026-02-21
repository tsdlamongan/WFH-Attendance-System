<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogUserActivity
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only log if user is authenticated
        if (auth()->check() && ($request->isMethod('post') || $request->isMethod('put') || $request->isMethod('delete'))) {
            $action = $this->getActionType($request);
            $description = $this->getDescription($request, $action);

            // Log activity after response is sent
            if ($response->isSuccessful()) {
                app(\App\Services\ActivityLogService::class)->logActivity(
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
    private function getActionType(Request $request): \App\Enums\ActivityType
    {
        $path = $request->path();
        $method = $request->method();

        if (str_contains($path, 'check-in')) {
            return \App\Enums\ActivityType::CHECK_IN;
        }
        if (str_contains($path, 'check-out')) {
            return \App\Enums\ActivityType::CHECK_OUT;
        }
        if (str_contains($path, 'tasks')) {
            return $method === 'POST' ? \App\Enums\ActivityType::TASK_CREATED : \App\Enums\ActivityType::TASK_UPDATED;
        }
        if (str_contains($path, 'users')) {
            return match ($method) {
                'POST' => \App\Enums\ActivityType::USER_CREATED,
                'PUT', 'PATCH' => \App\Enums\ActivityType::USER_UPDATED,
                'DELETE' => \App\Enums\ActivityType::USER_DELETED,
            };
        }
        if (str_contains($path, 'attendances')) {
            return match ($method) {
                'POST' => \App\Enums\ActivityType::ATTENDANCE_CREATED,
                'PUT', 'PATCH' => \App\Enums\ActivityType::ATTENDANCE_EDITED,
                'DELETE' => \App\Enums\ActivityType::ATTENDANCE_DELETED,
            };
        }
        if (str_contains($path, 'leaves')) {
            if (str_contains($path, 'approve')) {
                return \App\Enums\ActivityType::LEAVE_APPROVED;
            }
            if (str_contains($path, 'reject')) {
                return \App\Enums\ActivityType::LEAVE_REJECTED;
            }
            return \App\Enums\ActivityType::LEAVE_REQUESTED;
        }
        if (str_contains($path, 'holidays')) {
            return match ($method) {
                'POST' => \App\Enums\ActivityType::HOLIDAY_CREATED,
                'PUT', 'PATCH' => \App\Enums\ActivityType::HOLIDAY_UPDATED,
                'DELETE' => \App\Enums\ActivityType::HOLIDAY_DELETED,
            };
        }

        return \App\Enums\ActivityType::TASK_UPDATED; // Default
    }

    /**
     * Get description for activity log.
     */
    private function getDescription(Request $request, \App\Enums\ActivityType $action): string
    {
        $method = $request->method();
        $path = $request->path();

        return match ($action) {
            \App\Enums\ActivityType::CHECK_IN => 'User checked in',
            \App\Enums\ActivityType::CHECK_OUT => 'User checked out',
            \App\Enums\ActivityType::TASK_CREATED => 'Task created',
            \App\Enums\ActivityType::TASK_UPDATED => 'Task updated',
            \App\Enums\ActivityType::ATTENDANCE_CREATED => 'Attendance created',
            \App\Enums\ActivityType::ATTENDANCE_EDITED => 'Attendance edited',
            \App\Enums\ActivityType::ATTENDANCE_DELETED => 'Attendance deleted',
            \App\Enums\ActivityType::USER_CREATED => 'User created',
            \App\Enums\ActivityType::USER_UPDATED => 'User updated',
            \App\Enums\ActivityType::USER_DELETED => 'User deleted',
            \App\Enums\ActivityType::LEAVE_REQUESTED => 'Leave requested',
            \App\Enums\ActivityType::LEAVE_APPROVED => 'Leave approved',
            \App\Enums\ActivityType::LEAVE_REJECTED => 'Leave rejected',
            \App\Enums\ActivityType::HOLIDAY_CREATED => 'Holiday created',
            \App\Enums\ActivityType::HOLIDAY_UPDATED => 'Holiday updated',
            \App\Enums\ActivityType::HOLIDAY_DELETED => 'Holiday deleted',
            default => 'Activity logged',
        };
    }
}
