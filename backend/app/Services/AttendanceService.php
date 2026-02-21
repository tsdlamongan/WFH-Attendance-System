<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\TaskRepository;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AttendanceService
{
    public function __construct(
        private AttendanceRepository $attendanceRepository,
        private TaskRepository $taskRepository,
        private ActivityLogService $activityLogService
    ) {}

    /**
     * Check in employee with tasks.
     */
    public function checkIn(User $user, array $tasks, ?Request $request = null): Attendance
    {
        // Validate no active check-in
        $activeAttendance = $this->attendanceRepository->findActiveByUser($user);
        if ($activeAttendance) {
            throw new \Exception('You have already checked in. Please check out first.');
        }

        // Check if today is a holiday for this team
        $today = Carbon::today();
        $holiday = Holiday::where('team_id', $user->team_id)
            ->whereDate('date', $today)
            ->first();
        if ($holiday) {
            throw new \Exception("Today is a holiday: {$holiday->name}. You cannot check in.");
        }

        // Check if user is on approved leave
        $approvedLeave = Leave::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->first();

        if ($approvedLeave) {
            throw new \Exception('You are currently on approved leave. You cannot check in.');
        }

        DB::beginTransaction();
        try {
            $checkInTime = Carbon::now();

            $attendance = $this->attendanceRepository->create([
                'user_id' => $user->id,
                'check_in' => $checkInTime,
                'check_out' => null,
                'date' => $checkInTime->toDateString(),
                'total_hours' => 0,
            ]);

            $this->taskRepository->createMany($attendance, $tasks);

            $this->activityLogService->logActivity(
                $user,
                ActivityType::CHECK_IN,
                "User checked in with " . count($tasks) . " tasks",
                $request
            );

            DB::commit();
            return $attendance->load('tasks');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Check-in failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Check out employee with task statuses.
     */
    public function checkOut(User $user, int $attendanceId, array $tasksData, ?Request $request = null): Attendance
    {
        $attendance = $this->attendanceRepository->findById($attendanceId);

        if (!$attendance || $attendance->user_id !== $user->id) {
            throw new \Exception('Attendance record not found or does not belong to you.');
        }

        if ($attendance->check_out) {
            throw new \Exception('You have already checked out.');
        }

        DB::beginTransaction();
        try {
            $checkOutTime = Carbon::now();
            $totalHours = $this->calculateTotalHours($attendance->check_in, $checkOutTime);

            $this->attendanceRepository->update($attendance, [
                'check_out' => $checkOutTime,
                'total_hours' => $totalHours,
            ]);

            // Only update task statuses if tasks data is provided
            if (!empty($tasksData)) {
                $this->taskRepository->updateMultipleStatuses($tasksData);
            } else {
                Log::warning("Check-out without task updates for attendance {$attendance->id}");
            }

            $this->activityLogService->logActivity(
                $user,
                ActivityType::CHECK_OUT,
                "User checked out. Total hours: {$totalHours}",
                $request
            );

            DB::commit();
            return $attendance->fresh(['tasks']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Check-out failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Auto checkout an active attendance, marking incomplete tasks with default blocker reason.
     */
    public function autoCheckOut(Attendance $attendance): Attendance
    {
        if ($attendance->check_out) {
            throw new \Exception("Attendance #{$attendance->id} is already checked out.");
        }

        DB::beginTransaction();
        try {
            $checkOutTime = Carbon::now();
            $totalHours = $this->calculateTotalHours($attendance->check_in, $checkOutTime);

            $this->attendanceRepository->update($attendance, [
                'check_out' => $checkOutTime,
                'total_hours' => $totalHours,
                'is_auto_checkout' => true,
            ]);

            Task::where('attendance_id', $attendance->id)
                ->where('is_completed', false)
                ->whereNull('blocker_reason')
                ->update(['blocker_reason' => 'belum selesai']);

            $user = $attendance->user;
            if ($user) {
                $this->activityLogService->logActivity(
                    $user,
                    ActivityType::AUTO_CHECKOUT,
                    "Auto checkout after {$totalHours} hours (required: {$user->team?->getRequiredWorkHours()} hours)"
                );
            }

            DB::commit();

            return $attendance->fresh(['tasks']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Auto checkout failed for attendance #{$attendance->id}: ".$e->getMessage());
            throw $e;
        }
    }

    /**
     * Calculate total working hours between check-in and check-out.
     */
    public function calculateTotalHours(Carbon $checkIn, Carbon $checkOut): float
    {
        $minutes = $checkIn->diffInMinutes($checkOut);
        return round($minutes / 60, 2);
    }

    /**
     * Get today's status for user.
     */
    public function getTodayStatus(User $user): array
    {
        $user->loadMissing('team');

        $today = Carbon::today();
        $activeAttendance = $this->attendanceRepository->findActiveByUser($user);
        $todayAttendances = $this->attendanceRepository->getAllByUserAndDate($user, $today);

        $todayTotalHours = $todayAttendances->sum('total_hours');

        if ($activeAttendance) {
            $elapsedHours = $this->calculateTotalHours($activeAttendance->check_in, Carbon::now());
            $todayTotalHours += $elapsedHours;
        }

        $previousSessions = $todayAttendances
            ->where('id', '!=', $activeAttendance?->id)
            ->map(function ($attendance) {
                return [
                    'check_in' => $attendance->check_in,
                    'check_out' => $attendance->check_out,
                    'total_hours' => $attendance->total_hours,
                    'is_auto_checkout' => (bool) $attendance->is_auto_checkout,
                ];
            })
            ->values();

        $lastAutoCheckout = $todayAttendances
            ->where('is_auto_checkout', true)
            ->sortByDesc('check_out')
            ->first();

        // Build current session data with proper null checks
        $currentSession = null;
        if ($activeAttendance) {
            // Ensure tasks relation is loaded
            if (!$activeAttendance->relationLoaded('tasks')) {
                $activeAttendance->load('tasks');
            }
            
            $currentSession = [
                'id' => $activeAttendance->id,
                'check_in' => $activeAttendance->check_in,
                'elapsed_hours' => $this->calculateTotalHours($activeAttendance->check_in, Carbon::now()),
                'tasks' => $activeAttendance->tasks->map(function ($task) {
                    return [
                        'id' => $task->id,
                        'title' => $task->title,
                        'is_completed' => $task->is_completed,
                        'blocker_reason' => $task->blocker_reason,
                    ];
                })->toArray(),
            ];
        }

        $requiredWorkHours = $user->team?->getRequiredWorkHours() ?? Team::DEFAULT_REQUIRED_WORK_HOURS;

        return [
            'date' => $today->toDateString(),
            'is_checked_in' => $activeAttendance !== null,
            'current_session' => $currentSession,
            'today_total_hours' => $todayTotalHours,
            'required_hours' => $requiredWorkHours,
            'remaining_hours' => max(0, $requiredWorkHours - $todayTotalHours),
            'previous_sessions' => $previousSessions,
            'last_auto_checkout' => $lastAutoCheckout ? [
                'id' => $lastAutoCheckout->id,
                'check_out' => $lastAutoCheckout->check_out,
                'total_hours' => $lastAutoCheckout->total_hours,
            ] : null,
        ];
    }
}

