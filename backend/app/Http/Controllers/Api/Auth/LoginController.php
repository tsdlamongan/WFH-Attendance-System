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
            'email' => 'required|email',
            'password' => 'required|min:8',
            'captcha_token' => 'required|string',
        ], [
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'password.required' => 'Kata sandi wajib diisi.',
            'password.min' => 'Kata sandi minimal :min karakter.',
            'captcha_token.required' => 'Verifikasi captcha wajib dilakukan.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Periksa kembali data yang Anda masukkan.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Verify reCAPTCHA
        $captchaToken = $request->input('captcha_token');
        if (! $this->recaptchaService->verify($captchaToken)) {
            return response()->json([
                'success' => false,
                'message' => 'Verifikasi reCAPTCHA gagal. Silakan coba lagi.',
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
                    'User logged in',
                    $request
                );

                return response()->json([
                    'success' => true,
                    'data' => [
                        'user' => new UserResource($user),
                        'token' => $token,
                    ],
                    'message' => 'Login berhasil.',
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Login Gagal, pastikan email dan kata sandi benar!',
            ], 401);
        } catch (\Exception $e) {
            Log::error('Login failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal login. Silakan coba lagi.',
            ], 500);
        }
    }
}
