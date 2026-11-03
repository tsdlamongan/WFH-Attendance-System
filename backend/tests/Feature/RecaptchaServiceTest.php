<?php

use App\Services\RecaptchaService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const RECAPTCHA_VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

/**
 * Build the service as it behaves outside of the testing environment.
 */
function recaptchaService(string $secret = 'test-secret-key', string $environment = 'production'): RecaptchaService
{
    config(['services.recaptcha.secret_key' => $secret]);
    app()->instance('env', $environment);

    return new RecaptchaService;
}

beforeEach(function () {
    Http::preventStrayRequests();
});

describe('testing environment bypass', function () {
    it('accepts any non-empty token without calling Google', function () {
        Http::fake();
        config(['services.recaptcha.secret_key' => '']);

        expect(app()->environment('testing'))->toBeTrue()
            ->and((new RecaptchaService)->verify('any-token'))->toBeTrue();

        Http::assertNothingSent();
    });

    it('rejects an empty token even in the testing environment', function () {
        Http::fake();

        expect((new RecaptchaService)->verify(''))->toBeFalse();

        Http::assertNothingSent();
    });
});

describe('configuration guards', function () {
    it('fails and logs a warning when the secret key is not configured', function () {
        Http::fake();
        Log::spy();

        expect(recaptchaService('')->verify('some-token'))->toBeFalse();

        Log::shouldHaveReceived('warning')->with('reCAPTCHA secret key not configured')->once();
        Http::assertNothingSent();
    });

    it('fails and logs a warning instead of crashing when the secret key is not set at all', function () {
        Http::fake();
        Log::spy();
        config(['services.recaptcha.secret_key' => null]);
        app()->instance('env', 'production');

        expect((new RecaptchaService)->verify('some-token'))->toBeFalse();

        Log::shouldHaveReceived('warning')->with('reCAPTCHA secret key not configured')->once();
        Http::assertNothingSent();
    });

    it('can be built without a secret key in the testing environment', function () {
        Http::fake();
        config(['services.recaptcha.secret_key' => null]);

        expect((new RecaptchaService)->verify('any-token'))->toBeTrue();

        Http::assertNothingSent();
    });

    it('fails and logs a warning when the token is empty', function () {
        Http::fake();
        Log::spy();

        expect(recaptchaService()->verify(''))->toBeFalse();

        Log::shouldHaveReceived('warning')->with('reCAPTCHA token is empty')->once();
        Http::assertNothingSent();
    });
});

describe('Google verification', function () {
    it('returns true and posts the secret, token and client ip as a form when Google reports success', function () {
        Http::fake([
            RECAPTCHA_VERIFY_URL => Http::response([
                'success' => true,
                'challenge_ts' => '2026-03-12T10:00:00Z',
                'hostname' => 'localhost',
            ], 200),
        ]);
        Log::spy();

        expect(recaptchaService('my-secret')->verify('valid-token'))->toBeTrue();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            return $request->url() === RECAPTCHA_VERIFY_URL
                && $request->method() === 'POST'
                && $request->isForm()
                && $request['secret'] === 'my-secret'
                && $request['response'] === 'valid-token'
                && $request['remoteip'] === request()->ip();
        });

        Log::shouldHaveReceived('info')->with('Verifying reCAPTCHA token', ['token_length' => strlen('valid-token')])->once();
        Log::shouldNotHaveReceived('error');
    });

    it('returns false and logs the error codes when Google reports failure', function () {
        Http::fake([
            RECAPTCHA_VERIFY_URL => Http::response([
                'success' => false,
                'error-codes' => ['invalid-input-response'],
            ], 200),
        ]);
        Log::spy();

        expect(recaptchaService()->verify('bad-token'))->toBeFalse();

        Log::shouldHaveReceived('error')
            ->with('reCAPTCHA verification failed', ['error-codes' => ['invalid-input-response']])
            ->once();
    });

    it('logs "unknown" error codes when a failed response carries none', function () {
        Http::fake([
            RECAPTCHA_VERIFY_URL => Http::response(['success' => false], 200),
        ]);
        Log::spy();

        expect(recaptchaService()->verify('bad-token'))->toBeFalse();

        Log::shouldHaveReceived('error')
            ->with('reCAPTCHA verification failed', ['error-codes' => 'unknown'])
            ->once();
    });

    it('returns false when the response has no success field', function () {
        Http::fake([
            RECAPTCHA_VERIFY_URL => Http::response(['unexpected' => 'payload'], 200),
        ]);
        Log::spy();

        expect(recaptchaService()->verify('some-token'))->toBeFalse();

        Log::shouldHaveReceived('error')->with('reCAPTCHA verification failed', Mockery::type('array'))->once();
    });

    it('returns false and logs status and body when Google responds with an HTTP error', function () {
        Http::fake([
            RECAPTCHA_VERIFY_URL => Http::response('Service Unavailable', 503),
        ]);
        Log::spy();

        expect(recaptchaService()->verify('some-token'))->toBeFalse();

        Log::shouldHaveReceived('error')
            ->with('reCAPTCHA API request failed', ['status' => 503, 'body' => 'Service Unavailable'])
            ->once();
    });

    it('returns false and logs the exception when the connection fails', function () {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });
        Log::spy();

        expect(recaptchaService()->verify('some-token'))->toBeFalse();

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'reCAPTCHA verification exception')
                && str_contains($message, 'Operation timed out')
                && array_key_exists('exception', $context))
            ->once();
    });
});
