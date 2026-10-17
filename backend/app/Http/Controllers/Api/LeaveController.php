<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeaveRequest;
use App\Http\Resources\LeaveResource;
use App\Models\Leave;
use App\Services\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LeaveController extends Controller
{
    public function __construct(
        private LeaveService $leaveService
    ) {}

    public function store(LeaveRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();

            $leave = $this->leaveService->requestLeave(
                $user,
                $request->validated(),
                $request
            );

            return response()->json([
                'success' => true,
                'data' => new LeaveResource($leave),
                'message' => 'Leave request submitted successfully',
            ], 201);
        } catch (\Exception $e) {
            Log::error('Create leave request failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function myRequests(): JsonResponse
    {
        try {
            $user = auth()->user();
            $leaves = Leave::where('user_id', $user->id)
                ->with(['user', 'approver'])
                ->orderBy('created_at', 'desc')
                ->get();

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

    public function summary(Request $request): JsonResponse
    {
        // Validated outside the try block so a ValidationException renders as a 422, not a 500.
        $validated = $request->validate([
            'year' => 'nullable|integer|between:2000,2100',
        ]);

        try {
            $user = auth()->user();
            $year = isset($validated['year']) ? (int) $validated['year'] : null;

            $summary = $this->leaveService->getLeaveSummary($user, $year);

            return response()->json([
                'success' => true,
                'data' => $summary,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get leave summary failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to get leave summary',
            ], 500);
        }
    }
}
