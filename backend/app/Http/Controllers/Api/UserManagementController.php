<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Http\Resources\UserResource;
use App\Repositories\UserRepository;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserManagementController extends Controller
{
    public function __construct(
        private UserRepository $userRepository,
        private ActivityLogService $activityLogService
    ) {}

    public function index(): JsonResponse
    {
        try {
            $perPage = request()->get('per_page', 10);
            $teamId = auth()->user()->team_id;
            
            // Validate per_page parameter
            $perPage = in_array($perPage, [10, 50, 100, 1000]) ? $perPage : 10;
            
            $users = $this->userRepository->getPaginated($perPage, $teamId);

            return response()->json([
                'success' => true,
                'data' => UserResource::collection($users->items()),
                'pagination' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                    'from' => $users->firstItem(),
                    'to' => $users->lastItem(),
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get users failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to get users',
            ], 500);
        }
    }

    public function store(UserRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $currentUser = auth()->user();

            // Super admin must specify team_id in request, others use their own team
            if ($currentUser->isSuperAdmin()) {
                if (!isset($data['team_id'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Team ID is required for super admin',
                    ], 422);
                }
            } else {
                $data['team_id'] = $currentUser->team_id;
            }

            // If leave_quota_days is not set, use team's default
            if (!isset($data['leave_quota_days'])) {
                $team = \App\Models\Team::find($data['team_id']);
                if ($team) {
                    $data['leave_quota_days'] = $team->default_leave_quota_days;
                } else {
                    $data['leave_quota_days'] = 12; // Default fallback
                }
            }

            $user = $this->userRepository->create($data);

            return response()->json([
                'success' => true,
                'data' => new UserResource($user),
                'message' => 'User created successfully',
            ], 201);
        } catch (\Exception $e) {
            Log::error('Create user failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to create user',
            ], 500);
        }
    }

    public function update(UserRequest $request, int $id): JsonResponse
    {
        try {
            $user = $this->userRepository->findById($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            $currentUser = auth()->user();

            // Ensure user belongs to the same team (super admin can update any user)
            if (!$currentUser->isSuperAdmin() && $user->team_id !== $currentUser->team_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to update this user',
                ], 403);
            }

            $this->userRepository->update($user, $request->validated());

            return response()->json([
                'success' => true,
                'data' => new UserResource($user->fresh()),
                'message' => 'User updated successfully',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Update user failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update user',
            ], 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $user = $this->userRepository->findById($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            $currentUser = auth()->user();

            // Ensure user belongs to the same team (super admin can delete any user)
            if (!$currentUser->isSuperAdmin() && $user->team_id !== $currentUser->team_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to delete this user',
                ], 403);
            }

            $this->userRepository->delete($user);

            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Delete user failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete user',
            ], 500);
        }
    }

    public function toggleDisabled(Request $request, int $id): JsonResponse
    {
        try {
            $user = $this->userRepository->findById($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            $currentUser = auth()->user();

            if (!$currentUser->isSuperAdmin() && $user->team_id !== $currentUser->team_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to modify this user',
                ], 403);
            }

            if ($user->id === $currentUser->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak dapat menonaktifkan diri sendiri',
                ], 422);
            }

            if ($user->isSuperAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak dapat menonaktifkan super admin',
                ], 422);
            }

            $newState = !$user->is_disabled;
            $user->is_disabled = $newState;
            $user->save();

            if ($newState) {
                $user->tokens()->delete();
            }

            $this->activityLogService->logActivity(
                $currentUser,
                $newState ? ActivityType::USER_DISABLED : ActivityType::USER_ENABLED,
                ($newState ? 'Disabled' : 'Enabled') . ' user ' . $user->name,
                $request
            );

            return response()->json([
                'success' => true,
                'data' => new UserResource($user->fresh()),
                'message' => $newState ? 'User dinonaktifkan' : 'User diaktifkan',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Toggle disabled failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle user status',
            ], 500);
        }
    }

    public function search(Request $request): JsonResponse
    {
        try {
            $search = $request->get('q', '');
            $limit = $request->get('limit', 10);
            $teamId = auth()->user()->team_id;

            if (empty($search)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                ], 200);
            }

            $users = $this->userRepository->searchByName($search, $limit, $teamId);

            return response()->json([
                'success' => true,
                'data' => UserResource::collection($users),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Search users failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to search users',
            ], 500);
        }
    }
}
