<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ManagerReportController extends Controller
{
    public function __construct(
        private ReportService $reportService
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        try {
            $date = $request->get('date', Carbon::today()->format('Y-m-d'));
            $date = Carbon::parse($date);
            $teamId = auth()->user()->team_id;

            $dashboard = $this->reportService->getManagerDashboard($date, $teamId);

            return response()->json([
                'success' => true,
                'data' => $dashboard,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get manager dashboard failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to get dashboard',
            ], 500);
        }
    }

    public function employeeReport(Request $request, int $userId): JsonResponse
    {
        try {
            $manager = auth()->user();
            $employee = User::findOrFail($userId);

            if ($employee->team_id !== $manager->team_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view this employee report',
                ], 403);
            }

            $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
            $endDate = $request->get('end_date', Carbon::now()->format('Y-m-d'));

            $startDate = Carbon::parse($startDate);
            $endDate = Carbon::parse($endDate);

            $report = $this->reportService->getEmployeeReportForManager($employee, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => $report,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get employee report failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to get employee report',
            ], 500);
        }
    }

    public function dailyAttendanceReport(Request $request): JsonResponse
    {
        try {
            $date = $request->get('date', Carbon::today()->format('Y-m-d'));
            $date = Carbon::parse($date);

            $teamId = auth()->user()->team_id;

            $report = $this->reportService->getDailyAttendanceReport($date, $teamId);

            return response()->json([
                'success' => true,
                'data' => $report,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get daily attendance report failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to get daily attendance report',
            ], 500);
        }
    }

    public function monthlyAttendanceReport(Request $request): JsonResponse
    {
        try {
            $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
            $endDate = $request->get('end_date', Carbon::now()->endOfMonth()->format('Y-m-d'));

            $startDate = Carbon::parse($startDate);
            $endDate = Carbon::parse($endDate);

            $teamId = auth()->user()->team_id;

            $report = $this->reportService->getMonthlyAttendanceReport($startDate, $endDate, $teamId);

            return response()->json([
                'success' => true,
                'data' => $report,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get monthly attendance report failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to get monthly attendance report',
            ], 500);
        }
    }

    public function checkInTimeReport(Request $request): JsonResponse
    {
        try {
            $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
            $endDate = $request->get('end_date', Carbon::now()->endOfMonth()->format('Y-m-d'));

            $startDate = Carbon::parse($startDate);
            $endDate = Carbon::parse($endDate);

            $teamId = auth()->user()->team_id;

            $report = $this->reportService->getCheckInTimeReport($startDate, $endDate, $teamId);

            return response()->json([
                'success' => true,
                'data' => $report,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get check-in time report failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to get check-in time report',
            ], 500);
        }
    }
}
