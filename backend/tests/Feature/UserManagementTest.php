<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\CreatesTeamUsers;

class UserManagementTest extends TestCase
{
    use CreatesTeamUsers, RefreshDatabase;

    private User $manager;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->createManager([
            'name' => 'Test Manager',
            'email' => 'manager@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->employee = $this->createEmployee([
            'name' => 'Test Employee',
            'email' => 'employee@example.com',
        ]);
    }

    public function test_manager_can_list_all_users(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/users', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'email',
                        'role',
                    ],
                ],
                'pagination' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                    'from',
                    'to',
                ],
            ])
            ->assertJson(['success' => true]);
    }

    public function test_manager_can_list_users_with_pagination(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create additional users
        User::factory()->count(15)->create(['role' => UserRole::EMPLOYEE]);

        $response = $this->getJson('/api/v1/manager/users?page=1&per_page=10', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('pagination.per_page', 10)
            ->assertJsonPath('pagination.current_page', 1);
    }

    public function test_manager_can_change_per_page_value(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create additional users
        User::factory()->count(60)->create(['role' => UserRole::EMPLOYEE]);

        $response = $this->getJson('/api/v1/manager/users?page=1&per_page=50', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('pagination.per_page', 50);
    }

    public function test_pagination_validates_per_page_values(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Test with invalid per_page value (should default to 10)
        $response = $this->getJson('/api/v1/manager/users?per_page=999', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('pagination.per_page', 10);
    }

    public function test_manager_can_create_user(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/manager/users', [
            'name' => 'New Employee',
            'email' => 'newemployee@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role' => 'employee',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'name',
                    'email',
                    'role',
                ],
                'message',
            ])
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('users', [
            'email' => 'newemployee@example.com',
            'role' => UserRole::EMPLOYEE->value,
        ]);
    }

    public function test_manager_can_create_manager_user(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/manager/users', [
            'name' => 'New Manager',
            'email' => 'newmanager@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role' => 'manager',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('users', [
            'email' => 'newmanager@example.com',
            'role' => UserRole::MANAGER->value,
        ]);
    }

    public function test_manager_can_update_user(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/users/{$this->employee->id}", [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
            'role' => 'employee',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('users', [
            'id' => $this->employee->id,
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ]);
    }

    public function test_manager_can_update_user_password(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/users/{$this->employee->id}", [
            'name' => $this->employee->name,
            'email' => $this->employee->email,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
            'role' => 'employee',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_manager_can_delete_user(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $userToDelete = $this->createEmployee();

        $response = $this->deleteJson("/api/v1/manager/users/{$userToDelete->id}", [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('users', [
            'id' => $userToDelete->id,
        ]);
    }

    public function test_manager_cannot_create_user_with_duplicate_email(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/manager/users', [
            'name' => 'New Employee',
            'email' => $this->employee->email,
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role' => 'employee',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422);
    }

    public function test_manager_cannot_create_user_with_invalid_password(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/manager/users', [
            'name' => 'New Employee',
            'email' => 'new@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
            'role' => 'employee',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422);
    }

    public function test_employee_cannot_access_user_management(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/users', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_manager_can_search_users_by_name(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create users with specific names
        $this->createEmployee([
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
        ]);

        $this->createEmployee([
            'name' => 'Jane Smith',
            'email' => 'jane.smith@example.com',
        ]);

        $this->createEmployee([
            'name' => 'Johnny Walker',
            'email' => 'johnny.walker@example.com',
        ]);

        // Search for "john" should return John Doe and Johnny Walker
        $response = $this->getJson('/api/v1/manager/users/search?q=john', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'John Doe'])
            ->assertJsonFragment(['name' => 'Johnny Walker']);
    }

    public function test_manager_search_returns_limited_results(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create 15 users with "Test" in their name
        for ($i = 1; $i <= 15; $i++) {
            $this->createEmployee([
                'name' => "Test User {$i}",
                'email' => "testuser{$i}@example.com",
            ]);
        }

        // Search with limit of 5
        $response = $this->getJson('/api/v1/manager/users/search?q=test&limit=5', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonCount(5, 'data');
    }

    public function test_manager_search_returns_empty_array_for_empty_query(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/users/search?q=', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonCount(0, 'data');
    }

    public function test_manager_search_returns_empty_array_for_no_matches(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/users/search?q=nonexistentuser12345', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonCount(0, 'data');
    }

    public function test_manager_search_is_case_insensitive(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $this->createEmployee([
            'name' => 'Alice Johnson',
            'email' => 'alice@example.com',
        ]);

        // Search with lowercase
        $response = $this->getJson('/api/v1/manager/users/search?q=alice', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['name' => 'Alice Johnson']);

        // Search with uppercase
        $response = $this->getJson('/api/v1/manager/users/search?q=ALICE', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['name' => 'Alice Johnson']);
    }

    public function test_employee_cannot_search_users(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/users/search?q=test', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_search_users(): void
    {
        $response = $this->getJson('/api/v1/manager/users/search?q=test');

        $response->assertStatus(401);
    }

    public function test_manager_can_disable_user(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;
        $employeeToken = $this->employee->createToken('auth-token')->plainTextToken;

        $response = $this->patchJson("/api/v1/manager/users/{$this->employee->id}/toggle-disabled", [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.is_disabled', true);

        $this->assertDatabaseHas('users', ['id' => $this->employee->id, 'is_disabled' => true]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $this->employee->id]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->manager->id,
            'action' => 'user_disabled',
        ]);
    }

    public function test_manager_can_enable_disabled_user(): void
    {
        $disabled = $this->createDisabledEmployee();
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->patchJson("/api/v1/manager/users/{$disabled->id}/toggle-disabled", [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_disabled', false);

        $this->assertDatabaseHas('users', ['id' => $disabled->id, 'is_disabled' => false]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->manager->id,
            'action' => 'user_enabled',
        ]);
    }

    public function test_manager_cannot_disable_self(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->patchJson("/api/v1/manager/users/{$this->manager->id}/toggle-disabled", [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'diri sendiri'));
    }

    public function test_cannot_disable_super_admin(): void
    {
        $superAdmin = User::factory()->create([
            'role' => UserRole::SUPER_ADMIN,
            'team_id' => null,
        ]);
        $superAdminToken = $superAdmin->createToken('auth-token')->plainTextToken;

        $otherSuperAdmin = User::factory()->create([
            'role' => UserRole::SUPER_ADMIN,
            'team_id' => null,
        ]);

        $response = $this->patchJson("/api/v1/manager/users/{$otherSuperAdmin->id}/toggle-disabled", [], [
            'Authorization' => "Bearer {$superAdminToken}",
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'super admin'));
    }

    public function test_manager_cannot_toggle_user_from_different_team(): void
    {
        $otherTeam = $this->createTeam();
        $otherUser = User::factory()->create([
            'team_id' => $otherTeam->id,
            'role' => UserRole::EMPLOYEE,
        ]);
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->patchJson("/api/v1/manager/users/{$otherUser->id}/toggle-disabled", [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_user_list_index_includes_disabled_users(): void
    {
        $this->createDisabledEmployee(['name' => 'Disabled Person']);
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/users', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['name' => 'Disabled Person']);
    }

    public function test_user_search_excludes_disabled_users(): void
    {
        $this->createDisabledEmployee(['name' => 'Disabled Carl']);
        $this->createEmployee(['name' => 'Active Carl']);
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/users/search?q=Carl', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['name' => 'Active Carl'])
            ->assertJsonMissing(['name' => 'Disabled Carl']);
    }
}
