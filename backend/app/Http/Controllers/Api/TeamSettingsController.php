<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TeamSettingsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TeamSettingsController extends Controller
{
    /**
     * Get current team settings.
     */
    public function show(): JsonResponse
    {
        try {
            $user = auth()->user();
            $team = $user->team;

            if (!$team) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tim tidak ditemukan',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $team->id,
                    'name' => $team->name,
                    'slug' => $team->slug,
                    'description' => $team->description,
                    'required_work_hours' => $team->required_work_hours,
                    'default_leave_quota_days' => $team->default_leave_quota_days,
                    'max_leave_days_per_month' => $team->max_leave_days_per_month,
                    'check_in_window_start' => substr($team->check_in_window_start, 0, 5), // Format to HH:MM
                    'check_in_window_end' => substr($team->check_in_window_end, 0, 5), // Format to HH:MM
                    'is_active' => $team->is_active,
                    'created_at' => $team->created_at?->toIso8601String(),
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get team settings failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil pengaturan tim',
            ], 500);
        }
    }

    /**
     * Update team settings.
     */
    public function update(TeamSettingsRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();
            $team = $user->team;

            if (!$team) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tim tidak ditemukan',
                ], 404);
            }

            $team->update($request->validated());

            Log::info('Team settings updated', [
                'team_id' => $team->id,
                'updated_by' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $team->id,
                    'name' => $team->name,
                    'slug' => $team->slug,
                    'description' => $team->description,
                    'required_work_hours' => $team->required_work_hours,
                    'default_leave_quota_days' => $team->default_leave_quota_days,
                    'max_leave_days_per_month' => $team->max_leave_days_per_month,
                    'check_in_window_start' => substr($team->check_in_window_start, 0, 5), // Format to HH:MM
                    'check_in_window_end' => substr($team->check_in_window_end, 0, 5), // Format to HH:MM
                    'is_active' => $team->is_active,
                ],
                'message' => 'Pengaturan tim berhasil diperbarui',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Update team settings failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui pengaturan tim',
            ], 500);
        }
    }
}
