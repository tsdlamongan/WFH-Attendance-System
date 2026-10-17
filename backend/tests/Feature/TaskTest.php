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

class TaskTest extends TestCase
{
    use CreatesTeamUsers, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'employee@example.com',
            'password' => Hash::make('password123'),
            'role' => UserRole::EMPLOYEE,
        ]);
    }

    public function test_employee_can_add_tasks_to_active_session(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        // Check in first
        $checkInResponse = $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [
                ['title' => 'Initial Task'],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $attendanceId = $checkInResponse->json('data.id');

        // Add more tasks
        $response = $this->postJson('/api/v1/tasks/add', [
            'attendance_id' => $attendanceId,
            'tasks' => [
                ['title' => 'Additional Task 1'],
                ['title' => 'Additional Task 2'],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'tasks',
                ],
            ]);
    }

    public function test_employee_can_view_incomplete_tasks(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        // Create attendance with incomplete task
        $attendance = Attendance::factory()->create([
            'user_id' => $this->user->id,
            'check_in' => Carbon::now(),
            'check_out' => null,
            'date' => Carbon::today(),
        ]);

        $attendance->tasks()->create([
            'title' => 'Incomplete Task',
            'is_completed' => false,
        ]);

        $response = $this->getJson('/api/v1/tasks/incomplete', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
            ]);
    }

    public function test_employee_cannot_add_tasks_to_completed_attendance(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        // Create completed attendance
        $attendance = Attendance::factory()->create([
            'user_id' => $this->user->id,
            'check_in' => Carbon::now()->subHours(9),
            'check_out' => Carbon::now()->subHour(),
            'date' => Carbon::today(),
            'total_hours' => 8.0,
        ]);

        $response = $this->postJson('/api/v1/tasks/add', [
            'attendance_id' => $attendance->id,
            'tasks' => [
                ['title' => 'New Task'],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422);
    }

    public function test_employee_cannot_add_tasks_to_other_user_attendance(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        $otherUser = User::factory()->create(['role' => UserRole::EMPLOYEE]);

        // Create attendance for other user
        $attendance = Attendance::factory()->create([
            'user_id' => $otherUser->id,
            'check_in' => Carbon::now(),
            'check_out' => null,
            'date' => Carbon::today(),
        ]);

        $response = $this->postJson('/api/v1/tasks/add', [
            'attendance_id' => $attendance->id,
            'tasks' => [
                ['title' => 'New Task'],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422);
    }

    public function test_add_tasks_validation_requires_attendance_id(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/tasks/add', [
            'tasks' => [
                ['title' => 'Task 1'],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['attendance_id']);
    }

    public function test_add_tasks_validation_requires_tasks(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        $checkIn = $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [
                ['title' => 'Initial Task'],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $attendanceId = $checkIn->json('data.id');

        $response = $this->postJson('/api/v1/tasks/add', [
            'attendance_id' => $attendanceId,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tasks']);
    }

    public function test_employee_can_view_incomplete_tasks_from_last_session_only(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        // Create old attendance (3 days ago) with incomplete task - should NOT appear
        $oldAttendance = Attendance::factory()->create([
            'user_id' => $this->user->id,
            'check_in' => Carbon::now()->subDays(3)->setTime(9, 0, 0),
            'check_out' => Carbon::now()->subDays(3)->setTime(17, 0, 0),
            'date' => Carbon::now()->subDays(3)->toDateString(),
            'total_hours' => 8.0,
        ]);

        $oldAttendance->tasks()->create([
            'title' => 'Old Incomplete Task',
            'is_completed' => false,
            'blocker_reason' => 'Old blocker',
        ]);

        // Create last session (yesterday) with incomplete tasks - SHOULD appear
        $lastAttendance = Attendance::factory()->create([
            'user_id' => $this->user->id,
            'check_in' => Carbon::yesterday()->setTime(9, 0, 0),
            'check_out' => Carbon::yesterday()->setTime(17, 0, 0),
            'date' => Carbon::yesterday()->toDateString(),
            'total_hours' => 8.0,
        ]);

        $lastAttendance->tasks()->create([
            'title' => 'Recent Incomplete Task 1',
            'is_completed' => false,
            'blocker_reason' => 'Waiting for API',
        ]);

        $lastAttendance->tasks()->create([
            'title' => 'Recent Incomplete Task 2',
            'is_completed' => false,
            'blocker_reason' => 'Need review',
        ]);

        $lastAttendance->tasks()->create([
            'title' => 'Completed Task',
            'is_completed' => true,
            'blocker_reason' => null,
        ]);

        $response = $this->getJson('/api/v1/tasks/incomplete-last-session', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonCount(2, 'data'); // Only 2 incomplete tasks from last session

        // Verify the correct tasks are returned
        $data = $response->json('data');
        $this->assertEquals('Recent Incomplete Task 1', $data[0]['title']);
        $this->assertEquals('Recent Incomplete Task 2', $data[1]['title']);
    }

    public function test_incomplete_tasks_from_last_session_returns_empty_when_no_previous_attendance(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/tasks/incomplete-last-session', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonCount(0, 'data');
    }

    public function test_incomplete_tasks_from_last_session_excludes_active_attendance(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        // Create completed last session
        $lastAttendance = Attendance::factory()->create([
            'user_id' => $this->user->id,
            'check_in' => Carbon::yesterday()->setTime(9, 0, 0),
            'check_out' => Carbon::yesterday()->setTime(17, 0, 0),
            'date' => Carbon::yesterday()->toDateString(),
            'total_hours' => 8.0,
        ]);

        $lastAttendance->tasks()->create([
            'title' => 'Task from last session',
            'is_completed' => false,
        ]);

        // Create active attendance (no check_out) - should be EXCLUDED
        $activeAttendance = Attendance::factory()->create([
            'user_id' => $this->user->id,
            'check_in' => Carbon::now(),
            'check_out' => null,
            'date' => Carbon::today()->toDateString(),
        ]);

        $activeAttendance->tasks()->create([
            'title' => 'Task from active session',
            'is_completed' => false,
        ]);

        $response = $this->getJson('/api/v1/tasks/incomplete-last-session', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data'); // Only from last COMPLETED session

        $data = $response->json('data');
        $this->assertEquals('Task from last session', $data[0]['title']);
    }

    public function test_incomplete_tasks_from_last_session_only_shows_user_own_tasks(): void
    {
        $token = $this->user->createToken('auth-token')->plainTextToken;

        $otherUser = User::factory()->create(['role' => UserRole::EMPLOYEE]);

        // Create attendance for other user
        $otherAttendance = Attendance::factory()->create([
            'user_id' => $otherUser->id,
            'check_in' => Carbon::yesterday()->setTime(9, 0, 0),
            'check_out' => Carbon::yesterday()->setTime(17, 0, 0),
            'date' => Carbon::yesterday()->toDateString(),
            'total_hours' => 8.0,
        ]);

        $otherAttendance->tasks()->create([
            'title' => 'Other user task',
            'is_completed' => false,
        ]);

        // Create attendance for current user
        $myAttendance = Attendance::factory()->create([
            'user_id' => $this->user->id,
            'check_in' => Carbon::yesterday()->setTime(9, 0, 0),
            'check_out' => Carbon::yesterday()->setTime(17, 0, 0),
            'date' => Carbon::yesterday()->toDateString(),
            'total_hours' => 8.0,
        ]);

        $myAttendance->tasks()->create([
            'title' => 'My task',
            'is_completed' => false,
        ]);

        $response = $this->getJson('/api/v1/tasks/incomplete-last-session', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data'); // Only my tasks

        $data = $response->json('data');
        $this->assertEquals('My task', $data[0]['title']);
    }
}
