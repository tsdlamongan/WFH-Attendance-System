<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OvertimeRequest;
use App\Http\Resources\OvertimeResource;
use App\Models\Overtime;
use App\Services\OvertimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class OvertimeController extends Controller
{
    public function __construct(
        private OvertimeService $overtimeService
    ) {}

    public function store(OvertimeRequest $request): JsonResponse
    {
        try {
            $overtime = $this->overtimeService->request(
                auth()->user(),
                $request->validated(),
                $request
            );

            return response()->json([
                'success' => true,
                'data' => new OvertimeResource($overtime),
                'message' => 'Pengajuan lembur berhasil dikirim.',
            ], 201);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Create overtime request failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim pengajuan lembur.',
            ], 500);
        }
    }

    public function myRequests(): JsonResponse
    {
        try {
            $overtimes = Overtime::where('user_id', auth()->id())
                ->with(['user', 'approver'])
                ->withSum('attendances as used_hours', 'total_hours')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => OvertimeResource::collection($overtimes),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get overtime requests failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data lembur.',
            ], 500);
        }
    }
}
