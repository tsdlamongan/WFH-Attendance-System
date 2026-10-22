<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteAutoCheckoutTasksRequest;
use App\Http\Requests\TaskRequest;
use App\Http\Resources\TaskResource;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TaskController extends Controller
{
    public function __construct(
        private TaskService $taskService
    ) {}

    public function addTasks(TaskRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $attendanceId = $request->validated()['attendance_id'];
            $tasks = $request->validated()['tasks'];

            $createdTasks = $this->taskService->addTasks($user, $attendanceId, $tasks, $request);

            return response()->json([
                'success' => true,
                'data' => [
                    'tasks' => TaskResource::collection($createdTasks),
                ],
                'message' => 'Tasks added successfully',
            ], 201);
        } catch (\Exception $e) {
            Log::error('Add tasks failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function completeAfterAutoCheckout(CompleteAutoCheckoutTasksRequest $request): JsonResponse
    {
        try {
            $completedTasks = $this->taskService->completeAfterAutoCheckout(
                auth()->user(),
                $request->validated()['task_ids'],
                $request
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'tasks' => TaskResource::collection($completedTasks),
                ],
                'message' => $completedTasks->count().' tugas ditandai selesai',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Complete auto checkout tasks failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function incomplete(): JsonResponse
    {
        try {
            $user = auth()->user();
            $incompleteTasks = $this->taskService->getIncompleteTasks($user);

            return response()->json([
                'success' => true,
                'data' => TaskResource::collection($incompleteTasks),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get incomplete tasks failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to get incomplete tasks',
            ], 500);
        }
    }

    public function incompleteFromLastSession(): JsonResponse
    {
        try {
            $user = auth()->user();
            $incompleteTasks = $this->taskService->getIncompleteTasksFromLastSession($user);

            return response()->json([
                'success' => true,
                'data' => TaskResource::collection($incompleteTasks),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get incomplete tasks from last session failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to get incomplete tasks from last session',
            ], 500);
        }
    }
}
