<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\Attendance;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\TaskRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskService
{
    public function __construct(
        private TaskRepository $taskRepository,
        private AttendanceRepository $attendanceRepository,
        private ActivityLogService $activityLogService
    ) {}

    /**
     * Add tasks to existing attendance session.
     */
    public function addTasks(User $user, int $attendanceId, array $tasks, ?Request $request = null): Collection
    {
        $attendance = $this->attendanceRepository->findById($attendanceId);

        if (! $attendance || $attendance->user_id !== $user->id) {
            throw new \Exception('Attendance record not found or does not belong to you.');
        }

        if ($attendance->check_out) {
            throw new \Exception('Cannot add tasks to completed attendance session.');
        }

        $createdTasks = $this->taskRepository->createMany($attendance, $tasks);

        $this->activityLogService->logActivity(
            $user,
            ActivityType::TASK_CREATED,
            'User added '.count($tasks).' tasks to attendance session',
            $request
        );

        return $createdTasks;
    }

    /**
     * Tick off tasks that an auto checkout left open. The employee could not update them at
     * checkout, so they may do it afterwards, for sessions of today and yesterday only
     * (Task::AUTO_CHECKOUT_EDIT_DAYS). The tasks only go from open to completed, and either all
     * of them are completed or none is.
     *
     * @param  array<int, int>  $taskIds
     */
    public function completeAfterAutoCheckout(User $user, array $taskIds, ?Request $request = null): Collection
    {
        $taskIds = array_values(array_unique($taskIds));

        $tasks = $this->taskRepository->getEditableAfterAutoCheckout($user->id)
            ->whereIn('id', $taskIds)
            ->values();

        if ($tasks->count() !== count($taskIds)) {
            throw new \Exception('Hanya tugas yang belum selesai dari sesi yang terkena auto checkout (hari ini atau kemarin) yang dapat diselesaikan.');
        }

        DB::transaction(function () use ($user, $tasks, $request) {
            foreach ($tasks as $task) {
                // Completed tasks carry no blocker, so the auto checkout's one goes away too.
                $this->taskRepository->updateStatus($task, true);
            }

            $this->activityLogService->logActivity(
                $user,
                ActivityType::TASK_UPDATED,
                'User completed '.$tasks->count().' task(s) left open by an auto checkout',
                $request
            );
        });

        return $tasks;
    }

    /**
     * Get incomplete tasks for user (from all history).
     */
    public function getIncompleteTasks(User $user): Collection
    {
        return $this->taskRepository->getIncompleteTasksForUser($user->id);
    }

    /**
     * Get incomplete tasks from user's last session only.
     */
    public function getIncompleteTasksFromLastSession(User $user): Collection
    {
        return $this->taskRepository->getIncompleteTasksFromLastSession($user->id);
    }
}
