<?php

namespace Tests\Feature;

use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\Leave;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\CreatesTeamUsers;

class ManagerLeaveTest extends TestCase
{
    use CreatesTeamUsers, RefreshDatabase;

    private User $manager;

    private User $employee;

    private Leave $leaveRequest;

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

        $this->leaveRequest = $this->createLeave([
            'user_id' => $this->employee->id,
            'start_date' => Carbon::tomorrow(),
            'end_date' => Carbon::tomorrow()->addDays(2),
            'status' => LeaveStatus::PENDING,
        ]);
    }

    public function test_manager_can_list_all_leave_requests(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        // Create additional leave requests
        $this->createLeave([
            'user_id' => $this->employee->id,
            'status' => LeaveStatus::APPROVED,
        ]);

        $this->createLeave([
            'user_id' => $this->employee->id,
            'status' => LeaveStatus::REJECTED,
        ]);

        $response = $this->getJson('/api/v1/manager/leaves', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'user_id',
                        'start_date',
                        'end_date',
                        'reason',
                        'status',
                    ],
                ],
            ])
            ->assertJson(['success' => true]);
    }

    public function test_manager_can_filter_leave_requests_by_status(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/leaves?status=pending', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_manager_can_approve_leave_request(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/leaves/{$this->leaveRequest->id}/approve", [
            'notes' => 'Approved. Take care and get well soon.',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'status',
                    'approved_by',
                    'approved_at',
                    'notes',
                ],
                'message',
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => LeaveStatus::APPROVED->value,
                ],
            ]);

        $this->assertDatabaseHas('leaves', [
            'id' => $this->leaveRequest->id,
            'status' => LeaveStatus::APPROVED->value,
            'approved_by' => $this->manager->id,
        ]);
    }

    public function test_manager_can_reject_leave_request(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/leaves/{$this->leaveRequest->id}/reject", [
            'notes' => 'We have a critical deadline during this period. Can you reschedule?',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => LeaveStatus::REJECTED->value,
                ],
            ]);

        $this->assertDatabaseHas('leaves', [
            'id' => $this->leaveRequest->id,
            'status' => LeaveStatus::REJECTED->value,
            'approved_by' => $this->manager->id,
        ]);
    }

    public function test_employee_cannot_access_manager_leave_endpoints(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/manager/leaves', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_employee_cannot_approve_leave(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/leaves/{$this->leaveRequest->id}/approve", [
            'notes' => 'Employee trying to approve',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_manager_can_edit_leave_dates(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $newStart = Carbon::tomorrow()->addDays(5)->format('Y-m-d');
        $newEnd = Carbon::tomorrow()->addDays(7)->format('Y-m-d');

        $response = $this->putJson("/api/v1/manager/leaves/{$this->leaveRequest->id}", [
            'start_date' => $newStart,
            'end_date' => $newEnd,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $this->leaveRequest->id,
                    'start_date' => $newStart,
                    'end_date' => $newEnd,
                ],
            ]);

        $leave = Leave::find($this->leaveRequest->id);
        $this->assertEquals($newStart, $leave->start_date->format('Y-m-d'));
        $this->assertEquals($newEnd, $leave->end_date->format('Y-m-d'));
    }

    public function test_manager_can_edit_leave_created_at(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $newCreatedAt = '2026-01-15T10:30:00';

        $response = $this->putJson("/api/v1/manager/leaves/{$this->leaveRequest->id}", [
            'created_at' => $newCreatedAt,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_manager_can_edit_approved_leave(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $approvedLeave = $this->createLeave([
            'user_id' => $this->employee->id,
            'start_date' => Carbon::tomorrow()->addDays(10),
            'end_date' => Carbon::tomorrow()->addDays(12),
            'status' => LeaveStatus::APPROVED,
            'approved_by' => $this->manager->id,
            'approved_at' => Carbon::now(),
        ]);

        $newApprovedAt = '2026-02-01T14:00:00';

        $response = $this->putJson("/api/v1/manager/leaves/{$approvedLeave->id}", [
            'approved_at' => $newApprovedAt,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_manager_can_edit_rejected_leave(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $rejectedLeave = $this->createLeave([
            'user_id' => $this->employee->id,
            'start_date' => Carbon::tomorrow()->addDays(20),
            'end_date' => Carbon::tomorrow()->addDays(21),
            'status' => LeaveStatus::REJECTED,
            'approved_by' => $this->manager->id,
            'approved_at' => Carbon::now(),
        ]);

        $newStart = Carbon::tomorrow()->addDays(25)->format('Y-m-d');

        $response = $this->putJson("/api/v1/manager/leaves/{$rejectedLeave->id}", [
            'start_date' => $newStart,
            'end_date' => Carbon::tomorrow()->addDays(26)->format('Y-m-d'),
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_manager_cannot_edit_leave_from_other_team(): void
    {
        $otherEmployee = User::factory()->create([
            'role' => UserRole::EMPLOYEE,
        ]);

        $otherLeave = Leave::factory()->create([
            'user_id' => $otherEmployee->id,
            'status' => LeaveStatus::PENDING,
        ]);

        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/leaves/{$otherLeave->id}", [
            'start_date' => Carbon::tomorrow()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->addDay()->format('Y-m-d'),
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_employee_cannot_edit_leave(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/leaves/{$this->leaveRequest->id}", [
            'start_date' => Carbon::tomorrow()->format('Y-m-d'),
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_edit_leave_validates_end_date_after_start_date(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $response = $this->putJson("/api/v1/manager/leaves/{$this->leaveRequest->id}", [
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-05',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422);
    }

    public function test_edit_leave_creates_activity_log(): void
    {
        $token = $this->manager->createToken('auth-token')->plainTextToken;

        $newStart = Carbon::tomorrow()->addDays(5)->format('Y-m-d');

        $this->putJson("/api/v1/manager/leaves/{$this->leaveRequest->id}", [
            'start_date' => $newStart,
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->manager->id,
            'action' => 'leave_edited',
        ]);
    }
}
