<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\ChangePasswordController;
use App\Http\Controllers\Api\EmployeeReportController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\ImpersonateController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\LeaveQuotaController;
use App\Http\Controllers\Api\ManagerAttendanceController;
use App\Http\Controllers\Api\ManagerLeaveController;
use App\Http\Controllers\Api\ManagerReportController;
use App\Http\Controllers\Api\ManagerTaskController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TeamManagementController;
use App\Http\Controllers\Api\TeamSettingsController;
use App\Http\Controllers\Api\UserManagementController;
use App\Http\Controllers\Api\WhatsAppController;
use App\Http\Middleware\EnsureTeamAccess;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Version 1
|--------------------------------------------------------------------------
*/

// Authentication routes (no auth required)
Route::prefix('v1/auth')->group(function () {
    Route::post('/login', [LoginController::class, 'login']);
    Route::post('/register', [RegisterController::class, 'register'])->middleware('registration.enabled');
    Route::get('/registration-status', function () {
        return response()->json([
            'enabled' => config('app.registration.enabled', true),
            'message' => config('app.registration.enabled', true)
                ? 'Registration is enabled'
                : 'Registration is disabled',
        ]);
    });
});

// Protected routes
Route::prefix('v1')->middleware(['auth:sanctum', 'log.user.activity', EnsureTeamAccess::class])->group(function () {
    // Authentication
    Route::post('/auth/logout', [LogoutController::class, 'logout']);

    // Change Password (available for all authenticated users)
    Route::post('/change-password', [ChangePasswordController::class, 'changePassword']);

    // Team Settings (Manager only)
    Route::middleware('role:manager')->group(function () {
        Route::get('/team/settings', [TeamSettingsController::class, 'show']);
        Route::put('/team/settings', [TeamSettingsController::class, 'update']);

        // WhatsApp Gateway Settings
        Route::prefix('whatsapp')->group(function () {
            Route::post('/create-link', [WhatsAppController::class, 'createLink']);
            Route::post('/link-existing', [WhatsAppController::class, 'linkExisting']);
            Route::get('/account-info', [WhatsAppController::class, 'accountInfo']);
            Route::get('/check-connection', [WhatsAppController::class, 'checkConnection']);
            Route::post('/confirm-link', [WhatsAppController::class, 'confirmLink']);
            Route::post('/relink', [WhatsAppController::class, 'relink']);
            Route::post('/disconnect', [WhatsAppController::class, 'disconnect']);
            Route::get('/settings', [WhatsAppController::class, 'getSettings']);
            Route::put('/settings', [WhatsAppController::class, 'updateSettings']);
            Route::post('/test-send', [WhatsAppController::class, 'testSend']);
            Route::get('/preview-recap', [WhatsAppController::class, 'previewRecap']);
            Route::post('/send-recap', [WhatsAppController::class, 'sendRecap']);
        });
    });

    // Attendance routes (Employee)
    Route::prefix('attendance')->middleware('role:employee')->group(function () {
        Route::post('/check-in', [AttendanceController::class, 'checkIn']);
        Route::post('/check-out', [AttendanceController::class, 'checkOut']);
        Route::get('/today', [AttendanceController::class, 'today']);
    });

    // Task routes (Employee)
    Route::prefix('tasks')->middleware('role:employee')->group(function () {
        Route::post('/add', [TaskController::class, 'addTasks']);
        Route::get('/incomplete', [TaskController::class, 'incomplete']);
        Route::get('/incomplete-last-session', [TaskController::class, 'incompleteFromLastSession']);
    });

    // Employee Report routes
    Route::prefix('reports')->middleware('role:employee')->group(function () {
        Route::get('/my-report', [EmployeeReportController::class, 'myReport']);
    });

    // Leave routes (Employee)
    Route::prefix('leaves')->middleware('role:employee')->group(function () {
        Route::post('/', [LeaveController::class, 'store']);
        Route::get('/my-requests', [LeaveController::class, 'myRequests']);
        Route::get('/summary', [LeaveController::class, 'summary']);
    });

    // Holidays (Both roles can view)
    Route::get('/holidays', [HolidayController::class, 'index']);

    // Manager routes
    Route::middleware('role:manager')->group(function () {
        // Manager Dashboard
        Route::get('/manager/dashboard', [ManagerReportController::class, 'dashboard']);
        Route::get('/manager/reports/employee/{userId}', [ManagerReportController::class, 'employeeReport']);
        Route::get('/manager/reports/daily-attendance', [ManagerReportController::class, 'dailyAttendanceReport']);
        Route::get('/manager/reports/monthly-attendance', [ManagerReportController::class, 'monthlyAttendanceReport']);
        Route::get('/manager/reports/check-in-time', [ManagerReportController::class, 'checkInTimeReport']);

        // User Management
        Route::prefix('manager/users')->group(function () {
            Route::get('/', [UserManagementController::class, 'index']);
            Route::get('/search', [UserManagementController::class, 'search']);
            Route::post('/', [UserManagementController::class, 'store']);
            Route::put('/{id}', [UserManagementController::class, 'update']);
            Route::delete('/{id}', [UserManagementController::class, 'destroy']);
        });

        // Attendance Management
        Route::prefix('manager/attendances')->group(function () {
            Route::get('/', [ManagerAttendanceController::class, 'index']);
            Route::post('/', [ManagerAttendanceController::class, 'store']);
            Route::put('/{id}', [ManagerAttendanceController::class, 'update']);
            Route::delete('/{id}', [ManagerAttendanceController::class, 'destroy']);
        });

        // Task Management (Manager)
        Route::prefix('manager/tasks')->group(function () {
            Route::put('/{id}', [ManagerTaskController::class, 'update']);
        });

        // Holiday Management
        Route::prefix('manager/holidays')->group(function () {
            Route::post('/', [HolidayController::class, 'store']);
            Route::put('/{id}', [HolidayController::class, 'update']);
            Route::delete('/{id}', [HolidayController::class, 'destroy']);
        });

        // Leave Management
        Route::prefix('manager/leaves')->group(function () {
            Route::get('/', [ManagerLeaveController::class, 'index']);
            Route::put('/{id}', [ManagerLeaveController::class, 'update']);
            Route::put('/{id}/approve', [ManagerLeaveController::class, 'approve']);
            Route::put('/{id}/reject', [ManagerLeaveController::class, 'reject']);
        });

        // Leave Quota Management
        Route::prefix('manager/leave-quotas')->group(function () {
            Route::get('/', [LeaveQuotaController::class, 'index']);
            Route::put('/{userId}', [LeaveQuotaController::class, 'update']);
            Route::post('/bulk', [LeaveQuotaController::class, 'bulkUpdate']);
        });

        // Activity Logs
        Route::get('/manager/activity-logs', [ActivityLogController::class, 'index']);
    });

    // Super Admin Routes (Only accessible by super admin)
    Route::middleware('super.admin')->prefix('super-admin')->group(function () {
        // Team Management
        Route::prefix('teams')->group(function () {
            Route::get('/', [TeamManagementController::class, 'index']);
            Route::post('/', [TeamManagementController::class, 'store']);
            Route::get('/{id}', [TeamManagementController::class, 'show']);
            Route::put('/{id}', [TeamManagementController::class, 'update']);
            Route::delete('/{id}', [TeamManagementController::class, 'destroy']);
        });

        // Impersonation - start impersonate (requires super admin)
        Route::post('/impersonate/{userId}', [ImpersonateController::class, 'impersonate']);
    });

    // Stop impersonate - accessible by impersonated user (not super admin)
    Route::post('/super-admin/stop-impersonate', [ImpersonateController::class, 'stopImpersonate']);
});
