<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

class ImpersonateController extends Controller
{
    /**
     * Prefix of the name of an impersonation token. The id of the super admin who started the
     * impersonation is appended ("impersonation:12"). Token names are only ever set server-side,
     * so this is the trusted record of who is behind an impersonated session.
     */
    private const TOKEN_NAME_PREFIX = 'impersonation:';

    /**
     * Impersonate a user (Super admin only)
     */
    public function impersonate(Request $request, int $userId): JsonResponse
    {
        try {
            $superAdmin = auth()->user();

            // Only super admin can impersonate
            if (! $superAdmin->isSuperAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only super admin can impersonate users.',
                ], 403);
            }

            $targetUser = User::find($userId);

            if (! $targetUser) {
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

            // Create a new token for the target user. Its name records the impersonating super admin,
            // which is what stopImpersonate() trusts (never a client-supplied id).
            $token = $targetUser->createToken(self::TOKEN_NAME_PREFIX.$superAdmin->id, ['impersonated' => true])->plainTextToken;

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
            Log::error('Impersonate user failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to impersonate user',
            ], 500);
        }
    }

    /**
     * The id of the super admin behind an impersonation token, or null when the token is not one
     * (a regular login token, a transient/test token, a legacy "impersonation" token without id).
     */
    private function impersonatorId(mixed $token): ?int
    {
        if (! $token instanceof PersonalAccessToken
            || ! preg_match('/^'.preg_quote(self::TOKEN_NAME_PREFIX, '/').'(\d+)$/', (string) $token->name, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Stop impersonating and return to super admin
     */
    public function stopImpersonate(Request $request): JsonResponse
    {
        try {
            $currentUser = auth()->user();
            $currentToken = $request->user()->currentAccessToken();

            // The way back is only open to a session that really is an impersonation, and the
            // super admin is read from that token - never from the request body. Trusting a
            // client-supplied id would let any user swap their token for a super admin's.
            $superAdminId = $this->impersonatorId($currentToken);

            if ($superAdminId === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Not currently impersonating a user',
                ], 403);
            }

            // The SPA still sends "original_user_id"; if present it must agree with the token.
            $claimedId = $request->input('original_user_id');
            if ($claimedId !== null && $claimedId !== ''
                && (! is_scalar($claimedId) || (int) $claimedId !== $superAdminId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid original user',
                ], 403);
            }

            $superAdmin = User::find($superAdminId);

            if (! $superAdmin || ! $superAdmin->isSuperAdmin() || $superAdmin->is_disabled) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid original user',
                ], 403);
            }

            // Revoke the impersonation token
            $currentToken->delete();

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
            Log::error('Stop impersonate failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to stop impersonation',
            ], 500);
        }
    }
}
