<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RecaptchaService
{
    private ?string $secretKey;

    private string $verifyUrl;

    public function __construct()
    {
        $this->secretKey = config('services.recaptcha.secret_key');
        $this->verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
    }

    /**
     * Verify reCAPTCHA token
     */
    public function verify(string $token): bool
    {
        // Skip verification in testing environment
        if (app()->environment('testing')) {
            return ! empty($token);
        }

        if (empty($this->secretKey)) {
            Log::warning('reCAPTCHA secret key not configured');

            return false;
        }

        if (empty($token)) {
            Log::warning('reCAPTCHA token is empty');

            return false;
        }

        try {
            Log::info('Verifying reCAPTCHA token', ['token_length' => strlen($token)]);

            $response = Http::asForm()
                ->timeout(10)
                ->post($this->verifyUrl, [
                    'secret' => $this->secretKey,
                    'response' => $token,
                    'remoteip' => request()->ip(),
                ]);

            if (! $response->successful()) {
                Log::error('reCAPTCHA API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            $result = $response->json();

            Log::info('reCAPTCHA verification result', [
                'success' => $result['success'] ?? false,
                'challenge_ts' => $result['challenge_ts'] ?? null,
                'hostname' => $result['hostname'] ?? null,
                'error-codes' => $result['error-codes'] ?? [],
            ]);

            // For reCAPTCHA v2 (checkbox), we only check success
            // For reCAPTCHA v3 (invisible), we would also check score
            $success = $result['success'] ?? false;

            // Log error codes if verification failed
            (! $success) && Log::error('reCAPTCHA verification failed', [
                'error-codes' => $result['error-codes'] ?? 'unknown',
            ]);

            return $success;
        } catch (\Exception $e) {
            Log::error('reCAPTCHA verification exception: '.$e->getMessage(), [
                'exception' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
