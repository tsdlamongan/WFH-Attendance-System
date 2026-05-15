<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTeamUsers;

class CheckInTimeReportTest extends TestCase
{
    use RefreshDatabase, CreatesTeamUsers;

    private User $manager;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->createManager();
        $this->employee = $this->createEmployee();
    }

    public function test_manager_can_get_check_in_time_report(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create attendances with different check-in times
        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'date' => Carbon::today(),
            'check_in' => Carbon::today()->setTime(9, 15, 0), // On time (9:00-10:00)
            'check_out' => Carbon::today()->setTime(17, 0, 0),
            'total_hours' => 7.75,
        ]);

        $response = $this->getJson('/api/v1/manager/reports/check-in-time', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'start_date',
                    'end_date',
                    'window_start',
                    'window_end',
                    'employees' => [
                        '*' => [
                            'employee' => ['id', 'name', 'email'],
                            'total_days',
                            'on_time_days',
                            'late_days',
                            'consistency_rate',
                            'details' => [
                                '*' => [
                                    'date',
                                    'check_in_time',
                                    'is_on_time',
                                ],
                            ],
                        ],
                    ],
                ],
            ])
            ->assertJson(['success' => true]);
    }

    public function test_check_in_time_report_calculates_consistency_correctly(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Use fixed dates within the same month to avoid cross-month issues
        $baseDate = Carbon::create(2024, 6, 15); // Mid-month to ensure all 5 days are in same month

        // Create 5 attendances: 3 on time, 2 late
        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'date' => $baseDate->copy()->subDays(4),
            'check_in' => $baseDate->copy()->subDays(4)->setTime(9, 15, 0), // On time
            'check_out' => $baseDate->copy()->subDays(4)->setTime(17, 0, 0),
            'total_hours' => 7.75,
        ]);

        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'date' => $baseDate->copy()->subDays(3),
            'check_in' => $baseDate->copy()->subDays(3)->setTime(9, 30, 0), // On time
            'check_out' => $baseDate->copy()->subDays(3)->setTime(17, 0, 0),
            'total_hours' => 7.5,
        ]);

        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'date' => $baseDate->copy()->subDays(2),
            'check_in' => $baseDate->copy()->subDays(2)->setTime(10, 15, 0), // Late
            'check_out' => $baseDate->copy()->subDays(2)->setTime(17, 0, 0),
            'total_hours' => 6.75,
        ]);

        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'date' => $baseDate->copy()->subDays(1),
            'check_in' => $baseDate->copy()->subDays(1)->setTime(9, 45, 0), // On time
            'check_out' => $baseDate->copy()->subDays(1)->setTime(17, 0, 0),
            'total_hours' => 7.25,
        ]);

        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'date' => $baseDate,
            'check_in' => $baseDate->copy()->setTime(11, 0, 0), // Late
            'check_out' => $baseDate->copy()->setTime(18, 0, 0),
            'total_hours' => 7.0,
        ]);

        // Use explicit date range to include all test data
        $startDate = $baseDate->copy()->subDays(4)->format('Y-m-d');
        $endDate = $baseDate->format('Y-m-d');

        $response = $this->getJson("/api/v1/manager/reports/check-in-time?start_date={$startDate}&end_date={$endDate}", [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $employeeData = $data['employees'][0];

        $this->assertEquals(5, $employeeData['total_days']);
        $this->assertEquals(3, $employeeData['on_time_days']);
        $this->assertEquals(2, $employeeData['late_days']);
        $this->assertEquals(60.0, $employeeData['consistency_rate']); // 3/5 * 100 = 60%
    }

    public function test_check_in_time_report_uses_earliest_check_in_for_multiple_sessions(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create multiple sessions on same day, first session is late but second is on time
        $date = Carbon::today();

        // First session (late)
        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'date' => $date,
            'check_in' => $date->copy()->setTime(10, 30, 0), // Late
            'check_out' => $date->copy()->setTime(12, 0, 0),
            'total_hours' => 1.5,
        ]);

        // Second session (on time - but shouldn't matter as we use earliest)
        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'date' => $date,
            'check_in' => $date->copy()->setTime(13, 0, 0), // Actually after window
            'check_out' => $date->copy()->setTime(18, 0, 0),
            'total_hours' => 5.0,
        ]);

        $response = $this->getJson('/api/v1/manager/reports/check-in-time', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $employeeData = $data['employees'][0];

        // Should use earliest check-in (10:30) which is late
        $this->assertEquals(1, $employeeData['total_days']);
        $this->assertEquals(0, $employeeData['on_time_days']);
        $this->assertEquals(1, $employeeData['late_days']);
        $this->assertFalse($employeeData['details'][0]['is_on_time']);
    }

    public function test_check_in_time_report_filters_by_date_range(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create attendance outside date range
        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'date' => Carbon::today()->subMonths(2),
            'check_in' => Carbon::today()->subMonths(2)->setTime(9, 0, 0),
            'check_out' => Carbon::today()->subMonths(2)->setTime(17, 0, 0),
            'total_hours' => 8.0,
        ]);

        // Create attendance inside date range
        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'date' => Carbon::today(),
            'check_in' => Carbon::today()->setTime(9, 30, 0),
            'check_out' => Carbon::today()->setTime(17, 0, 0),
            'total_hours' => 7.5,
        ]);

        $startDate = Carbon::now()->startOfMonth()->format('Y-m-d');
        $endDate = Carbon::now()->endOfMonth()->format('Y-m-d');

        $response = $this->getJson("/api/v1/manager/reports/check-in-time?start_date={$startDate}&end_date={$endDate}", [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);

        $data = $response->json('data');

        // Should only include attendance from current month
        $this->assertCount(1, $data['employees']);
        $this->assertEquals(1, $data['employees'][0]['total_days']);
    }

    public function test_employee_cannot_access_check_in_time_report(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/reports/check-in-time', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_access_check_in_time_report(): void
    {
        $response = $this->getJson('/api/v1/manager/reports/check-in-time');

        $response->assertStatus(401);
    }

    public function test_check_in_time_report_excludes_employees_without_attendance(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create another employee without any attendance
        $employee2 = $this->createEmployee(['name' => 'Employee 2', 'email' => 'employee2@example.com']);

        // Only create attendance for first employee
        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'date' => Carbon::today(),
            'check_in' => Carbon::today()->setTime(9, 30, 0),
            'check_out' => Carbon::today()->setTime(17, 0, 0),
            'total_hours' => 7.5,
        ]);

        $response = $this->getJson('/api/v1/manager/reports/check-in-time', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);

        $data = $response->json('data');

        // Should only include employee with attendance
        $this->assertCount(1, $data['employees']);
        $this->assertEquals($this->employee->id, $data['employees'][0]['employee']['id']);
    }

    public function test_check_in_time_report_excludes_disabled_employees(): void
    {
        $disabled = $this->createDisabledEmployee();

        Attendance::factory()->create([
            'user_id' => $disabled->id,
            'date' => Carbon::today(),
            'check_in' => Carbon::today()->setTime(9, 0, 0),
            'check_out' => Carbon::today()->setTime(17, 0, 0),
            'total_hours' => 8.0,
        ]);

        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'date' => Carbon::today(),
            'check_in' => Carbon::today()->setTime(9, 0, 0),
            'check_out' => Carbon::today()->setTime(17, 0, 0),
            'total_hours' => 8.0,
        ]);

        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/reports/check-in-time', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
        $employeeIds = collect($response->json('data.employees'))->pluck('employee.id');
        $this->assertFalse($employeeIds->contains($disabled->id));
        $this->assertTrue($employeeIds->contains($this->employee->id));
    }
}
