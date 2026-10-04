<?php

use App\Enums\ActivityType;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\RecaptchaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

const AUTH_EDGE_LOGIN_URL = '/api/v1/auth/login';
const AUTH_EDGE_LOGOUT_URL = '/api/v1/auth/logout';
const AUTH_EDGE_PASSWORD_URL = '/api/v1/change-password';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function authEdgeLoginPayload(array $overrides = []): array
{
    return array_merge([
        'email' => 'user@example.com',
        'password' => 'password123',
        'captcha_token' => 'test-captcha-token',
    ], $overrides);
}

/**
 * @return array<string, string>
 */
function authEdgeBearer(User $user, string $name = 'auth-token'): array
{
    return ['Authorization' => 'Bearer '.$user->createToken($name)->plainTextToken];
}

/**
 * Make the activity logger blow up, to drive the controllers' catch blocks.
 */
function authEdgeBreakActivityLogger(): void
{
    $logger = Mockery::mock(ActivityLogService::class);
    $logger->shouldReceive('logActivity')->andThrow(new RuntimeException('activity store is down'));

    app()->instance(ActivityLogService::class, $logger);
}

/**
 * Behave like a brand-new HTTP request: the app instance is shared by every call
 * of a test, so drop the guards (and the default guard auth:sanctum switched) a previous request left behind.
 */
function authEdgeFreshRequest(): void
{
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
}

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------

it('rejects a login whose captcha verification fails, even with valid credentials', function () {
    $user = $this->createEmployee(['email' => 'user@example.com', 'password' => Hash::make('password123')]);

    $this->mock(RecaptchaService::class)
        ->shouldReceive('verify')
        ->once()
        ->with('forged-token')
        ->andReturn(false);

    $response = $this->postJson(AUTH_EDGE_LOGIN_URL, authEdgeLoginPayload(['captcha_token' => 'forged-token']));

    $response->assertUnprocessable()
        ->assertExactJson([
            'success' => false,
            'message' => 'Verifikasi reCAPTCHA gagal. Silakan coba lagi.',
            'errors' => ['captcha_token' => ['Verifikasi reCAPTCHA gagal. Silakan coba lagi.']],
        ]);

    // No session was opened: no token issued and no login audit entry.
    expect($user->tokens()->count())->toBe(0);
    $this->assertDatabaseMissing('activity_logs', ['user_id' => $user->id, 'action' => ActivityType::LOGIN->value]);
});

