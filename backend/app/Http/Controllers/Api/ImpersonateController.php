<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ImpersonateController extends Controller
{
    /**
     * Impersonate a user (Super admin only)
     */
    public function impersonate(Request $request, int $userId): JsonResponse
    {
        try {
            $superAdmin = auth()->user();

            // Only super admin can impersonate
            if (!$superAdmin->isSuperAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only super admin can impersonate users.',
                ], 403);
            }

            $targetUser = User::find($userId);

            if (!$targetUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            // Cannot impersonate another super admin
            if ($targetUser->isSuperAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot impersonate another super admin',
                ], 403);
            }

            if ($targetUser->is_disabled) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak dapat impersonate user yang dinonaktifkan',
                ], 403);
            }

            // Store original user info in session/token
            // Create a new token for the target user
            $token = $targetUser->createToken('impersonation', ['impersonated' => true])->plainTextToken;

            Log::info('User impersonation', [
                'super_admin_id' => $superAdmin->id,
                'super_admin_email' => $superAdmin->email,
                'target_user_id' => $targetUser->id,
                'target_user_email' => $targetUser->email,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => new UserResource($targetUser),
                    'token' => $token,
                    'original_user_id' => $superAdmin->id,
                    'is_impersonating' => true,
                ],
                'message' => 'Successfully impersonating user',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Impersonate user failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to impersonate user',
            ], 500);
        }
    }

    /**
     * Stop impersonating and return to super admin
     */
    public function stopImpersonate(Request $request): JsonResponse
    {
        try {
            $currentUser = auth()->user();
            $originalUserId = $request->input('original_user_id');

            if (!$originalUserId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Original user ID not provided',
                ], 400);
            }

            $superAdmin = User::find($originalUserId);

            if (!$superAdmin || !$superAdmin->isSuperAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid original user',
                ], 403);
            }

            // Revoke current impersonation token if exists
            $currentToken = $request->user()->currentAccessToken();
            if ($currentToken) {
                $currentToken->delete();
            }

            // Create new token for super admin
            $token = $superAdmin->createToken('auth-token')->plainTextToken;

            Log::info('Stop impersonation', [
                'super_admin_id' => $superAdmin->id,
                'impersonated_user_id' => $currentUser->id,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => new UserResource($superAdmin),
                    'token' => $token,
                    'is_impersonating' => false,
                ],
                'message' => 'Stopped impersonating user',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Stop impersonate failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to stop impersonation',
            ], 500);
        }
    }
}
