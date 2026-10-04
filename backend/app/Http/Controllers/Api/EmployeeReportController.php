<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EmployeeReportController extends Controller
{
    public function __construct(
        private ReportService $reportService
    ) {}

    public function myReport(Request $request): JsonResponse
    {
        // Validated outside the try block so a ValidationException renders as a 422, not a 500.
        $validated = $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        try {
            $user = auth()->user();

            $startDate = Carbon::parse($validated['start_date'] ?? Carbon::now()->startOfMonth()->format('Y-m-d'));
            $endDate = Carbon::parse($validated['end_date'] ?? Carbon::now()->format('Y-m-d'));

            $report = $this->reportService->getEmployeeReport($user, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => $report,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get employee report failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to get report',
            ], 500);
        }
    }
}