it('checks the captcha before the credentials so unknown accounts are not revealed', function () {
    $this->mock(RecaptchaService::class)->shouldReceive('verify')->once()->andReturn(false);

    $this->postJson(AUTH_EDGE_LOGIN_URL, authEdgeLoginPayload(['email' => 'nobody@example.com']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['captcha_token']);
});

it('never reaches the captcha service when the payload is invalid', function () {
    $this->mock(RecaptchaService::class)->shouldNotReceive('verify');

    $this->postJson(AUTH_EDGE_LOGIN_URL, authEdgeLoginPayload(['email' => 'not-an-email']))
        ->assertUnprocessable();
});

it('returns the localized validation messages for an incomplete login', function () {
    $response = $this->postJson(AUTH_EDGE_LOGIN_URL, []);

    $response->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Periksa kembali data yang Anda masukkan.')
        ->assertJsonPath('errors.email.0', 'Email wajib diisi.')
        ->assertJsonPath('errors.password.0', 'Kata sandi wajib diisi.')
        ->assertJsonPath('errors.captcha_token.0', 'Verifikasi captcha wajib dilakukan.');
});

it('rejects a login with a malformed email or a too short password', function () {
    $this->postJson(AUTH_EDGE_LOGIN_URL, authEdgeLoginPayload(['email' => 'nope', 'password' => 'short']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Format email tidak valid.')
        ->assertJsonPath('errors.password.0', 'Kata sandi minimal 8 karakter.');
});

it('rejects a captcha token that is not a string', function () {
    $this->postJson(AUTH_EDGE_LOGIN_URL, authEdgeLoginPayload(['captcha_token' => ['x']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['captcha_token']);
});

it('records a login audit entry with the client address and user agent', function () {
    $user = $this->createManager(['email' => 'user@example.com', 'password' => Hash::make('password123')]);

    $this->postJson(AUTH_EDGE_LOGIN_URL, authEdgeLoginPayload(), ['User-Agent' => 'PestAgent/1.0'])
        ->assertOk();

    $log = ActivityLog::where('user_id', $user->id)->where('action', ActivityType::LOGIN)->first();

    expect($log)->not->toBeNull()
        ->and($log->team_id)->toBe($user->team_id)
        ->and($log->description)->toBe('User logged in')
        ->and($log->ip_address)->toBe('127.0.0.1')
        ->and($log->user_agent)->toBe('PestAgent/1.0');
});

it('issues a usable token and does not expose the password hash on login', function () {
    $user = $this->createEmployee(['email' => 'user@example.com', 'password' => Hash::make('password123')]);

    $response = $this->postJson(AUTH_EDGE_LOGIN_URL, authEdgeLoginPayload());

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Login berhasil.')
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.role', 'employee');

    expect($response->getContent())->not->toContain('password"')
        ->and($response->getContent())->not->toContain($user->password);

    $this->assertDatabaseHas('personal_access_tokens', [
        'tokenable_id' => $user->id,
        'tokenable_type' => User::class,
        'name' => 'auth-token',
    ]);

    $this->getJson('/api/v1/attendance/today', ['Authorization' => 'Bearer '.$response->json('data.token')])
        ->assertOk();
});

it('opens a separate token for every login so several devices can stay signed in', function () {
    $user = $this->createEmployee(['email' => 'user@example.com', 'password' => Hash::make('password123')]);

    $first = $this->postJson(AUTH_EDGE_LOGIN_URL, authEdgeLoginPayload())->assertOk()->json('data.token');
    $second = $this->postJson(AUTH_EDGE_LOGIN_URL, authEdgeLoginPayload())->assertOk()->json('data.token');

    expect($first)->not->toBe($second)
        ->and($user->tokens()->count())->toBe(2);
});

it('does not issue a token or audit entry for a wrong password', function () {
    $user = $this->createEmployee(['email' => 'user@example.com', 'password' => Hash::make('password123')]);

    $this->postJson(AUTH_EDGE_LOGIN_URL, authEdgeLoginPayload(['password' => 'wrong-password']))
        ->assertUnauthorized()
        ->assertExactJson([
            'success' => false,
            'message' => 'Login Gagal, pastikan email dan kata sandi benar!',
        ]);

    expect($user->tokens()->count())->toBe(0);
    $this->assertDatabaseMissing('activity_logs', ['user_id' => $user->id, 'action' => ActivityType::LOGIN->value]);
});

it('revokes the existing tokens of a disabled account that tries to log in', function () {
    $user = $this->createEmployee([
        'email' => 'user@example.com',
        'password' => Hash::make('password123'),
        'is_disabled' => true,
    ]);
    $oldToken = $user->createToken('old-device');
    $user->createToken('another-device');
    expect($user->tokens()->count())->toBe(2);

    $this->postJson(AUTH_EDGE_LOGIN_URL, authEdgeLoginPayload())
        ->assertForbidden()
        ->assertExactJson([
            'success' => false,
            'message' => 'Akun anda telah dinonaktifkan. Silakan hubungi manager.',
        ]);

    expect($user->tokens()->count())->toBe(0);
    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $oldToken->accessToken->id]);
    $this->assertDatabaseMissing('activity_logs', ['user_id' => $user->id, 'action' => ActivityType::LOGIN->value]);
});

it('does not reveal that an account is disabled when the password is wrong', function () {
    $this->createEmployee([
        'email' => 'user@example.com',
        'password' => Hash::make('password123'),
        'is_disabled' => true,
    ]);

    $this->postJson(AUTH_EDGE_LOGIN_URL, authEdgeLoginPayload(['password' => 'wrong-password']))
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Login Gagal, pastikan email dan kata sandi benar!');
});

it('answers 500 and logs the error when login fails unexpectedly', function () {
    $this->createEmployee(['email' => 'user@example.com', 'password' => Hash::make('password123')]);
    authEdgeBreakActivityLogger();
    Log::spy();

    $response = $this->postJson(AUTH_EDGE_LOGIN_URL, authEdgeLoginPayload());

    $response->assertStatus(500)
        ->assertExactJson([
            'success' => false,
            'message' => 'Gagal login. Silakan coba lagi.',
        ]);
    expect($response->getContent())->not->toContain('activity store is down');

    Log::shouldHaveReceived('error')->with('Login failed: activity store is down')->once();
});

// ---------------------------------------------------------------------------
// Logout
// ---------------------------------------------------------------------------

it('revokes only the token used to log out and keeps the other devices signed in', function () {
    $user = $this->createEmployee();
    $current = $user->createToken('auth-token');
    $otherDevice = $user->createToken('auth-token');

    $this->postJson(AUTH_EDGE_LOGOUT_URL, [], ['Authorization' => 'Bearer '.$current->plainTextToken])
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'Logout berhasil.',
        ]);

    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $current->accessToken->id]);
    $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherDevice->accessToken->id]);
    expect($user->tokens()->count())->toBe(1);
});

