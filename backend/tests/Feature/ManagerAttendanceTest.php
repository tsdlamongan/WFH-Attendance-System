<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\CreatesTeamUsers;

class ManagerAttendanceTest extends TestCase
{
    use RefreshDatabase, CreatesTeamUsers;

    private User $manager;
    private User $employee;
    private Attendance $attendance;

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

        $this->attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => Carbon::parse('2024-01-15 09:00:00'),
            'check_out' => Carbon::parse('2024-01-15 17:00:00'),
            'date' => Carbon::parse('2024-01-15'),
            'total_hours' => 8.0,
        ]);
    }

    public function test_manager_can_list_all_attendances(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/attendances', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'user_id',
                        'check_in',
                        'check_out',
                        'date',
                        'total_hours',
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

    public function test_manager_can_list_attendances_with_pagination(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create additional attendances
        Attendance::factory()->count(25)->create([
            'user_id' => $this->employee->id,
            'date' => Carbon::today(),
        ]);

        $response = $this->getJson('/api/v1/manager/attendances?page=1&per_page=10', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('pagination.per_page', 10)
            ->assertJsonPath('pagination.current_page', 1);
    }

    public function test_manager_can_list_attendances_with_date_range(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/attendances?start_date=2024-01-01&end_date=2024-01-31', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_manager_can_edit_attendance(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/attendances/{$this->attendance->id}", [
            'date' => '2024-01-15',
            'check_in' => '2024-01-15T09:00:00',
            'check_out' => '2024-01-15T18:00:00',
            'reason' => 'Employee forgot to check out, verified via chat',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'check_in',
                    'check_out',
                    'total_hours',
                ],
                'message',
            ])
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('attendances', [
            'id' => $this->attendance->id,
        ]);
    }

    public function test_manager_can_edit_attendance_date(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $newDate = '2024-01-16';
        $response = $this->putJson("/api/v1/manager/attendances/{$this->attendance->id}", [
            'date' => $newDate,
            'check_in' => '2024-01-16T09:00:00',
            'check_out' => '2024-01-16T17:00:00',
            'reason' => 'Correcting attendance date, employee worked on different day',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Refresh the attendance model
        $this->attendance->refresh();

        // Compare using Carbon to handle date format
        $this->assertEquals(
            $newDate,
            Carbon::parse($this->attendance->date)->toDateString()
        );
    }

    public function test_manager_cannot_edit_attendance_without_date(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/attendances/{$this->attendance->id}", [
            'check_in' => '2024-01-15T09:00:00',
            'check_out' => '2024-01-15T18:00:00',
            'reason' => 'Missing date field',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    public function test_manager_cannot_edit_attendance_with_mismatched_check_in_date(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/attendances/{$this->attendance->id}", [
            'date' => '2024-01-15',
            'check_in' => '2024-01-16T09:00:00', // Different date
            'check_out' => '2024-01-15T17:00:00',
            'reason' => 'Testing date mismatch validation',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['check_in']);
    }

    public function test_manager_can_edit_attendance_with_overtime_checkout_on_different_day(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/attendances/{$this->attendance->id}", [
            'date' => '2024-01-15',
            'check_in' => '2024-01-15T09:00:00',
            'check_out' => '2024-01-16T02:00:00', // Overtime - next day
            'reason' => 'Employee worked overtime until next day',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->attendance->refresh();
        $this->assertEquals('2024-01-16 02:00:00', $this->attendance->check_out->format('Y-m-d H:i:s'));
    }

    public function test_manager_cannot_edit_attendance_without_reason(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/attendances/{$this->attendance->id}", [
            'date' => '2024-01-15',
            'check_in' => '2024-01-15T09:00:00',
            'check_out' => '2024-01-15T18:00:00',
            'reason' => 'Short',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422);
    }

    public function test_manager_can_delete_attendance(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->deleteJson("/api/v1/manager/attendances/{$this->attendance->id}", [
            'reason' => 'Duplicate entry - employee checked in twice by mistake',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('attendances', [
            'id' => $this->attendance->id,
        ]);
    }

    public function test_manager_cannot_delete_attendance_without_reason(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->deleteJson("/api/v1/manager/attendances/{$this->attendance->id}", [
            'reason' => 'Short',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422);
    }

    public function test_employee_cannot_edit_attendance(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/attendances/{$this->attendance->id}", [
            'date' => '2024-01-15',
            'check_in' => '2024-01-15T09:00:00',
            'check_out' => '2024-01-15T18:00:00',
            'reason' => 'Employee trying to edit',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_employee_cannot_delete_attendance(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        $response = $this->deleteJson("/api/v1/manager/attendances/{$this->attendance->id}", [
            'reason' => 'Employee trying to delete',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_manager_can_filter_attendances_by_user_id(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create another employee and their attendance
        $employee2 = User::factory()->create([
            'name' => 'Employee Two',
            'email' => 'employee2@example.com',
            'role' => UserRole::EMPLOYEE,
        ]);

        Attendance::factory()->count(5)->create([
            'user_id' => $this->employee->id,
            'date' => Carbon::today(),
        ]);

        Attendance::factory()->count(3)->create([
            'user_id' => $employee2->id,
            'date' => Carbon::today(),
        ]);

        // Filter by first employee
        $response = $this->getJson("/api/v1/manager/attendances?user_id={$this->employee->id}", [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // All returned attendances should belong to the first employee
        $data = $response->json('data');
        foreach ($data as $attendance) {
            $this->assertEquals($this->employee->id, $attendance['user_id']);
        }
    }

    public function test_manager_can_combine_user_filter_with_date_range(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create another employee
        $employee2 = User::factory()->create([
            'name' => 'Employee Two',
            'email' => 'employee2@example.com',
            'role' => UserRole::EMPLOYEE,
        ]);

        // Create attendances for employee 1 in January
        Attendance::factory()->count(3)->create([
            'user_id' => $this->employee->id,
            'date' => Carbon::parse('2024-01-10'),
        ]);

        // Create attendances for employee 1 in February
        Attendance::factory()->count(2)->create([
            'user_id' => $this->employee->id,
            'date' => Carbon::parse('2024-02-10'),
        ]);

        // Create attendances for employee 2 in January
        Attendance::factory()->count(4)->create([
            'user_id' => $employee2->id,
            'date' => Carbon::parse('2024-01-15'),
        ]);

        // Filter by employee 1 and January date range
        $response = $this->getJson(
            '/api/v1/manager/attendances?user_id=' . $this->employee->id . 
            '&start_date=2024-01-01&end_date=2024-01-31',
            [
                'Authorization' => "Bearer {$token}",
            ]
        );

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Should only return employee 1's January attendances (3 + 1 from setUp)
        $data = $response->json('data');
        foreach ($data as $attendance) {
            $this->assertEquals($this->employee->id, $attendance['user_id']);
            $date = Carbon::parse($attendance['date']);
            $this->assertTrue($date->between(
                Carbon::parse('2024-01-01'),
                Carbon::parse('2024-01-31')
            ));
        }
    }

    public function test_manager_can_use_user_filter_with_pagination(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create 25 attendances for the employee
        Attendance::factory()->count(25)->create([
            'user_id' => $this->employee->id,
            'date' => Carbon::today(),
        ]);

        // Create attendances for another employee (should be filtered out)
        $employee2 = User::factory()->create(['role' => UserRole::EMPLOYEE]);
        Attendance::factory()->count(10)->create([
            'user_id' => $employee2->id,
            'date' => Carbon::today(),
        ]);

        $response = $this->getJson(
            "/api/v1/manager/attendances?user_id={$this->employee->id}&page=1&per_page=10",
            [
                'Authorization' => "Bearer {$token}",
            ]
        );

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('pagination.per_page', 10)
            ->assertJsonPath('pagination.current_page', 1);

        // Verify all results are for the filtered employee
        $data = $response->json('data');
        $this->assertCount(10, $data);
        foreach ($data as $attendance) {
            $this->assertEquals($this->employee->id, $attendance['user_id']);
        }
    }
}
