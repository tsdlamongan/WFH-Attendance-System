<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeamManagementController extends Controller
{
    /**
     * Get all teams (Super admin only)
     */
    public function index(): JsonResponse
    {
        try {
            $perPage = request()->get('per_page', 10);

            // Validate per_page parameter
            $perPage = in_array($perPage, [10, 50, 100, 1000]) ? $perPage : 10;

            $teams = Team::withCount(['users', 'managers', 'employees'])
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $teams->items(),
                'pagination' => [
                    'current_page' => $teams->currentPage(),
                    'last_page' => $teams->lastPage(),
                    'per_page' => $teams->perPage(),
                    'total' => $teams->total(),
                    'from' => $teams->firstItem(),
                    'to' => $teams->lastItem(),
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get teams failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to get teams',
            ], 500);
        }
    }

    /**
     * Create a new team
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'required_work_hours' => 'required|numeric|min:1|max:24',
                'default_leave_quota_days' => 'required|integer|min:0|max:365',
                'max_leave_days_per_month' => 'required|integer|min:0|max:31',
            ], [
                'name.required' => 'Nama tim wajib diisi.',
                'name.max' => 'Nama tim maksimal 255 karakter.',
                'description.max' => 'Deskripsi maksimal 1000 karakter.',
                'required_work_hours.required' => 'Jam kerja wajib diisi.',
                'required_work_hours.numeric' => 'Jam kerja harus berupa angka.',
                'required_work_hours.min' => 'Jam kerja minimal :min jam.',
                'required_work_hours.max' => 'Jam kerja maksimal :max jam.',
                'default_leave_quota_days.required' => 'Kuota cuti wajib diisi.',
                'default_leave_quota_days.integer' => 'Kuota cuti harus berupa angka bulat.',
                'default_leave_quota_days.min' => 'Kuota cuti minimal :min hari.',
                'default_leave_quota_days.max' => 'Kuota cuti maksimal :max hari.',
                'max_leave_days_per_month.required' => 'Kuota cuti per bulan wajib diisi.',
                'max_leave_days_per_month.integer' => 'Kuota cuti per bulan harus berupa angka bulat.',
                'max_leave_days_per_month.min' => 'Kuota cuti per bulan minimal :min hari.',
                'max_leave_days_per_month.max' => 'Kuota cuti per bulan maksimal :max hari.',
            ]);

            $validated['slug'] = Str::slug($validated['name']).'-'.time();
            $validated['is_active'] = true;

            $team = Team::create($validated);

            return response()->json([
                'success' => true,
                'data' => $team,
                'message' => 'Team created successfully',
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Periksa kembali data yang Anda masukkan.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Create team failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to create team',
            ], 500);
        }
    }

    /**
     * Update a team
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $team = Team::find($id);

            if (! $team) {
                return response()->json([
                    'success' => false,
                    'message' => 'Team not found',
                ], 404);
            }

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'description' => 'nullable|string|max:1000',
                'required_work_hours' => 'sometimes|numeric|min:1|max:24',
                'default_leave_quota_days' => 'sometimes|integer|min:0|max:365',
                'max_leave_days_per_month' => 'sometimes|integer|min:0|max:31',
                'is_active' => 'sometimes|boolean',
            ], [
                'name.max' => 'Nama tim maksimal 255 karakter.',
                'description.max' => 'Deskripsi maksimal 1000 karakter.',
                'required_work_hours.numeric' => 'Jam kerja harus berupa angka.',
                'required_work_hours.min' => 'Jam kerja minimal :min jam.',
                'required_work_hours.max' => 'Jam kerja maksimal :max jam.',
                'default_leave_quota_days.integer' => 'Kuota cuti harus berupa angka bulat.',
                'default_leave_quota_days.min' => 'Kuota cuti minimal :min hari.',
                'default_leave_quota_days.max' => 'Kuota cuti maksimal :max hari.',
                'max_leave_days_per_month.integer' => 'Kuota cuti per bulan harus berupa angka bulat.',
                'max_leave_days_per_month.min' => 'Kuota cuti per bulan minimal :min hari.',
                'max_leave_days_per_month.max' => 'Kuota cuti per bulan maksimal :max hari.',
                'is_active.boolean' => 'Status aktif harus bernilai benar atau salah.',
            ]);

            if (isset($validated['name']) && $validated['name'] !== $team->name) {
                $validated['slug'] = Str::slug($validated['name']).'-'.time();
            }

            $team->update($validated);

            return response()->json([
                'success' => true,
                'data' => $team->fresh(),
                'message' => 'Team updated successfully',
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Periksa kembali data yang Anda masukkan.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Update team failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update team',
            ], 500);
        }
    }

    /**
     * Delete a team
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $team = Team::find($id);

            if (! $team) {
                return response()->json([
                    'success' => false,
                    'message' => 'Team not found',
                ], 404);
            }

            // Check if team has users
            if ($team->users()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete team with existing users. Please remove or reassign users first.',
                ], 422);
            }

            $team->delete();

            return response()->json([
                'success' => true,
                'message' => 'Team deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Delete team failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete team',
            ], 500);
        }
    }

    /**
     * Get team details with users
     */
    public function show(int $id): JsonResponse
    {
        try {
            $team = Team::with(['users' => function ($query) {
                $query->select('id', 'team_id', 'name', 'email', 'role');
            }])
                ->withCount(['users', 'managers', 'employees'])
                ->find($id);

            if (! $team) {
                return response()->json([
                    'success' => false,
                    'message' => 'Team not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $team,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get team details failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to get team details',
            ], 500);
        }
    }
}