it('rejects a token that was already used to log out', function () {
    $user = $this->createEmployee();
    $token = $user->createToken('auth-token')->plainTextToken;

    $this->postJson(AUTH_EDGE_LOGOUT_URL, [], ['Authorization' => "Bearer {$token}"])->assertOk();

    authEdgeFreshRequest();

    $this->getJson('/api/v1/attendance/today', ['Authorization' => "Bearer {$token}"])
        ->assertUnauthorized();
});

it('writes a logout audit entry for the user', function () {
    $user = $this->createManager();

    $this->postJson(AUTH_EDGE_LOGOUT_URL, [], authEdgeBearer($user))->assertOk();

    $log = ActivityLog::where('user_id', $user->id)->where('action', ActivityType::LOGOUT)->first();

    expect($log)->not->toBeNull()
        ->and($log->team_id)->toBe($user->team_id)
        ->and($log->description)->toBe('User logged out');
});

it('refuses to log out with an invalid bearer token', function () {
    $this->postJson(AUTH_EDGE_LOGOUT_URL, [], ['Authorization' => 'Bearer 1|not-a-real-token'])
        ->assertUnauthorized();
});

it('blocks a disabled user from logging out through the normal flow and revokes their tokens', function () {
    $user = $this->createEmployee(['is_disabled' => true]);
    $headers = authEdgeBearer($user);

    $this->postJson(AUTH_EDGE_LOGOUT_URL, [], $headers)
        ->assertForbidden()
        ->assertJsonPath('success', false);

    expect($user->tokens()->count())->toBe(0);
    $this->assertDatabaseMissing('activity_logs', ['user_id' => $user->id, 'action' => ActivityType::LOGOUT->value]);
});

it('answers 500 and logs the error when logout fails unexpectedly', function () {
    $user = $this->createEmployee();
    authEdgeBreakActivityLogger();
    Log::spy();

    $response = $this->postJson(AUTH_EDGE_LOGOUT_URL, [], authEdgeBearer($user));

    $response->assertStatus(500)
        ->assertExactJson([
            'success' => false,
            'message' => 'Gagal logout. Silakan coba lagi.',
        ]);
    expect($response->getContent())->not->toContain('activity store is down');

    Log::shouldHaveReceived('error')->with('Logout failed: activity store is down')->once();
});

