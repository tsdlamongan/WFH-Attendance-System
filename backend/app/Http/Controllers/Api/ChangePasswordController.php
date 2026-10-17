<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class ChangePasswordController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLogService
    ) {}

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();

            // Verify current password
            if (! Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Password lama tidak sesuai.',
                    'errors' => [
                        'current_password' => ['Password lama tidak sesuai.'],
                    ],
                ], 422);
            }

            // Check if new password is same as current password
            if (Hash::check($request->new_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Password baru tidak boleh sama dengan password lama.',
                    'errors' => [
                        'new_password' => ['Password baru tidak boleh sama dengan password lama.'],
                    ],
                ], 422);
            }

            // Update password
            $user->password = Hash::make($request->new_password);
            $user->save();

            // Log activity
            $this->activityLogService->logActivity(
                $user,
                ActivityType::PASSWORD_CHANGED,
                'User changed their password',
                $request
            );

            return response()->json([
                'success' => true,
                'message' => 'Password berhasil diubah.',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Change password failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengubah password. Silakan coba lagi.',
            ], 500);
        }
    }
}
