<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TeamIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Team $teamA;
    private Team $teamB;
    private User $managerA;
    private User $managerB;
    private User $employeeA;
    private User $employeeB;

    protected function setUp(): void
    {
        parent::setUp();

        // Create Team A
        $this->teamA = Team::factory()->create(['name' => 'Team A']);
        $this->managerA = User::factory()->create([
            'team_id' => $this->teamA->id,
            'email' => 'managera@example.com',
            'password' => Hash::make('password123'),
            'role' => UserRole::MANAGER,
        ]);
        $this->employeeA = User::factory()->create([
            'team_id' => $this->teamA->id,
            'email' => 'employeea@example.com',
            'role' => UserRole::EMPLOYEE,
        ]);

        // Create Team B
        $this->teamB = Team::factory()->create(['name' => 'Team B']);
        $this->managerB = User::factory()->create([
            'team_id' => $this->teamB->id,
            'email' => 'managerb@example.com',
            'password' => Hash::make('password123'),
            'role' => UserRole::MANAGER,
        ]);
        $this->employeeB = User::factory()->create([
            'team_id' => $this->teamB->id,
            'email' => 'employeeb@example.com',
            'role' => UserRole::EMPLOYEE,
        ]);
    }

    public function test_manager_cannot_see_users_from_other_team(): void
    {
        $token = $this->managerA->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/users', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
        
        // Should only see users from Team A (manager + employee = 2 users)
        $this->assertCount(2, $response->json('data'));
        
        // Should not see Team B users
        $emails = collect($response->json('data'))->pluck('email')->toArray();
        $this->assertContains('managera@example.com', $emails);
        $this->assertContains('employeea@example.com', $emails);
        $this->assertNotContains('managerb@example.com', $emails);
        $this->assertNotContains('employeeb@example.com', $emails);
    }

    public function test_manager_cannot_see_attendances_from_other_team(): void
    {
        // Create attendance for Team A employee
        $attendanceA = Attendance::factory()->create([
            'user_id' => $this->employeeA->id,
            'date' => Carbon::today(),
        ]);

        // Create attendance for Team B employee
        $attendanceB = Attendance::factory()->create([
            'user_id' => $this->employeeB->id,
            'date' => Carbon::today(),
        ]);

        $tokenA = $this->managerA->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/attendances', [
            'Authorization' => "Bearer {$tokenA}",
        ]);

        $response->assertStatus(200);
        
        // Count should be 1 (only Team A attendance)
        $this->assertCount(1, $response->json('data'));
        
        // Should only see Team A attendances
        $attendanceIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertEquals([$attendanceA->id], $attendanceIds);
    }

    public function test_manager_cannot_edit_attendance_from_other_team(): void
    {
        $attendanceB = Attendance::factory()->create([
            'user_id' => $this->employeeB->id,
            'date' => Carbon::today(),
        ]);

        $tokenA = $this->managerA->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/attendances/{$attendanceB->id}", [
            'date' => Carbon::today()->toDateString(),
            'check_in' => Carbon::now()->subHours(8)->toIso8601String(),
            'check_out' => Carbon::now()->toIso8601String(),
            'reason' => 'Test reason for editing',
        ], [
            'Authorization' => "Bearer {$tokenA}",
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized to edit this attendance',
            ]);
    }

    public function test_manager_cannot_see_holidays_from_other_team(): void
    {
        // Create holiday for Team A
        $holidayA = Holiday::factory()->create([
            'team_id' => $this->teamA->id,
            'date' => Carbon::parse('2024-12-25'),
            'name' => 'Team A Christmas',
        ]);

        // Create holiday for Team B
        $holidayB = Holiday::factory()->create([
            'team_id' => $this->teamB->id,
            'date' => Carbon::parse('2024-12-25'),
            'name' => 'Team B Christmas',
        ]);

        $tokenA = $this->managerA->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/holidays?year=2024', [
            'Authorization' => "Bearer {$tokenA}",
        ]);

        $response->assertStatus(200);
        
        // Should only see 1 holiday (Team A)
        $this->assertCount(1, $response->json('data'));
        
        // Should only see Team A holidays
        $holidayNames = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertEquals(['Team A Christmas'], $holidayNames);
    }

    public function test_manager_cannot_see_leave_requests_from_other_team(): void
    {
        // Create leave for Team A employee
        $leaveA = Leave::factory()->create([
            'user_id' => $this->employeeA->id,
            'status' => 'pending',
        ]);

        // Create leave for Team B employee
        $leaveB = Leave::factory()->create([
            'user_id' => $this->employeeB->id,
            'status' => 'pending',
        ]);

        $tokenA = $this->managerA->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/leaves?status=pending', [
            'Authorization' => "Bearer {$tokenA}",
        ]);

        $response->assertStatus(200);
        
        // Should only see Team A leaves
        $leaveIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($leaveA->id, $leaveIds);
        $this->assertNotContains($leaveB->id, $leaveIds);
    }

    public function test_manager_cannot_approve_leave_from_other_team(): void
    {
        $leaveB = Leave::factory()->create([
            'user_id' => $this->employeeB->id,
            'status' => 'pending',
        ]);

        $tokenA = $this->managerA->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/leaves/{$leaveB->id}/approve", [
            'notes' => 'Approved',
        ], [
            'Authorization' => "Bearer {$tokenA}",
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized to approve this leave request',
            ]);
    }

    public function test_manager_dashboard_only_shows_own_team_employees(): void
    {
        $tokenA = $this->managerA->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/dashboard', [
            'Authorization' => "Bearer {$tokenA}",
        ]);

        $response->assertStatus(200);
        
        // Should show only 1 employee (employeeA), not employeeB
        $this->assertEquals(1, $response->json('data.summary.total_employees'));
        
        $employeeEmails = collect($response->json('data.employees'))->pluck('email')->toArray();
        $this->assertContains('employeea@example.com', $employeeEmails);
        $this->assertNotContains('employeeb@example.com', $employeeEmails);
    }

    public function test_manager_cannot_view_employee_report_from_other_team(): void
    {
        $tokenA = $this->managerA->createToken('auth-token')->plainTextToken;

        $response = $this->getJson("/api/v1/manager/reports/employee/{$this->employeeB->id}", [
            'Authorization' => "Bearer {$tokenA}",
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized to view this employee report',
            ]);
    }

    public function test_different_teams_can_have_same_holiday_dates(): void
    {
        // Both teams can have Christmas on same date
        $holidayA = Holiday::factory()->create([
            'team_id' => $this->teamA->id,
            'date' => Carbon::parse('2024-12-25'),
            'name' => 'Team A Christmas',
        ]);

        $holidayB = Holiday::factory()->create([
            'team_id' => $this->teamB->id,
            'date' => Carbon::parse('2024-12-25'),
            'name' => 'Team B Christmas',
        ]);

        $this->assertTrue(
            Holiday::where('team_id', $this->teamA->id)
                ->whereDate('date', '2024-12-25')
                ->exists()
        );

        $this->assertTrue(
            Holiday::where('team_id', $this->teamB->id)
                ->whereDate('date', '2024-12-25')
                ->exists()
        );
    }

    public function test_activity_logs_are_team_isolated(): void
    {
        // Create explicit activity logs for testing
        \App\Models\ActivityLog::create([
            'team_id' => $this->teamA->id,
            'user_id' => $this->employeeA->id,
            'action' => \App\Enums\ActivityType::CHECK_IN,
            'description' => 'Team A activity',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test',
            'created_at' => now(),
        ]);

        \App\Models\ActivityLog::create([
            'team_id' => $this->teamB->id,
            'user_id' => $this->employeeB->id,
            'action' => \App\Enums\ActivityType::CHECK_IN,
            'description' => 'Team B activity',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test',
            'created_at' => now(),
        ]);
        
        $tokenA = $this->managerA->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/activity-logs', [
            'Authorization' => "Bearer {$tokenA}",
        ]);

        $response->assertStatus(200);
        
        // Should only see Team A activity logs
        $logs = $response->json('data.logs');
        $userIds = collect($logs)->pluck('user.id')->unique()->toArray();
        
        // Should contain Team A employee
        $this->assertContains($this->employeeA->id, $userIds);
        
        // Should NOT contain Team B employee
        $this->assertNotContains($this->employeeB->id, $userIds);
    }
}
