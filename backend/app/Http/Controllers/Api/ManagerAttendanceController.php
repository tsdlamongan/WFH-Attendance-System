<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\AttendanceEditRequest;
use App\Http\Resources\AttendanceResource;
use App\Repositories\AttendanceRepository;
use App\Services\ActivityLogService;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ManagerAttendanceController extends Controller
{
    public function __construct(
        private AttendanceRepository $attendanceRepository,
        private AttendanceService $attendanceService,
        private ActivityLogService $activityLogService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $startDate = $request->get('start_date') ? Carbon::parse($request->get('start_date')) : null;
            $endDate = $request->get('end_date') ? Carbon::parse($request->get('end_date')) : null;
            $perPage = $request->get('per_page', 10);
            $userId = $request->get('user_id') ? (int)$request->get('user_id') : null;
            $teamId = auth()->user()->team_id;
            
            // Validate per_page parameter
            $perPage = in_array($perPage, [10, 50, 100, 1000]) ? $perPage : 10;

            $attendances = $this->attendanceRepository->getPaginatedInDateRange($startDate, $endDate, $perPage, $userId, $teamId);

            return response()->json([
                'success' => true,
                'data' => AttendanceResource::collection($attendances->items()),
                'pagination' => [
                    'current_page' => $attendances->currentPage(),
                    'last_page' => $attendances->lastPage(),
                    'per_page' => $attendances->perPage(),
                    'total' => $attendances->total(),
                    'from' => $attendances->firstItem(),
                    'to' => $attendances->lastItem(),
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get attendances failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to get attendances',
            ], 500);
        }
    }

    public function update(AttendanceEditRequest $request, int $id): JsonResponse
    {
        try {
            $attendance = $this->attendanceRepository->findById($id);

            if (!$attendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Attendance not found',
                ], 404);
            }

            // Ensure attendance belongs to user in the same team
            if ($attendance->user->team_id !== auth()->user()->team_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to edit this attendance',
                ], 403);
            }

            $validated = $request->validated();
            $date = Carbon::parse($validated['date'])->toDateString();
            $checkIn = Carbon::parse($validated['check_in']);
            $checkOut = $validated['check_out'] ? Carbon::parse($validated['check_out']) : null;

            $totalHours = $checkOut ? $this->attendanceService->calculateTotalHours($checkIn, $checkOut) : 0;

            $this->attendanceRepository->update($attendance, [
                'date' => $date,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'total_hours' => $totalHours,
            ]);

            $manager = auth()->user();
            $this->activityLogService->logActivity(
                $manager,
                ActivityType::ATTENDANCE_EDITED,
                "Edited attendance #{$id} for {$attendance->user->name}. Reason: {$validated['reason']}",
                $request
            );

            return response()->json([
                'success' => true,
                'data' => new AttendanceResource($attendance->fresh(['user', 'tasks'])),
                'message' => 'Attendance updated successfully',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Update attendance failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update attendance',
            ], 500);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $attendance = $this->attendanceRepository->findById($id);

            if (!$attendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Attendance not found',
                ], 404);
            }

            $reason = $request->input('reason');
            if (!$reason || strlen($reason) < 10) {
                return response()->json([
                    'success' => false,
                    'message' => 'Reason is required (minimum 10 characters)',
                ], 422);
            }

            $manager = auth()->user();
            $this->activityLogService->logActivity(
                $manager,
                ActivityType::ATTENDANCE_DELETED,
                "Deleted attendance #{$id} for {$attendance->user->name}. Reason: {$reason}",
                $request
            );

            $this->attendanceRepository->delete($attendance);

            return response()->json([
                'success' => true,
                'message' => 'Attendance deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Delete attendance failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete attendance',
            ], 500);
        }
    }
}
