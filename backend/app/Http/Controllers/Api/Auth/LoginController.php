<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Repositories\UserRepository;
use App\Services\ActivityLogService;
use App\Services\RecaptchaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class LoginController extends Controller
{
    public function __construct(
        private UserRepository $userRepository,
        private ActivityLogService $activityLogService,
        private RecaptchaService $recaptchaService
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'password' => 'required|min:8',
            'captcha_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Verify reCAPTCHA
        $captchaToken = $request->input('captcha_token');
        if (!$this->recaptchaService->verify($captchaToken)) {
            return response()->json([
                'success' => false,
                'message' => 'reCAPTCHA verification failed',
                'errors' => ['captcha_token' => ['Verifikasi reCAPTCHA gagal. Silakan coba lagi.']],
            ], 422);
        }

        try {
            if (Auth::attempt($request->only('email', 'password'))) {
                $user = Auth::user();

                if ($user->is_disabled) {
                    Auth::logout();
                    $user->tokens()->delete();

                    return response()->json([
                        'success' => false,
                        'message' => 'Akun anda telah dinonaktifkan. Silakan hubungi manager.',
                    ], 403);
                }

                $token = $user->createToken('auth-token')->plainTextToken;

                $this->activityLogService->logActivity(
                    $user,
                    ActivityType::LOGIN,
                    "User logged in",
                    $request
                );

                return response()->json([
                    'success' => true,
                    'data' => [
                        'user' => new UserResource($user),
                        'token' => $token,
                    ],
                    'message' => 'Login successful',
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401);
        } catch (\Exception $e) {
            Log::error('Login failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to login. Please try again.',
            ], 500);
        }
    }
}
