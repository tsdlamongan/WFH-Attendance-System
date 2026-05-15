<?php

namespace Tests\Feature;

use App\Enums\LeaveStatus;
use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\CreatesTeamUsers;

class ManagerDashboardTest extends TestCase
{
    use CreatesTeamUsers, RefreshDatabase;

    private User $manager;

    private User $employee1;

    private User $employee2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->createManager([
            'email' => 'manager@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->employee1 = $this->createEmployee([
            'email' => 'employee1@example.com',
        ]);

        $this->employee2 = $this->createEmployee([
            'email' => 'employee2@example.com',
        ]);
    }

    public function test_manager_can_view_dashboard(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create attendance for employee1
        Attendance::factory()->create([
            'user_id' => $this->employee1->id,
            'check_in' => Carbon::now()->subHours(2),
            'check_out' => null,
            'date' => Carbon::today(),
        ]);

        // Create completed attendance for employee2
        Attendance::factory()->create([
            'user_id' => $this->employee2->id,
            'check_in' => Carbon::today()->setTime(9, 0),
            'check_out' => Carbon::today()->setTime(17, 0),
            'date' => Carbon::today(),
            'total_hours' => 8.0,
        ]);

        $response = $this->getJson('/api/v1/manager/dashboard', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'summary' => [
                        'total_employees',
                        'checked_in_now',
                        'on_leave',
                        'average_daily_hours',
                    ],
                    'employees',
                ],
            ])
            ->assertJson(['success' => true]);
    }

    public function test_manager_can_view_dashboard_with_date_filter(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/dashboard?date=2024-01-15', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_manager_can_view_employee_report(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create attendance for employee
        Attendance::factory()->create([
            'user_id' => $this->employee1->id,
            'check_in' => Carbon::parse('2024-01-15 09:00:00'),
            'check_out' => Carbon::parse('2024-01-15 17:00:00'),
            'date' => Carbon::parse('2024-01-15'),
            'total_hours' => 8.0,
        ]);

        $response = $this->getJson("/api/v1/manager/reports/employee/{$this->employee1->id}?start_date=2024-01-01&end_date=2024-01-31", [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'summary',
                    'attendances',
                ],
            ])
            ->assertJson(['success' => true]);
    }

    public function test_manager_dashboard_shows_employees_on_leave(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create approved leave for today
        $this->createLeave([
            'user_id' => $this->employee1->id,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
            'status' => LeaveStatus::APPROVED,
        ]);

        $response = $this->getJson('/api/v1/manager/dashboard', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.summary.on_leave', 1);
    }

    public function test_employee_cannot_access_manager_dashboard(): void
    {
        $token = $this->employee1->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/dashboard', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_manager_can_view_daily_attendance_report(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create attendance for employee1
        Attendance::factory()->create([
            'user_id' => $this->employee1->id,
            'check_in' => Carbon::today()->setTime(9, 0),
            'check_out' => Carbon::today()->setTime(17, 0),
            'date' => Carbon::today(),
            'total_hours' => 8.0,
        ]);

        $response = $this->getJson('/api/v1/manager/reports/daily-attendance?date='.Carbon::today()->format('Y-m-d'), [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'date',
                    'required_hours',
                    'employees',
                ],
            ])
            ->assertJson(['success' => true]);
    }

    public function test_daily_attendance_report_shows_all_employees(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Ensure required hours are set for this test
        $this->team->update(['required_work_hours' => Team::DEFAULT_REQUIRED_WORK_HOURS]);

        // Create attendance for employee1
        Attendance::factory()->create([
            'user_id' => $this->employee1->id,
            'check_in' => Carbon::today()->setTime(9, 0),
            'check_out' => Carbon::today()->setTime(17, 0),
            'date' => Carbon::today(),
            'total_hours' => 8.0,
        ]);

        // Create attendance for employee2
        Attendance::factory()->create([
            'user_id' => $this->employee2->id,
            'check_in' => Carbon::today()->setTime(8, 0),
            'check_out' => Carbon::today()->setTime(15, 0),
            'date' => Carbon::today(),
            'total_hours' => 7.0,
        ]);

        $response = $this->getJson('/api/v1/manager/reports/daily-attendance?date='.Carbon::today()->format('Y-m-d'), [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);

        $data = $response->json('data.employees');

        // Should have 2 employees
        $this->assertCount(2, $data);

        // Check employee1 has correct data
        $employee1Data = collect($data)->firstWhere('employee.id', $this->employee1->id);
        $this->assertNotNull($employee1Data, 'Employee1 data should exist');
        $this->assertEquals(8.0, $employee1Data['daily_total_hours'], 'Employee1 should have 8 total hours');
        // Overtime = 8 - 7 = 1 hour
        $this->assertEquals(1.0, $employee1Data['overtime_hours'], 'Employee1 should have 1 hour overtime. Got: '.($employee1Data['overtime_hours'] ?? 'null'));
        $this->assertEquals('overtime', $employee1Data['status']);

        // Check employee2 has correct data
        $employee2Data = collect($data)->firstWhere('employee.id', $this->employee2->id);
        $this->assertEquals(7.0, $employee2Data['daily_total_hours']);
        $this->assertEquals(0.0, $employee2Data['overtime_hours']);
        $this->assertEquals('complete', $employee2Data['status']);
    }

    public function test_daily_attendance_report_includes_sessions(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create multiple sessions for employee1 on the same day
        Attendance::factory()->create([
            'user_id' => $this->employee1->id,
            'check_in' => Carbon::today()->setTime(8, 0),
            'check_out' => Carbon::today()->setTime(12, 0),
            'date' => Carbon::today(),
            'total_hours' => 4.0,
        ]);

        Attendance::factory()->create([
            'user_id' => $this->employee1->id,
            'check_in' => Carbon::today()->setTime(14, 0),
            'check_out' => Carbon::today()->setTime(17, 0),
            'date' => Carbon::today(),
            'total_hours' => 3.0,
        ]);

        $response = $this->getJson('/api/v1/manager/reports/daily-attendance?date='.Carbon::today()->format('Y-m-d'), [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);

        $data = $response->json('data.employees');
        $employee1Data = collect($data)->firstWhere('employee.id', $this->employee1->id);

        // Should have 2 sessions
        $this->assertCount(2, $employee1Data['sessions']);

        // Session 1 should be the earliest (8:00)
        $this->assertEquals(1, $employee1Data['sessions'][0]['session_number']);

        // Session 2 should be later (14:00)
        $this->assertEquals(2, $employee1Data['sessions'][1]['session_number']);

        // Check sessions structure
        $this->assertArrayHasKey('check_in', $employee1Data['sessions'][0]);
        $this->assertArrayHasKey('check_out', $employee1Data['sessions'][0]);
        $this->assertArrayHasKey('total_hours', $employee1Data['sessions'][0]);
        $this->assertArrayHasKey('tasks', $employee1Data['sessions'][0]);
    }

    public function test_daily_attendance_report_shows_on_leave_status(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create approved leave for employee1
        $this->createLeave([
            'user_id' => $this->employee1->id,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
            'status' => LeaveStatus::APPROVED,
        ]);

        $response = $this->getJson('/api/v1/manager/reports/daily-attendance?date='.Carbon::today()->format('Y-m-d'), [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);

        $data = $response->json('data.employees');
        $employee1Data = collect($data)->firstWhere('employee.id', $this->employee1->id);

        // Should show on_leave status
        $this->assertEquals('on_leave', $employee1Data['status']);
        $this->assertEquals(0.0, $employee1Data['daily_total_hours']);
        $this->assertEmpty($employee1Data['sessions']);
    }

    public function test_daily_attendance_report_defaults_to_today(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/reports/daily-attendance', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.date', Carbon::today()->format('Y-m-d'));
    }

    public function test_employee_cannot_access_daily_attendance_report(): void
    {
        $token = $this->employee1->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/reports/daily-attendance', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_access_daily_attendance_report(): void
    {
        $response = $this->getJson('/api/v1/manager/reports/daily-attendance');

        $response->assertStatus(401);
    }

    public function test_manager_can_view_monthly_attendance_report(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create attendance for employee1
        Attendance::factory()->create([
            'user_id' => $this->employee1->id,
            'check_in' => Carbon::parse('2024-01-15 09:00:00'),
            'check_out' => Carbon::parse('2024-01-15 17:00:00'),
            'date' => Carbon::parse('2024-01-15'),
            'total_hours' => 8.0,
        ]);

        // Create attendance for employee2
        Attendance::factory()->create([
            'user_id' => $this->employee2->id,
            'check_in' => Carbon::parse('2024-01-16 08:00:00'),
            'check_out' => Carbon::parse('2024-01-16 12:00:00'),
            'date' => Carbon::parse('2024-01-16'),
            'total_hours' => 4.0,
        ]);

        Attendance::factory()->create([
            'user_id' => $this->employee2->id,
            'check_in' => Carbon::parse('2024-01-16 14:00:00'),
            'check_out' => Carbon::parse('2024-01-16 17:00:00'),
            'date' => Carbon::parse('2024-01-16'),
            'total_hours' => 3.0,
        ]);

        $response = $this->getJson('/api/v1/manager/reports/monthly-attendance?start_date=2024-01-01&end_date=2024-01-31', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'start_date',
                    'end_date',
                    'required_hours',
                    'employees' => [
                        '*' => [
                            'employee' => [
                                'id',
                                'name',
                                'email',
                            ],
                            'total_hours',
                            'total_overtime_hours',
                            'total_days_worked',
                            'daily_details',
                        ],
                    ],
                ],
            ])
            ->assertJson(['success' => true]);

        // Check employee1 has correct total hours
        $data = $response->json('data.employees');
        $employee1Data = collect($data)->firstWhere('employee.id', $this->employee1->id);
        $this->assertEquals(8.0, $employee1Data['total_hours']);
        $this->assertEquals(1, $employee1Data['total_days_worked']);

        // Check employee2 has correct total hours (4 + 3 = 7)
        $employee2Data = collect($data)->firstWhere('employee.id', $this->employee2->id);
        $this->assertEquals(7.0, $employee2Data['total_hours']);
        $this->assertEquals(1, $employee2Data['total_days_worked']);
    }

    public function test_monthly_attendance_report_shows_overtime_hours(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Ensure required hours are set for this test
        $this->team->update(['required_work_hours' => Team::DEFAULT_REQUIRED_WORK_HOURS]);

        // Create attendance with 9 hours on a Monday (1 hour overtime)
        // 2024-01-15 is a Monday
        // Expected hours = 1 day × 8 = 8 hours
        // Overtime = 9 - 8 = 1 hour
        Attendance::factory()->create([
            'user_id' => $this->employee1->id,
            'check_in' => Carbon::parse('2024-01-15 08:00:00'),
            'check_out' => Carbon::parse('2024-01-15 17:00:00'),
            'date' => Carbon::parse('2024-01-15'),
            'total_hours' => 9.0,
        ]);

        // Filter for just that one day so expected hours = 1 day × 8 = 8 hours
        $response = $this->getJson('/api/v1/manager/reports/monthly-attendance?start_date=2024-01-15&end_date=2024-01-15', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);

        $data = $response->json('data.employees');
        $employee1Data = collect($data)->firstWhere('employee.id', $this->employee1->id);

        // Should have 1 hour overtime (9 total - 8 expected = 1)
        $this->assertNotNull($employee1Data, 'Employee1 data should exist');
        $this->assertEquals(1.0, $employee1Data['total_overtime_hours'], 'Employee1 should have 1 hour overtime. Got: '.($employee1Data['total_overtime_hours'] ?? 'null'));

        // Verify the response includes working_days and expected_total_hours
        $this->assertEquals(1, $response->json('data.working_days'));
        $this->assertEquals(8, $response->json('data.expected_total_hours'));
    }

    public function test_monthly_attendance_report_includes_daily_details(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create attendance for employee1 on two different dates
        Attendance::factory()->create([
            'user_id' => $this->employee1->id,
            'check_in' => Carbon::parse('2024-01-15 09:00:00'),
            'check_out' => Carbon::parse('2024-01-15 17:00:00'),
            'date' => Carbon::parse('2024-01-15'),
            'total_hours' => 8.0,
        ]);

        Attendance::factory()->create([
            'user_id' => $this->employee1->id,
            'check_in' => Carbon::parse('2024-01-16 09:00:00'),
            'check_out' => Carbon::parse('2024-01-16 17:00:00'),
            'date' => Carbon::parse('2024-01-16'),
            'total_hours' => 8.0,
        ]);

        $response = $this->getJson('/api/v1/manager/reports/monthly-attendance?start_date=2024-01-01&end_date=2024-01-31', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);

        $data = $response->json('data.employees');
        $employee1Data = collect($data)->firstWhere('employee.id', $this->employee1->id);

        // Should have 2 daily details
        $this->assertCount(2, $employee1Data['daily_details']);
        $this->assertEquals('2024-01-16', $employee1Data['daily_details'][0]['date']); // Newest first
        $this->assertEquals('2024-01-15', $employee1Data['daily_details'][1]['date']);

        // Check daily details structure
        $this->assertArrayHasKey('sessions', $employee1Data['daily_details'][0]);
        $this->assertArrayHasKey('daily_total_hours', $employee1Data['daily_details'][0]);
        $this->assertArrayHasKey('status', $employee1Data['daily_details'][0]);
    }

    public function test_monthly_attendance_report_defaults_to_current_month(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create attendance for today
        Attendance::factory()->create([
            'user_id' => $this->employee1->id,
            'check_in' => Carbon::today()->setTime(9, 0),
            'check_out' => Carbon::today()->setTime(17, 0),
            'date' => Carbon::today(),
            'total_hours' => 8.0,
        ]);

        $response = $this->getJson('/api/v1/manager/reports/monthly-attendance', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_employee_cannot_access_monthly_attendance_report(): void
    {
        $token = $this->employee1->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/reports/monthly-attendance', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_access_monthly_attendance_report(): void
    {
        $response = $this->getJson('/api/v1/manager/reports/monthly-attendance');

        $response->assertStatus(401);
    }

    public function test_dashboard_excludes_disabled_employees(): void
    {
        $disabled = $this->createDisabledEmployee([
            'email' => 'disabled-emp@example.com',
            'name' => 'Disabled Person',
        ]);

        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/dashboard', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);

        $employeeIds = collect($response->json('data.employees'))->pluck('id');
        $this->assertFalse($employeeIds->contains($disabled->id), 'Disabled employee should not appear in dashboard');

        // total_employees should count only the 2 active ones (from setUp), not the disabled
        $this->assertEquals(2, $response->json('data.summary.total_employees'));
    }

    public function test_daily_attendance_report_excludes_disabled_employees(): void
    {
        $disabled = $this->createDisabledEmployee(['name' => 'Disabled Person']);
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/reports/daily-attendance?date='.Carbon::today()->format('Y-m-d'), [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
        $employeeIds = collect($response->json('data.employees'))->pluck('employee.id');
        $this->assertFalse($employeeIds->contains($disabled->id));
    }

    public function test_monthly_attendance_report_excludes_disabled_employees(): void
    {
        $disabled = $this->createDisabledEmployee(['name' => 'Disabled Person']);
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/reports/monthly-attendance?start_date=2024-01-01&end_date=2024-01-31', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
        $employeeIds = collect($response->json('data.employees'))->pluck('employee.id');
        $this->assertFalse($employeeIds->contains($disabled->id));
    }
}
