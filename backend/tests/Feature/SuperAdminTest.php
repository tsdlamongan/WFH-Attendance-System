<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test super admin can be created without team.
     */
    public function test_super_admin_can_be_created_without_team(): void
    {
        $superAdmin = User::create([
            'team_id' => null,
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
            'leave_quota_days' => 0,
        ]);

        $this->assertNull($superAdmin->team_id);
        $this->assertTrue($superAdmin->isSuperAdmin());
        $this->assertFalse($superAdmin->isManager());
        $this->assertFalse($superAdmin->isEmployee());
    }

    /**
     * Test super admin can bypass team access middleware.
     */
    public function test_super_admin_can_bypass_team_access_middleware(): void
    {
        $team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'is_active' => true,
            'required_work_hours' => 7.0,
            'default_leave_quota_days' => 12,
            'max_leave_days_per_month' => 5,
        ]);

        User::create([
            'team_id' => $team->id,
            'name' => 'Employee',
            'email' => 'employee@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::EMPLOYEE,
            'leave_quota_days' => 12,
        ]);

        $superAdmin = User::create([
            'team_id' => null,
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
            'leave_quota_days' => 0,
        ]);

        // Super admin should be able to access manager routes without team
        $response = $this->actingAs($superAdmin)->getJson('/api/v1/manager/users');

        $response->assertStatus(200);
    }

    /**
     * Test super admin can access manager routes.
     */
    public function test_super_admin_can_access_manager_routes(): void
    {
        $team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'is_active' => true,
            'required_work_hours' => 7.0,
            'default_leave_quota_days' => 12,
            'max_leave_days_per_month' => 5,
        ]);

        User::create([
            'team_id' => $team->id,
            'name' => 'Employee',
            'email' => 'employee@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::EMPLOYEE,
            'leave_quota_days' => 12,
        ]);

        $superAdmin = User::create([
            'team_id' => null,
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
            'leave_quota_days' => 0,
        ]);

        $response = $this->actingAs($superAdmin)->getJson('/api/v1/manager/users');

        $response->assertStatus(200);
    }

    /**
     * Test super admin can see users from all teams.
     */
    public function test_super_admin_can_see_users_from_all_teams(): void
    {
        $team1 = Team::create([
            'name' => 'Team 1',
            'slug' => 'team-1',
            'is_active' => true,
            'required_work_hours' => 7.0,
            'default_leave_quota_days' => 12,
            'max_leave_days_per_month' => 5,
        ]);

        $team2 = Team::create([
            'name' => 'Team 2',
            'slug' => 'team-2',
            'is_active' => true,
            'required_work_hours' => 7.0,
            'default_leave_quota_days' => 12,
            'max_leave_days_per_month' => 5,
        ]);

        User::create([
            'team_id' => $team1->id,
            'name' => 'Team 1 User',
            'email' => 'team1@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::EMPLOYEE,
            'leave_quota_days' => 12,
        ]);

        User::create([
            'team_id' => $team2->id,
            'name' => 'Team 2 User',
            'email' => 'team2@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::EMPLOYEE,
            'leave_quota_days' => 12,
        ]);

        $superAdmin = User::create([
            'team_id' => null,
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
            'leave_quota_days' => 0,
        ]);

        $response = $this->actingAs($superAdmin)->getJson('/api/v1/manager/users');

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data'); // Should see both team users + super admin
    }

    /**
     * Test super admin can access employee routes.
     */
    public function test_super_admin_can_access_employee_routes(): void
    {
        $superAdmin = User::create([
            'team_id' => null,
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
            'leave_quota_days' => 0,
        ]);

        $response = $this->actingAs($superAdmin)->getJson('/api/v1/attendance/today');

        // Super admin can access but might not have attendance data
        $response->assertStatus(200);
    }

    /**
     * Test user seeder creates or updates super admin.
     */
    public function test_user_seeder_creates_or_updates_super_admin(): void
    {
        // Run seeder first time
        $this->artisan('db:seed', ['--class' => 'UserSeeder']);

        $this->assertDatabaseHas('users', [
            'email' => 'admin@example.com',
            'role' => 'super_admin',
            'team_id' => null,
        ]);

        $firstCount = User::count();

        // Run seeder again - should not create duplicate
        $this->artisan('db:seed', ['--class' => 'UserSeeder']);

        $secondCount = User::count();

        // Count should remain the same (no duplicate created)
        $this->assertEquals($firstCount, $secondCount);
    }

    /**
     * Test super admin can login.
     */
    public function test_super_admin_can_login(): void
    {
        $superAdmin = User::create([
            'team_id' => null,
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password123'),
            'role' => UserRole::SUPER_ADMIN,
            'leave_quota_days' => 0,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password123',
            'captcha_token' => 'test-captcha-token',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'user' => [
                    'id',
                    'name',
                    'email',
                    'role',
                ],
                'token',
            ],
        ]);
        $response->assertJson([
            'data' => [
                'user' => [
                    'role' => 'super_admin',
                ],
            ],
        ]);
    }

    /**
     * Test super admin can update user from any team.
     */
    public function test_super_admin_can_update_user_from_any_team(): void
    {
        $team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'is_active' => true,
            'required_work_hours' => 7.0,
            'default_leave_quota_days' => 12,
            'max_leave_days_per_month' => 5,
        ]);

        $user = User::create([
            'team_id' => $team->id,
            'name' => 'Employee',
            'email' => 'employee@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::EMPLOYEE,
            'leave_quota_days' => 12,
        ]);

        $superAdmin = User::create([
            'team_id' => null,
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
            'leave_quota_days' => 0,
        ]);

        // Super admin should be able to update user from any team
        $response = $this->actingAs($superAdmin)->putJson("/api/v1/manager/users/{$user->id}", [
            'name' => 'Updated Employee',
            'email' => 'employee@example.com',
            'role' => 'employee',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Employee',
        ]);
    }
}
