<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\CreatesTeamUsers;

class AuthTest extends TestCase
{
    use CreatesTeamUsers, RefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
            'role' => UserRole::EMPLOYEE,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'captcha_token' => 'test-captcha-token',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'user' => ['id', 'name', 'email', 'role'],
                    'token',
                ],
                'message',
            ])
            ->assertJson(['success' => true]);
    }

    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
            'captcha_token' => 'test-captcha-token',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Login Gagal, pastikan email dan kata sandi benar!',
            ]);
    }

    public function test_user_cannot_login_with_nonexistent_email(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'password123',
            'captcha_token' => 'test-captcha-token',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Login Gagal, pastikan email dan kata sandi benar!',
            ]);
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create(['role' => UserRole::EMPLOYEE]);
        $token = $user->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/auth/logout', [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_login_validation_requires_email(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'password' => 'password123',
            'captcha_token' => 'test-captcha-token',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_validation_requires_password(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'captcha_token' => 'test-captcha-token',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_login_validation_requires_valid_email(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'invalid-email',
            'password' => 'password123',
            'captcha_token' => 'test-captcha-token',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_logout_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(401);
    }

    public function test_manager_can_login(): void
    {
        $manager = $this->createManager([
            'email' => 'manager@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'manager@example.com',
            'password' => 'password123',
            'captcha_token' => 'test-captcha-token',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.role', 'manager');
    }

    public function test_disabled_user_cannot_login(): void
    {
        $user = $this->createEmployee([
            'email' => 'disabled@example.com',
            'password' => Hash::make('password123'),
            'is_disabled' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'disabled@example.com',
            'password' => 'password123',
            'captcha_token' => 'test-captcha-token',
        ]);

        $response->assertStatus(403)
            ->assertJson(['success' => false])
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'dinonaktifkan'));

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_disabled_user_existing_token_is_blocked_and_revoked(): void
    {
        $user = $this->createEmployee(['is_disabled' => false]);
        $token = $user->createToken('auth-token')->plainTextToken;

        $user->update(['is_disabled' => true]);

        $response = $this->getJson('/api/v1/attendance/today', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'dinonaktifkan'));

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
