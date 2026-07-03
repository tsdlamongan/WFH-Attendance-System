<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class RegisterController extends Controller
{
    /**
     * Register a new manager with their team.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        // Double check registration is enabled
        if (! Config::get('app.registration.enabled', true)) {
            return response()->json([
                'success' => false,
                'message' => 'Pendaftaran akun baru sedang dinonaktifkan.',
                'errors' => [
                    'registration' => ['Pendaftaran akun baru sedang dinonaktifkan'],
                ],
            ], 403);
        }

        try {
            DB::beginTransaction();

            // Create team
            $team = Team::create([
                'name' => $request->team_name,
                'description' => $request->team_description,
                'required_work_hours' => $request->required_work_hours ?? Team::DEFAULT_REQUIRED_WORK_HOURS,
                'default_leave_quota_days' => $request->default_leave_quota_days ?? Team::DEFAULT_LEAVE_QUOTA_DAYS,
                'max_leave_days_per_month' => $request->max_leave_days_per_month ?? Team::DEFAULT_MAX_LEAVE_DAYS_PER_MONTH,
                'is_active' => true,
            ]);

            // Create manager user
            $user = User::create([
                'team_id' => $team->id,
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => UserRole::MANAGER,
                'leave_quota_days' => $team->default_leave_quota_days,
            ]);

            // Generate token
            $token = $user->createToken('auth_token')->plainTextToken;

            DB::commit();

            Log::info('New manager registered', [
                'user_id' => $user->id,
                'team_id' => $team->id,
                'email' => $user->email,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => new UserResource($user->load('team')),
                    'token' => $token,
                    'team' => [
                        'id' => $team->id,
                        'name' => $team->name,
                        'slug' => $team->slug,
                        'required_work_hours' => $team->required_work_hours,
                        'default_leave_quota_days' => $team->default_leave_quota_days,
                        'max_leave_days_per_month' => $team->max_leave_days_per_month,
                    ],
                ],
                'message' => 'Registrasi berhasil! Tim Anda telah dibuat.',
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Registration failed: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Registrasi gagal. Silakan coba lagi.',
            ], 500);
        }
    }
}
