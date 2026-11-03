<?php

namespace App\Repositories;

use App\Models\Attendance;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class TaskRepository
{
    /**
     * Create tasks for attendance.
     */
    public function createMany(Attendance $attendance, array $tasksData): Collection
    {
        $taskIds = [];

        foreach ($tasksData as $taskData) {
            $task = Task::create([
                'attendance_id' => $attendance->id,
                'project_id' => $taskData['project_id'] ?? null,
                'title' => $taskData['title'],
                'is_completed' => false,
                'blocker_reason' => null,
            ]);
            $taskIds[] = $task->id;
        }

        // Return as Eloquent Collection
        return Task::whereIn('id', $taskIds)->with('project')->get();
    }

    /**
     * Update task status.
     */
    public function updateStatus(Task $task, bool $isCompleted, ?string $blockerReason = null): bool
    {
        return $task->update([
            'is_completed' => $isCompleted,
            'blocker_reason' => $blockerReason,
        ]);
    }

    /**
     * Update multiple tasks status.
     */
    public function updateMultipleStatuses(array $tasksData): void
    {
        foreach ($tasksData as $taskData) {
            $task = Task::findOrFail($taskData['id']);
            $this->updateStatus(
                $task,
                $taskData['is_completed'],
                $taskData['blocker_reason'] ?? null
            );
        }
    }

    /**
     * Get incomplete tasks for user (from all history).
     */
    public function getIncompleteTasksForUser(int $userId): Collection
    {
        return Task::whereHas('attendance', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })
            ->where('is_completed', false)
            ->with(['attendance', 'project'])
            ->get();
    }

    /**
     * Get incomplete tasks from user's last attendance session only.
     */
    public function getIncompleteTasksFromLastSession(int $userId): Collection
    {
        // Get the last attendance (most recent check-out)
        $lastAttendance = Attendance::where('user_id', $userId)
            ->whereNotNull('check_out') // Only completed sessions
            ->orderBy('check_out', 'desc')
            ->first();

        if (! $lastAttendance) {
            return new Collection([]);
        }

        // Get incomplete tasks from that attendance only
        return Task::where('attendance_id', $lastAttendance->id)
            ->where('is_completed', false)
            ->with(['attendance', 'project'])
            ->get();
    }

    /**
     * Tasks an auto checkout left open that the employee may still tick off: the incomplete tasks
     * of the user's auto-checked-out sessions from today back to Task::AUTO_CHECKOUT_EDIT_DAYS ago.
     */
    public function getEditableAfterAutoCheckout(int $userId): Collection
    {
        $since = Carbon::today()->subDays(Task::AUTO_CHECKOUT_EDIT_DAYS)->toDateString();

        return Task::where('is_completed', false)
            ->whereHas('attendance', function ($query) use ($userId, $since) {
                $query->where('user_id', $userId)
                    ->where('is_auto_checkout', true)
                    ->whereNotNull('check_out')
                    ->where('date', '>=', $since);
            })
            ->with(['attendance', 'project'])
            ->orderBy('attendance_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * Get tasks by attendance.
     */
    public function getByAttendance(Attendance $attendance): Collection
    {
        return Task::where('attendance_id', $attendance->id)->get();
    }
}
