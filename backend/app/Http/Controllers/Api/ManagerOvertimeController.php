<?php

namespace App\Http\Controllers\Api;

use App\Enums\OvertimeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveOvertimeRequest;
use App\Http\Requests\AssignOvertimeRequest;
use App\Http\Requests\RejectOvertimeRequest;
use App\Http\Resources\OvertimeResource;
use App\Models\Overtime;
use App\Models\User;
use App\Services\OvertimeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ManagerOvertimeController extends Controller
{
    public function __construct(
        private OvertimeService $overtimeService
    ) {}

    public function index(Request $request): JsonResponse
    {
        // Validated outside the try block so a ValidationException renders as a 422, not a 500.
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(OvertimeStatus::class)],
        ]);

        try {
            $teamId = auth()->user()->team_id;

            $query = Overtime::with(['user', 'approver'])
                ->withSum('attendances as used_hours', 'total_hours')
                ->whereHas('user', fn ($q) => $q->where('team_id', $teamId))
                ->orderBy('created_at', 'desc');

            if (! empty($validated['status'])) {
                $query->where('status', $validated['status']);
            }

            return response()->json([
                'success' => true,
                'data' => OvertimeResource::collection($query->get()),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get overtime requests failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data lembur.',
            ], 500);
        }
    }

    public function pendingCount(): JsonResponse
    {
        try {
            $teamId = auth()->user()->team_id;

            $count = Overtime::where('status', OvertimeStatus::PENDING)
                ->whereHas('user', fn ($q) => $q->where('team_id', $teamId))
                ->count();

            return response()->json([
                'success' => true,
                'data' => ['count' => $count],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get pending overtime count failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil jumlah pengajuan lembur.',
            ], 500);
        }
    }

    public function store(AssignOvertimeRequest $request): JsonResponse
    {
        try {
            $manager = auth()->user();
            $employee = User::findOrFail($request->validated('user_id'));

            $overtime = $this->overtimeService->assign(
                $manager,
                $employee,
                $request->validated(),
                $request
            );

            return response()->json([
                'success' => true,
                'data' => new OvertimeResource($overtime),
                'message' => 'Lembur berhasil ditugaskan.',
            ], 201);
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Assign overtime failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal menugaskan lembur.',
            ], 500);
        }
    }

    public function approve(ApproveOvertimeRequest $request, int $id): JsonResponse
    {
        try {
            $overtime = $this->findForManager($id);

            $overtime = $this->overtimeService->approve(
                $overtime,
                auth()->user(),
                $request->validated('approved_minutes'),
                $request->validated('notes'),
                $request
            );

            return response()->json([
                'success' => true,
                'data' => new OvertimeResource($overtime),
                'message' => 'Pengajuan lembur disetujui.',
            ], 200);
        } catch (\Throwable $e) {
            return $this->failure($e, 'approve');
        }
    }

    public function reject(RejectOvertimeRequest $request, int $id): JsonResponse
    {
        try {
            $overtime = $this->findForManager($id);

            $overtime = $this->overtimeService->reject(
                $overtime,
                auth()->user(),
                $request->validated('notes'),
                $request
            );

            return response()->json([
                'success' => true,
                'data' => new OvertimeResource($overtime),
                'message' => 'Pengajuan lembur ditolak.',
            ], 200);
        } catch (\Throwable $e) {
            return $this->failure($e, 'reject');
        }
    }

    /**
     * Load an overtime record, making sure it belongs to an employee of the manager's team.
     */
    private function findForManager(int $id): Overtime
    {
        $overtime = Overtime::with('user')->findOrFail($id);

        if ($overtime->user->team_id !== auth()->user()->team_id) {
            throw new AuthorizationException('Tidak berwenang memproses pengajuan lembur ini.');
        }

        return $overtime;
    }

    private function failure(\Throwable $e, string $action): JsonResponse
    {
        if ($e instanceof AuthorizationException) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        }

        if ($e instanceof ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Pengajuan lembur tidak ditemukan.',
            ], 404);
        }

        if ($e instanceof \DomainException) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        Log::error("Overtime {$action} failed: ".$e->getMessage());

        return response()->json([
            'success' => false,
            'message' => 'Gagal memproses pengajuan lembur.',
        ], 500);
    }
}
