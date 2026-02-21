<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ManagerLeaveUpdateRequest;
use App\Http\Resources\LeaveResource;
use App\Models\Leave;
use App\Services\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ManagerLeaveController extends Controller
{
    public function __construct(
        private LeaveService $leaveService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $status = $request->get('status');
            $teamId = auth()->user()->team_id;

            $query = Leave::with(['user', 'approver'])
                ->whereHas('user', function ($q) use ($teamId) {
                    $q->where('team_id', $teamId);
                })
                ->orderBy('created_at', 'desc');

            if ($status) {
                $query->where('status', $status);
            }

            $leaves = $query->get();

            return response()->json([
                'success' => true,
                'data' => LeaveResource::collection($leaves),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get leave requests failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to get leave requests',
            ], 500);
        }
    }

    public function update(ManagerLeaveUpdateRequest $request, int $id): JsonResponse
    {
        try {
            $leave = Leave::findOrFail($id);
            /** @var \App\Models\User $manager */
            $manager = $request->user();

            if ($leave->user->team_id !== $manager->team_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak berwenang mengedit pengajuan cuti ini.',
                ], 403);
            }

            $leave = $this->leaveService->updateLeave(
                $leave,
                $manager,
                $request->validated(),
                $request
            );

            return response()->json([
                'success' => true,
                'data' => new LeaveResource($leave),
                'message' => 'Pengajuan cuti berhasil diperbarui.',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Pengajuan cuti tidak ditemukan.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Update leave failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui pengajuan cuti.',
            ], 500);
        }
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        try {
            $leave = Leave::findOrFail($id);
            $manager = auth()->user();

            // Ensure leave belongs to user in the same team
            if ($leave->user->team_id !== $manager->team_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to approve this leave request',
                ], 403);
            }

            $leave = $this->leaveService->approveLeave(
                $leave,
                $manager,
                $request->input('notes'),
                $request
            );

            return response()->json([
                'success' => true,
                'data' => new LeaveResource($leave),
                'message' => 'Leave request approved',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Approve leave failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve leave request',
            ], 500);
        }
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        try {
            $leave = Leave::findOrFail($id);
            $manager = auth()->user();

            if ($leave->user->team_id !== $manager->team_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to reject this leave request',
                ], 403);
            }

            $leave = $this->leaveService->rejectLeave(
                $leave,
                $manager,
                $request->input('notes'),
                $request
            );

            return response()->json([
                'success' => true,
                'data' => new LeaveResource($leave),
                'message' => 'Leave request rejected',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Reject leave failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject leave request',
            ], 500);
        }
    }
}
