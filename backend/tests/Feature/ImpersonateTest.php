<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Super admin can impersonate manager.
     */
    public function test_super_admin_can_impersonate_manager(): void
    {
        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
            'team_id' => null,
            'leave_quota_days' => 0,
        ]);

        $team = Team::create([
            'name' => 'Team A',
            'slug' => 'team-a',
            'required_work_hours' => 7.0,
            'default_leave_quota_days' => 12,
            'max_leave_days_per_month' => 5,
            'is_active' => true,
        ]);

        $manager = User::create([
            'name' => 'Manager',
            'email' => 'manager@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::MANAGER,
            'team_id' => $team->id,
            'leave_quota_days' => 12,
        ]);

        $response = $this->actingAs($superAdmin)->postJson("/api/v1/super-admin/impersonate/{$manager->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Successfully impersonating user',
        ]);
        $response->assertJsonStructure([
            'data' => [
                'user',
                'token',
                'original_user_id',
                'is_impersonating',
            ],
        ]);
        $response->assertJsonPath('data.original_user_id', $superAdmin->id);
        $response->assertJsonPath('data.is_impersonating', true);
    }

    /**
     * Super admin can impersonate employee.
     */
    public function test_super_admin_can_impersonate_employee(): void
    {
        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
            'team_id' => null,
            'leave_quota_days' => 0,
        ]);

        $team = Team::create([
            'name' => 'Team A',
            'slug' => 'team-a',
            'required_work_hours' => 7.0,
            'default_leave_quota_days' => 12,
            'max_leave_days_per_month' => 5,
            'is_active' => true,
        ]);

        $employee = User::create([
            'name' => 'Employee',
            'email' => 'employee@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::EMPLOYEE,
            'team_id' => $team->id,
            'leave_quota_days' => 12,
        ]);

        $response = $this->actingAs($superAdmin)->postJson("/api/v1/super-admin/impersonate/{$employee->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);
    }

    /**
     * Super admin cannot impersonate another super admin.
     */
    public function test_super_admin_cannot_impersonate_another_super_admin(): void
    {
        $superAdmin1 = User::create([
            'name' => 'Super Admin 1',
            'email' => 'admin1@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
            'team_id' => null,
            'leave_quota_days' => 0,
        ]);

        $superAdmin2 = User::create([
            'name' => 'Super Admin 2',
            'email' => 'admin2@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
            'team_id' => null,
            'leave_quota_days' => 0,
        ]);

        $response = $this->actingAs($superAdmin1)->postJson("/api/v1/super-admin/impersonate/{$superAdmin2->id}");

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'Cannot impersonate another super admin',
        ]);
    }

    /**
     * Manager cannot impersonate.
     */
    public function test_manager_cannot_impersonate(): void
    {
        $team = Team::create([
            'name' => 'Team A',
            'slug' => 'team-a',
            'required_work_hours' => 7.0,
            'default_leave_quota_days' => 12,
            'max_leave_days_per_month' => 5,
            'is_active' => true,
        ]);

        $manager = User::create([
            'name' => 'Manager',
            'email' => 'manager@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::MANAGER,
            'team_id' => $team->id,
            'leave_quota_days' => 12,
        ]);

        $employee = User::create([
            'name' => 'Employee',
            'email' => 'employee@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::EMPLOYEE,
            'team_id' => $team->id,
            'leave_quota_days' => 12,
        ]);

        $response = $this->actingAs($manager)->postJson("/api/v1/super-admin/impersonate/{$employee->id}");

        $response->assertStatus(403);
    }

    /**
     * Employee cannot impersonate.
     */
    public function test_employee_cannot_impersonate(): void
    {
        $team = Team::create([
            'name' => 'Team A',
            'slug' => 'team-a',
            'required_work_hours' => 7.0,
            'default_leave_quota_days' => 12,
            'max_leave_days_per_month' => 5,
            'is_active' => true,
        ]);

        $employee1 = User::create([
            'name' => 'Employee 1',
            'email' => 'employee1@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::EMPLOYEE,
            'team_id' => $team->id,
            'leave_quota_days' => 12,
        ]);

        $employee2 = User::create([
            'name' => 'Employee 2',
            'email' => 'employee2@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::EMPLOYEE,
            'team_id' => $team->id,
            'leave_quota_days' => 12,
        ]);

        $response = $this->actingAs($employee1)->postJson("/api/v1/super-admin/impersonate/{$employee2->id}");

        $response->assertStatus(403);
    }

    /**
     * Super admin cannot impersonate non-existent user.
     */
    public function test_super_admin_cannot_impersonate_nonexistent_user(): void
    {
        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
            'team_id' => null,
            'leave_quota_days' => 0,
        ]);

        $response = $this->actingAs($superAdmin)->postJson('/api/v1/super-admin/impersonate/99999');

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'User not found',
        ]);
    }

    /**
     * Impersonated user can stop impersonating.
     */
    public function test_impersonated_user_can_stop_impersonate(): void
    {
        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
            'team_id' => null,
            'leave_quota_days' => 0,
        ]);

        $team = Team::create([
            'name' => 'Team A',
            'slug' => 'team-a',
            'required_work_hours' => 7.0,
            'default_leave_quota_days' => 12,
            'max_leave_days_per_month' => 5,
            'is_active' => true,
        ]);

        $manager = User::create([
            'name' => 'Manager',
            'email' => 'manager@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::MANAGER,
            'team_id' => $team->id,
            'leave_quota_days' => 12,
        ]);

        // First, impersonate the manager
        $impersonateResponse = $this->actingAs($superAdmin)
            ->postJson("/api/v1/super-admin/impersonate/{$manager->id}");

        $impersonateResponse->assertStatus(200);

        // Now act as the impersonated user and stop impersonation
        $response = $this->actingAs($manager)
            ->postJson('/api/v1/super-admin/stop-impersonate', [
                'original_user_id' => $superAdmin->id,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Stopped impersonating user',
        ]);
        $response->assertJsonPath('data.is_impersonating', false);
        $response->assertJsonPath('data.user.role', 'super_admin');
    }

    public function test_super_admin_cannot_impersonate_disabled_user(): void
    {
        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
            'team_id' => null,
            'leave_quota_days' => 0,
        ]);

        $team = Team::create([
            'name' => 'Team A',
            'slug' => 'team-a',
            'required_work_hours' => 7.0,
            'default_leave_quota_days' => 12,
            'max_leave_days_per_month' => 5,
            'is_active' => true,
        ]);

        $disabled = User::create([
            'name' => 'Disabled User',
            'email' => 'disabled@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::EMPLOYEE,
            'team_id' => $team->id,
            'leave_quota_days' => 12,
            'is_disabled' => true,
        ]);

        $response = $this->actingAs($superAdmin)
            ->postJson("/api/v1/super-admin/impersonate/{$disabled->id}");

        $response->assertStatus(403)
            ->assertJson(['success' => false])
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'dinonaktifkan'));
    }
}
