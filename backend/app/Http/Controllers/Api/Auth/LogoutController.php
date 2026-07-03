<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class LogoutController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLogService
    ) {}

    public function logout(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            if ($user) {
                // Revoke current token
                $request->user()->currentAccessToken()->delete();

                $this->activityLogService->logActivity(
                    $user,
                    ActivityType::LOGOUT,
                    'User logged out',
                    $request
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Logout berhasil.',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Logout failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal logout. Silakan coba lagi.',
            ], 500);
        }
    }
}
