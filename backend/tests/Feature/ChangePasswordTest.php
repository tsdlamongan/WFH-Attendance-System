<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\CreatesTeamUsers;

class ChangePasswordTest extends TestCase
{
    use CreatesTeamUsers, RefreshDatabase;

    private User $user;

    private string $currentPassword = 'password123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'employee@example.com',
            'password' => Hash::make($this->currentPassword),
            'role' => UserRole::EMPLOYEE,
        ]);
    }

    public function test_employee_can_change_password_with_valid_credentials(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/change-password', [
            'current_password' => $this->currentPassword,
            'new_password' => 'newpassword123',
            'new_password_confirmation' => 'newpassword123',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Password berhasil diubah.',
            ]);

        // Verify password was actually changed
        $this->user->refresh();
        $this->assertTrue(Hash::check('newpassword123', $this->user->password));
        $this->assertFalse(Hash::check($this->currentPassword, $this->user->password));
    }

    public function test_change_password_logs_activity(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        $this->postJson('/api/v1/change-password', [
            'current_password' => $this->currentPassword,
            'new_password' => 'newpassword123',
            'new_password_confirmation' => 'newpassword123',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        // Check activity log was created
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->user->id,
            'action' => ActivityType::PASSWORD_CHANGED->value,
        ]);
    }

    public function test_change_password_fails_with_wrong_current_password(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/change-password', [
            'current_password' => 'wrongpassword',
            'new_password' => 'newpassword123',
            'new_password_confirmation' => 'newpassword123',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Password lama tidak sesuai.',
            ]);

        // Verify password was NOT changed
        $this->user->refresh();
        $this->assertTrue(Hash::check($this->currentPassword, $this->user->password));
    }

    public function test_change_password_fails_when_new_password_is_same_as_current(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/change-password', [
            'current_password' => $this->currentPassword,
            'new_password' => $this->currentPassword,
            'new_password_confirmation' => $this->currentPassword,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Password baru tidak boleh sama dengan password lama.',
            ]);
    }

    public function test_change_password_fails_with_password_mismatch(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/change-password', [
            'current_password' => $this->currentPassword,
            'new_password' => 'newpassword123',
            'new_password_confirmation' => 'differentpassword',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);
    }

    public function test_change_password_fails_with_short_password(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/change-password', [
            'current_password' => $this->currentPassword,
            'new_password' => 'short',
            'new_password_confirmation' => 'short',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);
    }

    public function test_change_password_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/change-password', [
            'current_password' => $this->currentPassword,
            'new_password' => 'newpassword123',
            'new_password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(401);
    }

    public function test_change_password_validation_requires_all_fields(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/change-password', [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'current_password',
                'new_password',
                'new_password_confirmation',
            ]);
    }

    public function test_manager_can_also_change_password(): void
    {
        $manager = User::factory()->create([
            'email' => 'manager@example.com',
            'password' => Hash::make('managerpass123'),
            'role' => UserRole::MANAGER,
        ]);

        $token = $manager->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/change-password', [
            'current_password' => 'managerpass123',
            'new_password' => 'newmanagerpass456',
            'new_password_confirmation' => 'newmanagerpass456',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        // Verify password was changed
        $manager->refresh();
        $this->assertTrue(Hash::check('newmanagerpass456', $manager->password));
    }

    // Note: Login tests omitted as they are covered in AuthTest.php
    // The password change functionality is verified by checking the password hash directly
}
