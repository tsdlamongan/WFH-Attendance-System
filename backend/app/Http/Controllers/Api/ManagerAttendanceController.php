<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\AttendanceEditRequest;
use App\Http\Requests\AttendanceStoreRequest;
use App\Http\Resources\AttendanceResource;
use App\Repositories\AttendanceRepository;
use App\Repositories\TaskRepository;
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
        private TaskRepository $taskRepository,
        private AttendanceService $attendanceService,
        private ActivityLogService $activityLogService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'start_date' => 'nullable|date_format:Y-m-d',
                'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
                'per_page' => 'nullable|integer|in:10,50,100',
                'user_id' => 'nullable|integer|exists:users,id',
            ]);
            
            $startDate = isset($validated['start_date']) ? Carbon::createFromFormat('Y-m-d', $validated['start_date'])->startOfDay() : null;
            $endDate = isset($validated['end_date']) ? Carbon::createFromFormat('Y-m-d', $validated['end_date'])->endOfDay() : null;
            $perPage = $validated['per_page'] ?? 10;
            $userId = $validated['user_id'] ?? null;
            $teamId = auth()->user()->team_id;

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

    public function store(AttendanceStoreRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $userId = (int) $validated['user_id'];
            $date = Carbon::parse($validated['date'])->toDateString();
            $checkIn = Carbon::parse($validated['check_in']);
            $checkOut = isset($validated['check_out']) && $validated['check_out']
                ? Carbon::parse($validated['check_out']) : null;
            $totalHours = $checkOut ? $this->attendanceService->calculateTotalHours($checkIn, $checkOut) : 0;

            $attendance = $this->attendanceRepository->create([
                'user_id' => $userId,
                'date' => $date,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'total_hours' => $totalHours,
            ]);

            $this->taskRepository->createMany($attendance, $validated['tasks']);

            return response()->json([
                'success' => true,
                'data' => new AttendanceResource($attendance->load(['user', 'tasks'])),
                'message' => 'Absensi berhasil ditambahkan',
            ], 201);
        } catch (\Exception $e) {
            Log::error('Create attendance failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal menambah absensi',
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
