<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Models\Attendance;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\CreatesTeamUsers;

class ManagerTaskTest extends TestCase
{
    use CreatesTeamUsers, RefreshDatabase;

    private User $manager;

    private User $employee;

    private Attendance $attendance;

    private Task $task;

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
            'check_out' => null,
            'date' => Carbon::parse('2024-01-15'),
            'total_hours' => 0,
        ]);

        $this->task = Task::factory()->create([
            'attendance_id' => $this->attendance->id,
            'title' => 'Complete feature X',
            'is_completed' => false,
            'blocker_reason' => 'Waiting for API credentials',
        ]);
    }

    public function test_manager_can_update_task_to_completed(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/tasks/{$this->task->id}", [
            'is_completed' => true,
            'blocker_reason' => null,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Task updated successfully',
            ]);

        $this->assertDatabaseHas('tasks', [
            'id' => $this->task->id,
            'is_completed' => true,
            'blocker_reason' => null,
        ]);
    }

    public function test_manager_can_update_task_to_incomplete_with_blocker_reason(): void
    {
        $completedTask = Task::factory()->create([
            'attendance_id' => $this->attendance->id,
            'title' => 'Another task',
            'is_completed' => true,
            'blocker_reason' => null,
        ]);

        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/tasks/{$completedTask->id}", [
            'is_completed' => false,
            'blocker_reason' => 'Found critical bug that needs to be fixed first',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Task updated successfully',
            ]);

        $this->assertDatabaseHas('tasks', [
            'id' => $completedTask->id,
            'is_completed' => false,
            'blocker_reason' => 'Found critical bug that needs to be fixed first',
        ]);
    }

    public function test_manager_cannot_update_incomplete_task_without_blocker_reason(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/tasks/{$this->task->id}", [
            'is_completed' => false,
            'blocker_reason' => null,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Alasan kendala wajib diisi untuk tugas yang belum selesai.',
            ]);
    }

    public function test_manager_cannot_update_incomplete_task_with_empty_blocker_reason(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/tasks/{$this->task->id}", [
            'is_completed' => false,
            'blocker_reason' => '',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Alasan kendala wajib diisi untuk tugas yang belum selesai.',
            ]);
    }

    public function test_manager_cannot_update_nonexistent_task(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson('/api/v1/manager/tasks/99999', [
            'is_completed' => true,
            'blocker_reason' => null,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Task not found',
            ]);
    }

    public function test_task_update_creates_activity_log(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $this->putJson("/api/v1/manager/tasks/{$this->task->id}", [
            'is_completed' => true,
            'blocker_reason' => null,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->manager->id,
            'action' => ActivityType::TASK_UPDATED->value,
        ]);
    }

    public function test_employee_cannot_update_task(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/tasks/{$this->task->id}", [
            'is_completed' => true,
            'blocker_reason' => null,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_update_task(): void
    {
        $response = $this->putJson("/api/v1/manager/tasks/{$this->task->id}", [
            'is_completed' => true,
            'blocker_reason' => null,
        ]);

        $response->assertStatus(401);
    }

    public function test_manager_can_update_blocker_reason_for_incomplete_task(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/tasks/{$this->task->id}", [
            'is_completed' => false,
            'blocker_reason' => 'Updated blocker reason - waiting for code review',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('tasks', [
            'id' => $this->task->id,
            'is_completed' => false,
            'blocker_reason' => 'Updated blocker reason - waiting for code review',
        ]);
    }

    public function test_blocker_reason_is_cleared_when_task_marked_completed(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/tasks/{$this->task->id}", [
            'is_completed' => true,
            'blocker_reason' => 'This should be ignored',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('tasks', [
            'id' => $this->task->id,
            'is_completed' => true,
            'blocker_reason' => null,
        ]);
    }

    public function test_validation_fails_for_missing_is_completed(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/tasks/{$this->task->id}", [
            'blocker_reason' => 'Some reason',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'message',
                'errors',
            ]);
    }

    public function test_validation_fails_for_invalid_is_completed_type(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/tasks/{$this->task->id}", [
            'is_completed' => 'not-a-boolean',
            'blocker_reason' => 'Some reason',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422);
    }
}