it('treats a logout without any authenticated user as a harmless no-op', function () {
    // Unreachable through the route (auth:sanctum answers 401 first), but the
    // controller must still not crash or write an audit row when called bare.
    $response = app(LogoutController::class)->logout(Request::create(AUTH_EDGE_LOGOUT_URL, 'POST'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe([
            'success' => true,
            'message' => 'Logout berhasil.',
        ]);
    expect(ActivityLog::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Change password
// ---------------------------------------------------------------------------

it('lets the user sign in with the new password and not with the old one after changing it', function () {
    $user = $this->createEmployee(['email' => 'user@example.com', 'password' => Hash::make('password123')]);

    $this->postJson(AUTH_EDGE_PASSWORD_URL, [
        'current_password' => 'password123',
        'new_password' => 'brand-new-pass',
        'new_password_confirmation' => 'brand-new-pass',
    ], authEdgeBearer($user))->assertOk();

    authEdgeFreshRequest();

    $this->postJson(AUTH_EDGE_LOGIN_URL, authEdgeLoginPayload(['password' => 'password123']))
        ->assertUnauthorized();

    $this->postJson(AUTH_EDGE_LOGIN_URL, authEdgeLoginPayload(['password' => 'brand-new-pass']))
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id);
});

it('does not touch the password or write a password audit entry when the current password is wrong', function () {
    $user = $this->createEmployee(['password' => Hash::make('password123')]);
    $hashBefore = $user->password;

    $this->postJson(AUTH_EDGE_PASSWORD_URL, [
        'current_password' => 'not-my-password',
        'new_password' => 'brand-new-pass',
        'new_password_confirmation' => 'brand-new-pass',
    ], authEdgeBearer($user))
        ->assertUnprocessable()
        ->assertJsonPath('errors.current_password.0', 'Password lama tidak sesuai.')
        ->assertJsonPath('success', false);

    expect($user->fresh()->password)->toBe($hashBefore);
    $this->assertDatabaseMissing('activity_logs', ['user_id' => $user->id, 'action' => ActivityType::PASSWORD_CHANGED->value]);
});

it('reports the reused password on the new_password field and keeps the old hash', function () {
    $user = $this->createEmployee(['password' => Hash::make('password123')]);
    $hashBefore = $user->password;

    $this->postJson(AUTH_EDGE_PASSWORD_URL, [
        'current_password' => 'password123',
        'new_password' => 'password123',
        'new_password_confirmation' => 'password123',
    ], authEdgeBearer($user))
        ->assertUnprocessable()
        ->assertJsonPath('errors.new_password.0', 'Password baru tidak boleh sama dengan password lama.');

    expect($user->fresh()->password)->toBe($hashBefore);
    $this->assertDatabaseMissing('activity_logs', ['user_id' => $user->id, 'action' => ActivityType::PASSWORD_CHANGED->value]);
});

it('returns the localized validation messages for an incomplete password change', function () {
    $user = $this->createEmployee();

    $this->postJson(AUTH_EDGE_PASSWORD_URL, [], authEdgeBearer($user))
        ->assertUnprocessable()
        ->assertJsonPath('errors.current_password.0', 'Password lama wajib diisi.')
        ->assertJsonPath('errors.new_password.0', 'Password baru wajib diisi.')
        ->assertJsonPath('errors.new_password_confirmation.0', 'Konfirmasi password wajib diisi.');
});

it('enforces the minimum and confirmation rules of the new password with localized messages', function () {
    $user = $this->createEmployee(['password' => Hash::make('password123')]);

    $this->postJson(AUTH_EDGE_PASSWORD_URL, [
        'current_password' => 'password123',
        'new_password' => 'short',
        'new_password_confirmation' => 'different',
    ], authEdgeBearer($user))
        ->assertUnprocessable()
        ->assertJsonFragment(['Password baru minimal 8 karakter.'])
        ->assertJsonFragment(['Konfirmasi password tidak cocok.']);
});

it('accepts a new password of exactly eight characters', function () {
    $user = $this->createEmployee(['password' => Hash::make('password123')]);

    $this->postJson(AUTH_EDGE_PASSWORD_URL, [
        'current_password' => 'password123',
        'new_password' => 'abcdefgh',
        'new_password_confirmation' => 'abcdefgh',
    ], authEdgeBearer($user))->assertOk();

    expect(Hash::check('abcdefgh', $user->fresh()->password))->toBeTrue();
});

it('rejects a non-string current password at validation time', function () {
    $user = $this->createEmployee();

    $this->postJson(AUTH_EDGE_PASSWORD_URL, [
        'current_password' => ['password123'],
        'new_password' => 'brand-new-pass',
        'new_password_confirmation' => 'brand-new-pass',
    ], authEdgeBearer($user))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);
});

it('stores the password change audit entry with the client address', function () {
    $user = $this->createManager(['password' => Hash::make('password123')]);

    $this->postJson(AUTH_EDGE_PASSWORD_URL, [
        'current_password' => 'password123',
        'new_password' => 'brand-new-pass',
        'new_password_confirmation' => 'brand-new-pass',
    ], authEdgeBearer($user) + ['User-Agent' => 'PestAgent/2.0'])->assertOk();

    $log = ActivityLog::where('user_id', $user->id)->where('action', ActivityType::PASSWORD_CHANGED)->first();

    expect($log)->not->toBeNull()
        ->and($log->team_id)->toBe($user->team_id)
        ->and($log->description)->toBe('User changed their password')
        ->and($log->ip_address)->toBe('127.0.0.1')
        ->and($log->user_agent)->toBe('PestAgent/2.0');
});

it('blocks a disabled user from changing the password', function () {
    $user = $this->createEmployee(['is_disabled' => true, 'password' => Hash::make('password123')]);
    $hashBefore = $user->password;

    $this->postJson(AUTH_EDGE_PASSWORD_URL, [
        'current_password' => 'password123',
        'new_password' => 'brand-new-pass',
        'new_password_confirmation' => 'brand-new-pass',
    ], authEdgeBearer($user))->assertForbidden();

    expect($user->fresh()->password)->toBe($hashBefore);
});

it('answers 500 and logs the error when changing the password fails unexpectedly', function () {
    $user = $this->createEmployee(['password' => Hash::make('password123')]);
    $headers = authEdgeBearer($user);
    authEdgeBreakActivityLogger();
    Log::spy();

    $response = $this->postJson(AUTH_EDGE_PASSWORD_URL, [
        'current_password' => 'password123',
        'new_password' => 'brand-new-pass',
        'new_password_confirmation' => 'brand-new-pass',
    ], $headers);

    $response->assertStatus(500)
        ->assertExactJson([
            'success' => false,
            'message' => 'Gagal mengubah password. Silakan coba lagi.',
        ]);
    expect($response->getContent())->not->toContain('activity store is down');

    Log::shouldHaveReceived('error')->with('Change password failed: activity store is down')->once();
});
