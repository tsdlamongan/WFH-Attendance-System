<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\CreatesTeamUsers;

class ActivityLogTest extends TestCase
{
    use CreatesTeamUsers, RefreshDatabase;

    private User $manager;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->createManager([
            'email' => 'manager@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->employee = $this->createEmployee([
            'email' => 'employee@example.com',
        ]);
    }

    public function test_manager_can_view_activity_logs(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create some activity logs
        $this->createActivityLog([
            'user_id' => $this->employee->id,
            'action' => ActivityType::CHECK_IN,
            'description' => 'User checked in',
        ]);

        $this->createActivityLog([
            'user_id' => $this->employee->id,
            'action' => ActivityType::CHECK_OUT,
            'description' => 'User checked out',
        ]);

        $response = $this->getJson('/api/v1/manager/activity-logs', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'logs' => [
                        '*' => [
                            'id',
                            'user' => ['id', 'name', 'email'],
                            'action',
                            'description',
                            'ip_address',
                            'user_agent',
                            'created_at',
                        ],
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

    public function test_manager_can_view_activity_logs_with_pagination(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create multiple activity logs
        ActivityLog::factory()->count(25)->create([
            'user_id' => $this->employee->id,
            'action' => ActivityType::CHECK_IN,
        ]);

        $response = $this->getJson('/api/v1/manager/activity-logs?page=1&per_page=10', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('pagination.per_page', 10)
            ->assertJsonPath('pagination.current_page', 1);
    }

    public function test_manager_can_filter_activity_logs_by_user(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $this->createActivityLog([
            'user_id' => $this->employee->id,
            'action' => ActivityType::CHECK_IN,
        ]);

        $response = $this->getJson("/api/v1/manager/activity-logs?user_id={$this->employee->id}", [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_manager_can_filter_activity_logs_by_action(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $this->createActivityLog([
            'user_id' => $this->employee->id,
            'action' => ActivityType::CHECK_IN,
        ]);

        $response = $this->getJson('/api/v1/manager/activity-logs?action=check_in', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_manager_can_filter_activity_logs_by_date_range(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $this->createActivityLog([
            'user_id' => $this->employee->id,
            'action' => ActivityType::CHECK_IN,
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/manager/activity-logs?start_date=2024-01-01&end_date=2024-01-31', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_employee_cannot_access_activity_logs(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/activity-logs', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_activity_logs_are_created_on_check_in(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [
                ['title' => 'Task 1'],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->employee->id,
            'action' => ActivityType::CHECK_IN->value,
        ]);
    }

    public function test_activity_logs_are_created_on_user_creation(): void
    {
        $managerToken = $this->manager->createToken('auth-token')->plainTextToken;

        $this->postJson('/api/v1/manager/users', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'employee',
        ], [
            'Authorization' => "Bearer {$managerToken}",
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => ActivityType::USER_CREATED->value,
        ]);
    }
}
