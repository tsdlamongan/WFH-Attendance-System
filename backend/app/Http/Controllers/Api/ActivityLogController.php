<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Repositories\ActivityLogRepository;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ActivityLogController extends Controller
{
    public function __construct(
        private ActivityLogRepository $activityLogRepository
    ) {}

    public function index(Request $request): JsonResponse
    {
        // Validated outside the try block so a ValidationException renders as a 422, not a 500.
        $validated = $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ], [
            'start_date.date_format' => 'Tanggal mulai harus berformat YYYY-MM-DD.',
            'end_date.date_format' => 'Tanggal selesai harus berformat YYYY-MM-DD.',
            'end_date.after_or_equal' => 'Tanggal selesai harus sama dengan atau setelah tanggal mulai.',
        ]);

        try {
            $userId = $request->get('user_id');
            $action = $request->get('action');
            $startDate = isset($validated['start_date']) ? Carbon::parse($validated['start_date']) : null;
            $endDate = isset($validated['end_date']) ? Carbon::parse($validated['end_date']) : null;
            $perPage = $request->get('per_page', 10);
            
            // Validate per_page parameter
            $perPage = in_array($perPage, [10, 50, 100, 1000]) ? $perPage : 10;

            $activityType = null;
            if ($action) {
                try {
                    $activityType = ActivityType::from($action);
                } catch (\ValueError $e) {
                    // Invalid action type, ignore it
                    Log::warning('Invalid activity type: ' . $action);
                }
            }

            $teamId = auth()->user()->team_id;
            
            $logs = $this->activityLogRepository->getPaginatedWithFilters(
                $userId ? (int)$userId : null,
                $activityType,
                $startDate,
                $endDate,
                $perPage,
                $teamId
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'logs' => ActivityLogResource::collection($logs->items()),
                ],
                'pagination' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                    'from' => $logs->firstItem(),
                    'to' => $logs->lastItem(),
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get activity logs failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil log aktivitas',
            ], 500);
        }
    }
}
