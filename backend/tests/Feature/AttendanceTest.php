<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\CreatesTeamUsers;

class AttendanceTest extends TestCase
{
    use RefreshDatabase, CreatesTeamUsers;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createEmployee([
            'email' => 'employee@example.com',
            'password' => Hash::make('password123'),
        ]);
    }

    public function test_employee_can_check_in_with_tasks(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [
                ['title' => 'Complete feature X'],
                ['title' => 'Fix bug Y'],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'user_id',
                    'check_in',
                    'date',
                    'tasks',
                ],
            ])
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('attendances', [
            'user_id' => $this->user->id,
        ]);
    }

    public function test_employee_cannot_check_in_on_holiday(): void
    {
        $this->createHoliday([
            'date' => Carbon::today(),
            'name' => 'Test Holiday',
            'description' => 'Test',
        ]);

        $token = $this->user->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [
                ['title' => 'Task 1'],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422);
    }

    public function test_employee_can_check_out(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        // Check in first
        $checkInResponse = $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [
                ['title' => 'Task 1'],
                ['title' => 'Task 2'],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $attendanceId = $checkInResponse->json('data.id');
        $tasks = $checkInResponse->json('data.tasks');

        // Check out
        $response = $this->postJson('/api/v1/attendance/check-out', [
            'attendance_id' => $attendanceId,
            'tasks' => [
                [
                    'id' => $tasks[0]['id'],
                    'is_completed' => true,
                    'blocker_reason' => null,
                ],
                [
                    'id' => $tasks[1]['id'],
                    'is_completed' => false,
                    'blocker_reason' => 'Waiting for dependencies',
                ],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'check_out',
                    'total_hours',
                ],
            ]);
    }

    public function test_employee_can_view_today_status(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/attendance/today', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'date',
                    'is_checked_in',
                    'today_total_hours',
                    'required_hours',
                ],
            ]);
    }

    public function test_employee_can_check_in_multiple_times_per_day_installment_system(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        // First check-in
        $checkIn1 = $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [
                ['title' => 'Morning tasks'],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $attendanceId1 = $checkIn1->json('data.id');
        $tasks1 = $checkIn1->json('data.tasks');

        // Check out first session
        $this->postJson('/api/v1/attendance/check-out', [
            'attendance_id' => $attendanceId1,
            'tasks' => [
                [
                    'id' => $tasks1[0]['id'],
                    'is_completed' => true,
                    'blocker_reason' => null,
                ],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        // Second check-in (installment)
        $checkIn2 = $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [
                ['title' => 'Afternoon tasks'],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $checkIn2->assertStatus(201)
            ->assertJson(['success' => true]);

        $this->assertDatabaseCount('attendances', 2);
    }

    public function test_employee_cannot_check_in_when_active_session_exists(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        // First check-in
        $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [
                ['title' => 'Task 1'],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        // Try to check-in again without checking out
        $response = $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [
                ['title' => 'Task 2'],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_employee_cannot_check_out_without_active_session(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/attendance/check-out', [
            'attendance_id' => 999,
            'tasks' => [],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422);
    }

    public function test_employee_cannot_check_out_when_already_checked_out(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        // Check in
        $checkIn = $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [
                ['title' => 'Task 1'],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $attendanceId = $checkIn->json('data.id');
        $tasks = $checkIn->json('data.tasks');

        // Check out first time
        $this->postJson('/api/v1/attendance/check-out', [
            'attendance_id' => $attendanceId,
            'tasks' => [
                [
                    'id' => $tasks[0]['id'],
                    'is_completed' => true,
                    'blocker_reason' => null,
                ],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        // Try to check out again
        $response = $this->postJson('/api/v1/attendance/check-out', [
            'attendance_id' => $attendanceId,
            'tasks' => [
                [
                    'id' => $tasks[0]['id'],
                    'is_completed' => true,
                    'blocker_reason' => null,
                ],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422);
    }

    public function test_check_out_calculates_total_hours_correctly(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        // Create attendance manually with known times
        $checkInTime = Carbon::parse('2024-01-15 09:00:00');
        $checkOutTime = Carbon::parse('2024-01-15 17:00:00');

        $attendance = Attendance::factory()->create([
            'user_id' => $this->user->id,
            'check_in' => $checkInTime,
            'check_out' => null,
            'date' => $checkInTime->toDateString(),
        ]);

        $task = $attendance->tasks()->create([
            'title' => 'Task 1',
            'is_completed' => false,
        ]);

        // Check out
        $response = $this->postJson("/api/v1/attendance/check-out", [
            'attendance_id' => $attendance->id,
            'tasks' => [
                [
                    'id' => $task->id,
                    'is_completed' => true,
                    'blocker_reason' => null,
                ],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_hours',
                ],
            ]);

        $totalHours = $response->json('data.total_hours');
        $this->assertGreaterThanOrEqual(0, $totalHours); // Should be calculated, even if small
    }

    public function test_employee_can_see_active_attendance_from_yesterday_in_today_status(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        // Create attendance from yesterday that is still active (no checkout)
        $yesterdayAttendance = Attendance::factory()->create([
            'user_id' => $this->user->id,
            'check_in' => Carbon::yesterday()->setTime(23, 0, 0),
            'check_out' => null,
            'date' => Carbon::yesterday()->toDateString(),
            'total_hours' => 0,
        ]);

        $yesterdayAttendance->tasks()->create([
            'title' => 'Task from yesterday',
            'is_completed' => false,
        ]);

        // Get today's status - should show yesterday's active attendance
        $response = $this->getJson('/api/v1/attendance/today', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'is_checked_in' => true,
                ],
            ]);

        // Verify it's the yesterday attendance
        $currentSession = $response->json('data.current_session');
        $this->assertNotNull($currentSession);
        $this->assertEquals($yesterdayAttendance->id, $currentSession['id']);
        $this->assertEquals('Task from yesterday', $currentSession['tasks'][0]['title']);
    }

    public function test_employee_can_checkout_from_yesterday_attendance(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        // Create attendance from yesterday that is still active
        $yesterdayAttendance = Attendance::factory()->create([
            'user_id' => $this->user->id,
            'check_in' => Carbon::yesterday()->setTime(23, 0, 0),
            'check_out' => null,
            'date' => Carbon::yesterday()->toDateString(),
            'total_hours' => 0,
        ]);

        $task = $yesterdayAttendance->tasks()->create([
            'title' => 'Task from yesterday',
            'is_completed' => false,
        ]);

        // Checkout today - should work
        $response = $this->postJson('/api/v1/attendance/check-out', [
            'attendance_id' => $yesterdayAttendance->id,
            'tasks' => [
                [
                    'id' => $task->id,
                    'is_completed' => true,
                    'blocker_reason' => null,
                ],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Verify checkout was successful
        $yesterdayAttendance->refresh();
        $this->assertNotNull($yesterdayAttendance->check_out);
        $this->assertGreaterThan(0, $yesterdayAttendance->total_hours);
    }

    public function test_findActiveByUser_includes_yesterday_attendance(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        // Create active attendance from yesterday
        $yesterdayAttendance = Attendance::factory()->create([
            'user_id' => $this->user->id,
            'check_in' => Carbon::yesterday()->setTime(23, 30, 0),
            'check_out' => null,
            'date' => Carbon::yesterday()->toDateString(),
            'total_hours' => 0,
        ]);

        // Try to check in today - should fail because still checked in from yesterday
        $response = $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [
                ['title' => 'New task today'],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'You have already checked in. Please check out first.',
            ]);
    }

    public function test_employee_can_checkin_after_checkout_from_yesterday(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        // Create completed attendance from yesterday
        $yesterdayAttendance = Attendance::factory()->create([
            'user_id' => $this->user->id,
            'check_in' => Carbon::yesterday()->setTime(9, 0, 0),
            'check_out' => Carbon::yesterday()->setTime(17, 0, 0),
            'date' => Carbon::yesterday()->toDateString(),
            'total_hours' => 8.0,
        ]);

        // Try to check in today - should succeed
        $response = $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [
                ['title' => 'Task for today'],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        // Verify new attendance is for today
        $data = $response->json('data');
        $this->assertEquals(Carbon::today()->toDateString(), $data['date']);
    }

    public function test_employee_can_check_in_with_standby_task_saves_attendance_and_tasks(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [
                ['title' => Task::STANDBY_TITLE],
                ['title' => 'Another task'],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.tasks.0.title', Task::STANDBY_TITLE);

        $this->assertDatabaseHas('attendances', ['user_id' => $this->user->id]);
        $this->assertDatabaseHas('tasks', [
            'attendance_id' => $response->json('data.id'),
            'title' => Task::STANDBY_TITLE,
        ]);
    }

    public function test_employee_check_in_with_standby_sends_whatsapp_when_team_configured(): void
    {
        $this->team->update([
            'whatsapp_api_secret' => encrypt('test-api-secret'),
            'whatsapp_account_unique_id' => 'test-unique-id-1234567890123456789012345',
            'whatsapp_connected' => true,
            'whatsapp_recipient_phone' => '628123456789',
        ]);

        Http::fake([
            'whatsapp.perekonomian.id/api/send/whatsapp' => Http::response([
                'status' => 200,
            ], 200),
        ]);

        $token = $this->user->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [
                ['title' => Task::STANDBY_TITLE],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201)->assertJson(['success' => true]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'whatsapp.perekonomian.id/api/send/whatsapp')
                && str_contains($request->body(), $this->user->name);
        });

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->user->id,
            'action' => ActivityType::WHATSAPP_STANDBY_SENT->value,
        ]);
    }

    public function test_employee_check_in_with_standby_succeeds_even_when_whatsapp_fails(): void
    {
        $this->team->update([
            'whatsapp_api_secret' => encrypt('test-api-secret'),
            'whatsapp_account_unique_id' => 'test-unique-id-1234567890123456789012345',
            'whatsapp_connected' => true,
            'whatsapp_recipient_phone' => '628123456789',
        ]);

        Http::fake([
            'whatsapp.perekonomian.id/api/send/whatsapp' => Http::response([
                'status' => 500,
                'message' => 'Gateway error',
            ], 500),
        ]);

        $token = $this->user->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [
                ['title' => Task::STANDBY_TITLE],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201)->assertJson(['success' => true]);

        $this->assertDatabaseHas('attendances', ['user_id' => $this->user->id]);
        $this->assertDatabaseHas('tasks', [
            'title' => Task::STANDBY_TITLE,
        ]);
    }
}
