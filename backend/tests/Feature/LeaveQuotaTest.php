<?php

namespace Tests\Feature;

use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\LeaveQuota;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\CreatesTeamUsers;

class LeaveQuotaTest extends TestCase
{
    use CreatesTeamUsers, RefreshDatabase;

    private User $manager;

    private User $employee;

    private User $employee2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->createManager([
            'email' => 'manager@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->employee = $this->createEmployee([
            'email' => 'employee@example.com',
            'leave_quota_days' => 12,
        ]);

        $this->employee2 = $this->createEmployee([
            'email' => 'employee2@example.com',
            'leave_quota_days' => 12,
        ]);
    }

    public function test_manager_can_list_leave_quotas(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;
        $year = now()->year;

        $response = $this->getJson("/api/v1/manager/leave-quotas?year={$year}", [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'user_id',
                        'name',
                        'email',
                        'year',
                        'quota_days',
                        'is_custom',
                        'used_days',
                        'pending_days',
                        'remaining_days',
                    ],
                ],
            ]);
    }

    public function test_list_quotas_shows_default_when_no_custom_set(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;
        $year = now()->year;

        $response = $this->getJson("/api/v1/manager/leave-quotas?year={$year}", [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $employeeData = collect($data)->firstWhere('user_id', $this->employee->id);

        $this->assertNotNull($employeeData);
        $this->assertEquals(12, $employeeData['quota_days']);
        $this->assertFalse($employeeData['is_custom']);
    }

    public function test_list_quotas_shows_custom_when_set(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;
        $year = now()->year;

        LeaveQuota::create([
            'user_id' => $this->employee->id,
            'year' => $year,
            'quota_days' => 5,
        ]);

        $response = $this->getJson("/api/v1/manager/leave-quotas?year={$year}", [
            'Authorization' => "Bearer {$token}",
        ]);

        $data = $response->json('data');
        $employeeData = collect($data)->firstWhere('user_id', $this->employee->id);

        $this->assertEquals(5, $employeeData['quota_days']);
        $this->assertTrue($employeeData['is_custom']);
    }

    public function test_manager_can_update_individual_leave_quota(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;
        $year = now()->year;

        $response = $this->putJson("/api/v1/manager/leave-quotas/{$this->employee->id}", [
            'year' => $year,
            'quota_days' => 3,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user_id' => $this->employee->id,
                    'year' => $year,
                    'quota_days' => 3,
                ],
            ]);

        $this->assertDatabaseHas('leave_quotas', [
            'user_id' => $this->employee->id,
            'year' => $year,
            'quota_days' => 3,
        ]);
    }

    public function test_manager_can_update_existing_quota(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;
        $year = now()->year;

        LeaveQuota::create([
            'user_id' => $this->employee->id,
            'year' => $year,
            'quota_days' => 3,
        ]);

        $response = $this->putJson("/api/v1/manager/leave-quotas/{$this->employee->id}", [
            'year' => $year,
            'quota_days' => 15,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'quota_days' => 15,
                ],
            ]);

        $this->assertDatabaseHas('leave_quotas', [
            'user_id' => $this->employee->id,
            'year' => $year,
            'quota_days' => 15,
        ]);

        $this->assertEquals(1, LeaveQuota::where('user_id', $this->employee->id)->where('year', $year)->count());
    }

    public function test_manager_can_bulk_update_quotas(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;
        $year = now()->year;

        $response = $this->postJson('/api/v1/manager/leave-quotas/bulk', [
            'year' => $year,
            'quota_days' => 8,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'year' => $year,
                    'quota_days' => 8,
                ],
            ]);

        $this->assertDatabaseHas('leave_quotas', [
            'user_id' => $this->employee->id,
            'year' => $year,
            'quota_days' => 8,
        ]);

        $this->assertDatabaseHas('leave_quotas', [
            'user_id' => $this->employee2->id,
            'year' => $year,
            'quota_days' => 8,
        ]);
    }

    public function test_manager_cannot_update_quota_for_other_team_user(): void
    {
        $otherEmployee = User::factory()->create([
            'role' => UserRole::EMPLOYEE,
            'leave_quota_days' => 12,
        ]);

        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/leave-quotas/{$otherEmployee->id}", [
            'year' => now()->year,
            'quota_days' => 5,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(404);
    }

    public function test_employee_cannot_access_leave_quota_endpoints(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/leave-quotas', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_quota_validation_rejects_invalid_year(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/leave-quotas/{$this->employee->id}", [
            'year' => 2010,
            'quota_days' => 12,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422);
    }

    public function test_quota_validation_rejects_negative_days(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/leave-quotas/{$this->employee->id}", [
            'year' => now()->year,
            'quota_days' => -1,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422);
    }

    public function test_leave_request_uses_yearly_quota(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 3, 1));

        try {
            $token = $this->employee->createToken('auth-token')->plainTextToken;

            LeaveQuota::create([
                'user_id' => $this->employee->id,
                'year' => 2026,
                'quota_days' => 3,
            ]);

            $this->createLeave([
                'user_id' => $this->employee->id,
                'start_date' => Carbon::create(2026, 4, 1),
                'end_date' => Carbon::create(2026, 4, 2),
                'status' => LeaveStatus::APPROVED,
            ]);

            // Try to request 2 more days (total would be 4, exceeding quota of 3)
            $response = $this->postJson('/api/v1/leaves', [
                'start_date' => '2026-05-10',
                'end_date' => '2026-05-11',
                'reason' => 'This should exceed the yearly quota of 3 days',
            ], [
                'Authorization' => "Bearer {$token}",
            ]);

            $response->assertStatus(422)
                ->assertJson(['success' => false]);

            $this->assertStringContainsString('Jatah cuti tidak mencukupi', $response->json('message'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_leave_summary_uses_yearly_quota(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;
        $year = now()->year;

        LeaveQuota::create([
            'user_id' => $this->employee->id,
            'year' => $year,
            'quota_days' => 5,
        ]);

        $response = $this->getJson("/api/v1/leaves/summary?year={$year}", [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'year' => $year,
                    'total_quota' => 5,
                ],
            ]);
    }

    public function test_leave_summary_falls_back_to_user_default(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/leaves/summary?year=2030', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'year' => 2030,
                    'total_quota' => 12,
                ],
            ]);
    }

    public function test_different_years_have_different_quotas(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        LeaveQuota::create([
            'user_id' => $this->employee->id,
            'year' => 2025,
            'quota_days' => 3,
        ]);

        LeaveQuota::create([
            'user_id' => $this->employee->id,
            'year' => 2026,
            'quota_days' => 12,
        ]);

        $response2025 = $this->getJson('/api/v1/leaves/summary?year=2025', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response2025->assertJson([
            'data' => ['total_quota' => 3],
        ]);

        $response2026 = $this->getJson('/api/v1/leaves/summary?year=2026', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response2026->assertJson([
            'data' => ['total_quota' => 12],
        ]);
    }

    public function test_quota_list_excludes_disabled_users(): void
    {
        $disabled = $this->createDisabledEmployee([
            'email' => 'disabled@example.com',
            'name' => 'Disabled User',
        ]);

        $token = $this->manager->createToken('auth-token')->plainTextToken;
        $year = now()->year;

        $response = $this->getJson("/api/v1/manager/leave-quotas?year={$year}", [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
        $userIds = collect($response->json('data'))->pluck('user_id');
        $this->assertFalse($userIds->contains($disabled->id), 'Disabled user should not appear in quota list');
    }

    public function test_cannot_update_quota_for_disabled_user(): void
    {
        $disabled = $this->createDisabledEmployee();
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/leave-quotas/{$disabled->id}", [
            'year' => now()->year,
            'quota_days' => 20,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(404);
    }
}
