<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeaveQuotaRequest;
use App\Models\LeaveQuota;
use App\Models\User;
use App\Services\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LeaveQuotaController extends Controller
{
    public function __construct(
        private LeaveService $leaveService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $year = (int) $request->query('year', now()->year);
            /** @var User $authUser */
            $authUser = $request->user();
            $teamId = $authUser->team_id;

            $users = User::where('team_id', $teamId)
                ->where('is_disabled', false)
                ->orderBy('name')
                ->get();

            $quotas = LeaveQuota::whereIn('user_id', $users->pluck('id'))
                ->where('year', $year)
                ->get()
                ->keyBy('user_id');

            $data = $users->map(function (User $user) use ($year, $quotas) {
                $leaveQuota = $quotas->get($user->id);
                $effectiveQuota = $leaveQuota ? $leaveQuota->quota_days : $user->leave_quota_days;
                $summary = $this->leaveService->getLeaveSummary($user, $year);

                return [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'year' => $year,
                    'quota_days' => $effectiveQuota,
                    'is_custom' => $leaveQuota !== null,
                    'used_days' => $summary['used_days'],
                    'pending_days' => $summary['pending_days'],
                    'remaining_days' => $summary['remaining_days'],
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('Get leave quotas failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data jatah cuti.',
            ], 500);
        }
    }

    public function update(LeaveQuotaRequest $request, int $userId): JsonResponse
    {
        try {
            /** @var User $manager */
            $manager = $request->user();
            $user = User::where('id', $userId)
                ->where('team_id', $manager->team_id)
                ->where('is_disabled', false)
                ->firstOrFail();

            $validated = $request->validated();

            $leaveQuota = LeaveQuota::updateOrCreate(
                ['user_id' => $user->id, 'year' => $validated['year']],
                ['quota_days' => $validated['quota_days']]
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'year' => $leaveQuota->year,
                    'quota_days' => $leaveQuota->quota_days,
                ],
                'message' => "Jatah cuti {$user->name} untuk tahun {$leaveQuota->year} berhasil diperbarui.",
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Pengguna tidak ditemukan atau bukan anggota tim Anda.',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Update leave quota failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui jatah cuti.',
            ], 500);
        }
    }

    public function bulkUpdate(LeaveQuotaRequest $request): JsonResponse
    {
        try {
            /** @var User $manager */
            $manager = $request->user();
            $validated = $request->validated();

            $users = User::where('team_id', $manager->team_id)
                ->where('is_disabled', false)
                ->get();

            DB::beginTransaction();
            try {
                $count = 0;
                foreach ($users as $user) {
                    LeaveQuota::updateOrCreate(
                        ['user_id' => $user->id, 'year' => $validated['year']],
                        ['quota_days' => $validated['quota_days']]
                    );
                    $count++;
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'data' => [
                        'year' => $validated['year'],
                        'quota_days' => $validated['quota_days'],
                        'affected_users' => $count,
                    ],
                    'message' => "Jatah cuti {$validated['quota_days']} hari untuk tahun {$validated['year']} berhasil diterapkan ke {$count} pengguna.",
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Bulk update leave quotas failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui jatah cuti secara massal.',
            ], 500);
        }
    }
}
