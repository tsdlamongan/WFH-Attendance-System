<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckInRequest;
use App\Http\Requests\CheckOutRequest;
use App\Http\Resources\AttendanceResource;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class AttendanceController extends Controller
{
    public function __construct(
        private AttendanceService $attendanceService
    ) {}

    public function checkIn(CheckInRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $tasks = $request->validated()['tasks'];
            $overtime = $request->validated()['overtime'] ?? null;

            $attendance = $this->attendanceService->checkIn($user, $tasks, $request, $overtime);

            return response()->json([
                'success' => true,
                'data' => new AttendanceResource($attendance->load('tasks.project')),
                'message' => 'Checked in successfully',
            ], 201);
        } catch (\Exception $e) {
            Log::error('Check-in failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function checkOut(CheckOutRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $attendanceId = $request->validated()['attendance_id'];
            $tasks = $request->validated()['tasks'];

            $attendance = $this->attendanceService->checkOut($user, $attendanceId, $tasks, $request);

            return response()->json([
                'success' => true,
                'data' => new AttendanceResource($attendance->load('tasks.project')),
                'message' => "Checked out successfully. Total hours: {$attendance->total_hours}",
            ], 200);
        } catch (\Exception $e) {
            Log::error('Check-out failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function today(): JsonResponse
    {
        try {
            $user = auth()->user();
            $status = $this->attendanceService->getTodayStatus($user);

            return response()->json([
                'success' => true,
                'data' => $status,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get today status failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to get today status',
            ], 500);
        }
    }
}
