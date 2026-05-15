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

class LeaveTest extends TestCase
{
    use RefreshDatabase, CreatesTeamUsers;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = User::factory()->create([
            'email' => 'employee@example.com',
            'password' => Hash::make('password123'),
            'role' => UserRole::EMPLOYEE,
            'leave_quota_days' => 12,
        ]);
    }

    public function test_employee_can_request_leave(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/leaves', [
            'start_date' => Carbon::tomorrow()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->addDays(2)->format('Y-m-d'),
            'reason' => 'Family emergency - need to travel to hometown',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'user_id',
                    'start_date',
                    'end_date',
                    'reason',
                    'status',
                ],
                'message',
            ])
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('leaves', [
            'user_id' => $this->employee->id,
            'status' => LeaveStatus::PENDING->value,
        ]);
    }

    public function test_employee_cannot_request_leave_with_invalid_dates(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/leaves', [
            'start_date' => Carbon::yesterday()->format('Y-m-d'),
            'end_date' => Carbon::yesterday()->subDay()->format('Y-m-d'),
            'reason' => 'Short reason',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422);
    }

    public function test_employee_cannot_request_leave_with_short_reason(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/leaves', [
            'start_date' => Carbon::tomorrow()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->addDay()->format('Y-m-d'),
            'reason' => 'Short',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422);
    }

    public function test_employee_can_view_my_leave_requests(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        // Create leave requests
        $this->createLeave([
            'user_id' => $this->employee->id,
            'status' => LeaveStatus::PENDING,
        ]);

        $this->createLeave([
            'user_id' => $this->employee->id,
            'status' => LeaveStatus::APPROVED,
        ]);

        $response = $this->getJson('/api/v1/leaves/my-requests', [
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

    public function test_employee_cannot_check_in_when_on_approved_leave(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        // Create approved leave for today
        $this->createLeave([
            'user_id' => $this->employee->id,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today(),
            'status' => LeaveStatus::APPROVED,
        ]);

        $response = $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [
                ['title' => 'Task 1'],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_manager_cannot_access_employee_leave_endpoints(): void
    {
        $manager = User::factory()->create([
            'role' => UserRole::MANAGER,
        ]);
        $token = $manager->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/leaves', [
            'start_date' => Carbon::tomorrow()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->addDay()->format('Y-m-d'),
            'reason' => 'Manager trying to request leave',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403);
    }

    public function test_employee_cannot_exceed_annual_leave_quota(): void
    {
        Carbon::setTestNow(Carbon::createFromDate(2025, 1, 10));

        try {
            $token = $this->employee->createToken('auth-token')->plainTextToken;
            $this->employee->update(['leave_quota_days' => 8]);

            $firstStart = Carbon::now()->addDays(5); // 2025-01-15
            $secondStart = Carbon::now()->addDays(35); // 2025-02-14

            $this->createLeave([
                'user_id' => $this->employee->id,
                'start_date' => $firstStart,
                'end_date' => $firstStart->copy()->addDays(3), // 4 days
                'status' => LeaveStatus::APPROVED,
            ]);

            $this->createLeave([
                'user_id' => $this->employee->id,
                'start_date' => $secondStart,
                'end_date' => $secondStart->copy()->addDays(2), // 3 days
                'status' => LeaveStatus::APPROVED,
            ]);

            // Try to request 2 more days (total would be 9, exceeding quota of 8)
            $response = $this->postJson('/api/v1/leaves', [
                'start_date' => Carbon::now()->addDays(70)->format('Y-m-d'), // 2025-03-21
                'end_date' => Carbon::now()->addDays(71)->format('Y-m-d'),
                'reason' => 'This should exceed the annual quota limit',
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

    public function test_employee_cannot_exceed_monthly_leave_limit(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        // Create approved leave for 3 days in next month
        $nextMonth = Carbon::now()->addMonth()->startOfMonth();
        $this->createLeave([
            'user_id' => $this->employee->id,
            'start_date' => $nextMonth->copy()->addDays(1),
            'end_date' => $nextMonth->copy()->addDays(3),
            'status' => LeaveStatus::APPROVED,
        ]);

        // Try to request 5 more days in the same month (would exceed monthly limit of 5)
        $response = $this->postJson('/api/v1/leaves', [
            'start_date' => $nextMonth->copy()->addDays(10)->format('Y-m-d'),
            'end_date' => $nextMonth->copy()->addDays(14)->format('Y-m-d'),
            'reason' => 'This should exceed the monthly limit of 5 days per month',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
        
        $this->assertStringContainsString('Batas cuti bulanan terlampaui', $response->json('message'));
    }

    public function test_employee_cannot_request_overlapping_leave(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        // Create existing leave - use a far future date and keep it minimal
        $startDate = Carbon::now()->addMonths(8)->startOfMonth()->addDays(15);
        $endDate = $startDate->copy(); // Only 1 day
        
        $this->createLeave([
            'user_id' => $this->employee->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => LeaveStatus::APPROVED,
        ]);

        // Try to request overlapping leave (exact same day) - keep it minimal
        $response = $this->postJson('/api/v1/leaves', [
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $startDate->format('Y-m-d'),
            'reason' => 'This should overlap with existing leave',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
        
        $this->assertStringContainsString('sudah memiliki pengajuan cuti', $response->json('message'));
    }

    public function test_employee_can_view_leave_summary(): void
    {
        $token = $this->employee->createToken('auth-token')->plainTextToken;

        // Create some leaves
        $this->createLeave([
            'user_id' => $this->employee->id,
            'start_date' => Carbon::now()->startOfYear()->addDays(10),
            'end_date' => Carbon::now()->startOfYear()->addDays(12),
            'status' => LeaveStatus::APPROVED,
        ]);

        $this->createLeave([
            'user_id' => $this->employee->id,
            'start_date' => Carbon::now()->startOfYear()->addDays(20),
            'end_date' => Carbon::now()->startOfYear()->addDays(21),
            'status' => LeaveStatus::PENDING,
        ]);

        $response = $this->getJson('/api/v1/leaves/summary', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'year',
                    'total_quota',
                    'used_days',
                    'pending_days',
                    'remaining_days',
                    'max_per_month',
                ],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'total_quota' => 12,
                    'used_days' => 3,
                    'pending_days' => 2,
                    'remaining_days' => 7,
                ],
            ]);
    }

    public function test_pending_leaves_count_toward_quota(): void
    {
        // Freeze time to mid-year to ensure all dates are in the future and same year
        Carbon::setTestNow(Carbon::create(2024, 5, 1));

        $token = $this->employee->createToken('auth-token')->plainTextToken;

        // Create pending leave for 10 days in June
        $this->createLeave([
            'user_id' => $this->employee->id,
            'start_date' => Carbon::create(2024, 6, 1),
            'end_date' => Carbon::create(2024, 6, 10),
            'status' => LeaveStatus::PENDING,
        ]);

        // Try to request 5 more days in July (would exceed quota of 12)
        $response = $this->postJson('/api/v1/leaves', [
            'start_date' => '2024-07-01',
            'end_date' => '2024-07-05',
            'reason' => 'This should exceed quota when including pending leaves',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertStringContainsString('Jatah cuti tidak mencukupi', $response->json('message'));

        // Reset time
        Carbon::setTestNow();
    }

    public function test_disabled_employee_cannot_request_leave(): void
    {
        $disabled = $this->createDisabledEmployee();
        $token = $disabled->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/leaves', [
            'start_date' => Carbon::tomorrow()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->addDays(1)->format('Y-m-d'),
            'reason' => 'Sakit dan butuh istirahat panjang',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'dinonaktifkan'));
    }
}
