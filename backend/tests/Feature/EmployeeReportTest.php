<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\CreatesTeamUsers;

class EmployeeReportTest extends TestCase
{
    use CreatesTeamUsers, RefreshDatabase;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = $this->createEmployee([
            'email' => 'employee@example.com',
            'password' => Hash::make('password123'),
        ]);
    }

    public function test_employee_can_view_personal_report(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        // Create some attendance records
        $attendance1 = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => Carbon::parse('2024-01-15 09:00:00'),
            'check_out' => Carbon::parse('2024-01-15 17:00:00'),
            'date' => Carbon::parse('2024-01-15'),
            'total_hours' => 8.0,
        ]);

        $attendance2 = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => Carbon::parse('2024-01-16 08:00:00'),
            'check_out' => Carbon::parse('2024-01-16 12:00:00'),
            'date' => Carbon::parse('2024-01-16'),
            'total_hours' => 4.0,
        ]);

        $attendance3 = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => Carbon::parse('2024-01-16 14:00:00'),
            'check_out' => Carbon::parse('2024-01-16 17:00:00'),
            'date' => Carbon::parse('2024-01-16'),
            'total_hours' => 3.0,
        ]);

        // Create tasks
        Task::factory()->create([
            'attendance_id' => $attendance1->id,
            'is_completed' => true,
        ]);

        Task::factory()->create([
            'attendance_id' => $attendance1->id,
            'is_completed' => false,
        ]);

        $response = $this->getJson('/api/v1/reports/my-report?start_date=2024-01-01&end_date=2024-01-31', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'summary' => [
                        'working_days',
                        'expected_hours',
                        'total_days_worked',
                        'total_leave_days',
                        'total_hours',
                        'average_hours_per_day',
                        'overtime_hours',
                        'deficit_hours',
                        'incomplete_days',
                        'task_completion_rate',
                    ],
                    'attendances',
                ],
            ])
            ->assertJson(['success' => true]);
    }

    public function test_employee_can_view_report_with_date_range(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/reports/my-report?start_date=2024-01-01&end_date=2024-01-31', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_employee_cannot_access_without_authentication(): void
    {
        $response = $this->getJson('/api/v1/reports/my-report');

        $response->assertStatus(401);
    }

    public function test_manager_cannot_access_employee_report_endpoint(): void
    {
        $manager = User::factory()->create([
            'role' => UserRole::MANAGER,
        ]);
        $token = $manager->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/reports/my-report', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }
}
