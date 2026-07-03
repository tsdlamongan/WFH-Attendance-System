<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ManagerTaskController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLogService
    ) {}

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            Log::info('Manager task update request', [
                'task_id' => $id,
                'request_data' => $request->all(),
            ]);

            $validator = Validator::make($request->all(), [
                'is_completed' => 'required|boolean',
                'blocker_reason' => 'nullable|string|max:500',
            ], [
                'is_completed.required' => 'Status penyelesaian tugas wajib diisi.',
                'is_completed.boolean' => 'Status penyelesaian tugas harus bernilai benar atau salah.',
                'blocker_reason.max' => 'Alasan kendala maksimal 500 karakter.',
            ]);

            if ($validator->fails()) {
                Log::warning('Task update validation failed', [
                    'errors' => $validator->errors(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Periksa kembali data yang Anda masukkan.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $task = Task::with('attendance.user')->find($id);

            if (! $task) {
                Log::warning('Task not found', ['task_id' => $id]);

                return response()->json([
                    'success' => false,
                    'message' => 'Task not found',
                ], 404);
            }

            // Ensure task belongs to user in the same team
            if ($task->attendance->user->team_id !== auth()->user()->team_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to update this task',
                ], 403);
            }

            $validated = $validator->validated();

            // Validate blocker reason is required if task is not completed
            if (! $validated['is_completed']) {
                if (empty($validated['blocker_reason'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Alasan kendala wajib diisi untuk tugas yang belum selesai.',
                    ], 422);
                }
            } else {
                // Clear blocker reason if task is completed
                $validated['blocker_reason'] = null;
            }

            $oldStatus = $task->is_completed ? 'completed' : 'incomplete';
            $newStatus = $validated['is_completed'] ? 'completed' : 'incomplete';

            $task->update($validated);

            $manager = auth()->user();
            $employee = $task->attendance->user;

            $this->activityLogService->logActivity(
                $manager,
                ActivityType::TASK_UPDATED,
                "Manager updated task '{$task->title}' for {$employee->name}. Status changed from {$oldStatus} to {$newStatus}",
                $request
            );

            Log::info('Task updated successfully', [
                'task_id' => $id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Task updated successfully',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Update task failed: '.$e->getMessage(), [
                'task_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update task: '.$e->getMessage(),
            ], 500);
        }
    }
}
