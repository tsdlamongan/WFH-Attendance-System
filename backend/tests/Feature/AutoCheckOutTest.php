<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Models\Attendance;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use Tests\Traits\CreatesTeamUsers;

class AutoCheckOutTest extends TestCase
{
    use RefreshDatabase, CreatesTeamUsers;

    private User $employee;

    private AttendanceService $attendanceService;

    private AttendanceRepository $attendanceRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = $this->createTeam(['required_work_hours' => 7.0]);
        $this->employee = $this->createEmployee();
        $this->attendanceService = app(AttendanceService::class);
        $this->attendanceRepository = app(AttendanceRepository::class);
    }

    public function test_auto_checkout_after_exceeding_required_hours(): void
    {
        $checkInTime = Carbon::now()->subHours(8);
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $checkInTime,
            'check_out' => null,
            'date' => $checkInTime->toDateString(),
            'total_hours' => 0,
        ]);

        Task::create([
            'attendance_id' => $attendance->id,
            'title' => 'Task A',
            'is_completed' => false,
            'blocker_reason' => null,
        ]);

        $result = $this->attendanceService->autoCheckOut($attendance);

        $this->assertNotNull($result->check_out);
        $this->assertTrue($result->is_auto_checkout);
        $this->assertGreaterThanOrEqual(7, $result->total_hours);
    }

    public function test_not_auto_checkout_before_reaching_required_hours(): void
    {
        $checkInTime = Carbon::now()->subHours(5);
        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $checkInTime,
            'check_out' => null,
            'date' => $checkInTime->toDateString(),
            'total_hours' => 0,
        ]);

        $exceeding = $this->attendanceRepository->findAllActiveExceedingHours();

        $this->assertCount(0, $exceeding);
    }

    public function test_incomplete_tasks_marked_with_default_blocker(): void
    {
        $checkInTime = Carbon::now()->subHours(8);
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $checkInTime,
            'check_out' => null,
            'date' => $checkInTime->toDateString(),
            'total_hours' => 0,
        ]);

        $taskA = Task::create([
            'attendance_id' => $attendance->id,
            'title' => 'Incomplete Task',
            'is_completed' => false,
            'blocker_reason' => null,
        ]);

        $taskB = Task::create([
            'attendance_id' => $attendance->id,
            'title' => 'Completed Task',
            'is_completed' => true,
            'blocker_reason' => null,
        ]);

        $taskC = Task::create([
            'attendance_id' => $attendance->id,
            'title' => 'Task with existing blocker',
            'is_completed' => false,
            'blocker_reason' => 'waiting for review',
        ]);

        $this->attendanceService->autoCheckOut($attendance);

        $this->assertEquals('belum selesai', $taskA->fresh()->blocker_reason);
        $this->assertNull($taskB->fresh()->blocker_reason);
        $this->assertEquals('waiting for review', $taskC->fresh()->blocker_reason);
    }

    public function test_is_auto_checkout_flag_set_to_true(): void
    {
        $checkInTime = Carbon::now()->subHours(8);
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $checkInTime,
            'check_out' => null,
            'date' => $checkInTime->toDateString(),
            'total_hours' => 0,
        ]);

        $this->attendanceService->autoCheckOut($attendance);

        $updated = Attendance::find($attendance->id);
        $this->assertTrue($updated->is_auto_checkout);
        $this->assertFalse($updated->isActive());
    }

    public function test_activity_log_created_with_auto_checkout_type(): void
    {
        $checkInTime = Carbon::now()->subHours(8);
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $checkInTime,
            'check_out' => null,
            'date' => $checkInTime->toDateString(),
            'total_hours' => 0,
        ]);

        $this->attendanceService->autoCheckOut($attendance);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->employee->id,
            'action' => ActivityType::AUTO_CHECKOUT->value,
        ]);
    }

    public function test_employee_can_check_in_again_after_auto_checkout(): void
    {
        $checkInTime = Carbon::now()->subHours(8);
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $checkInTime,
            'check_out' => null,
            'date' => $checkInTime->toDateString(),
            'total_hours' => 0,
        ]);

        $this->attendanceService->autoCheckOut($attendance);

        $token = $this->employee->createToken('auth-token')->plainTextToken;

        $response = $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [
                ['title' => 'Overtime task'],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $newAttendance = Attendance::where('user_id', $this->employee->id)
            ->whereNull('check_out')
            ->latest('id')
            ->first();

        $this->assertNotNull($newAttendance);
        $this->assertNotEquals($attendance->id, $newAttendance->id);
    }

    public function test_teams_with_different_required_work_hours(): void
    {
        $teamShort = $this->createTeam(['required_work_hours' => 4.0]);
        $teamLong = $this->createTeam(['required_work_hours' => 10.0]);

        $employeeShort = User::factory()->create([
            'team_id' => $teamShort->id,
            'role' => 'employee',
        ]);
        $employeeLong = User::factory()->create([
            'team_id' => $teamLong->id,
            'role' => 'employee',
        ]);

        $checkInTime = Carbon::now()->subHours(5);

        Attendance::factory()->create([
            'user_id' => $employeeShort->id,
            'check_in' => $checkInTime,
            'check_out' => null,
            'date' => $checkInTime->toDateString(),
            'total_hours' => 0,
        ]);

        Attendance::factory()->create([
            'user_id' => $employeeLong->id,
            'check_in' => $checkInTime,
            'check_out' => null,
            'date' => $checkInTime->toDateString(),
            'total_hours' => 0,
        ]);

        $exceeding = $this->attendanceRepository->findAllActiveExceedingHours();

        $userIds = $exceeding->pluck('user_id')->toArray();
        $this->assertContains($employeeShort->id, $userIds);
        $this->assertNotContains($employeeLong->id, $userIds);
    }

    public function test_artisan_command_auto_checks_out_exceeding_attendances(): void
    {
        $checkInTime = Carbon::now()->subHours(8);
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $checkInTime,
            'check_out' => null,
            'date' => $checkInTime->toDateString(),
            'total_hours' => 0,
        ]);

        Task::create([
            'attendance_id' => $attendance->id,
            'title' => 'Some task',
            'is_completed' => false,
            'blocker_reason' => null,
        ]);

        Artisan::call('attendance:auto-checkout');

        $updated = Attendance::find($attendance->id);
        $this->assertNotNull($updated->check_out);
        $this->assertTrue($updated->is_auto_checkout);
    }

    public function test_artisan_command_skips_already_checked_out(): void
    {
        $checkInTime = Carbon::now()->subHours(8);
        $checkOutTime = Carbon::now()->subHours(1);
        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $checkInTime,
            'check_out' => $checkOutTime,
            'date' => $checkInTime->toDateString(),
            'total_hours' => 7,
        ]);

        $exceeding = $this->attendanceRepository->findAllActiveExceedingHours();

        $this->assertCount(0, $exceeding);
    }

    public function test_today_status_includes_auto_checkout_data(): void
    {
        $checkInTime = Carbon::now()->subHours(8);
        $attendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $checkInTime,
            'check_out' => null,
            'date' => Carbon::today()->toDateString(),
            'total_hours' => 0,
        ]);

        $this->attendanceService->autoCheckOut($attendance);

        $token = $this->employee->createToken('auth-token')->plainTextToken;

        $response = $this->getJson('/api/v1/attendance/today', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertFalse($data['is_checked_in']);
        $this->assertNotNull($data['last_auto_checkout']);
        $this->assertEquals($attendance->id, $data['last_auto_checkout']['id']);

        $autoSession = collect($data['previous_sessions'])->firstWhere('is_auto_checkout', true);
        $this->assertNotNull($autoSession);
    }

    public function test_auto_checkout_second_session_when_total_daily_hours_exceed_required(): void
    {
        $today = Carbon::today();

        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $today->copy()->setTime(9, 0),
            'check_out' => $today->copy()->setTime(12, 0),
            'date' => $today->toDateString(),
            'total_hours' => 3.0,
        ]);

        $secondCheckIn = Carbon::now()->subHours(4)->subMinutes(5);
        $activeAttendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $secondCheckIn,
            'check_out' => null,
            'date' => $today->toDateString(),
            'total_hours' => 0,
        ]);

        Task::create([
            'attendance_id' => $activeAttendance->id,
            'title' => 'Second session task',
            'is_completed' => false,
            'blocker_reason' => null,
        ]);

        $exceeding = $this->attendanceRepository->findAllActiveExceedingHours();

        $this->assertCount(1, $exceeding);
        $this->assertEquals($activeAttendance->id, $exceeding->first()->id);

        $result = $this->attendanceService->autoCheckOut($activeAttendance);

        $this->assertNotNull($result->check_out);
        $this->assertTrue($result->is_auto_checkout);
    }

    public function test_no_auto_checkout_second_session_when_total_daily_hours_below_required(): void
    {
        $today = Carbon::today();

        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $today->copy()->setTime(9, 0),
            'check_out' => $today->copy()->setTime(11, 0),
            'date' => $today->toDateString(),
            'total_hours' => 2.0,
        ]);

        $secondCheckIn = Carbon::now()->subHours(2);
        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $secondCheckIn,
            'check_out' => null,
            'date' => $today->toDateString(),
            'total_hours' => 0,
        ]);

        $exceeding = $this->attendanceRepository->findAllActiveExceedingHours();

        $this->assertCount(0, $exceeding);
    }

    public function test_artisan_command_auto_checks_out_multi_session(): void
    {
        $today = Carbon::today();

        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $today->copy()->setTime(9, 0),
            'check_out' => $today->copy()->setTime(12, 0),
            'date' => $today->toDateString(),
            'total_hours' => 3.0,
        ]);

        $secondCheckIn = Carbon::now()->subHours(4)->subMinutes(5);
        $activeAttendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $secondCheckIn,
            'check_out' => null,
            'date' => $today->toDateString(),
            'total_hours' => 0,
        ]);

        Task::create([
            'attendance_id' => $activeAttendance->id,
            'title' => 'Multi-session task',
            'is_completed' => false,
            'blocker_reason' => null,
        ]);

        Artisan::call('attendance:auto-checkout');

        $updated = Attendance::find($activeAttendance->id);
        $this->assertNotNull($updated->check_out);
        $this->assertTrue($updated->is_auto_checkout);
        $this->assertEquals('belum selesai', $updated->tasks->first()->blocker_reason);
    }

    public function test_auto_checkout_multi_session_activity_log_includes_daily_total(): void
    {
        $today = Carbon::today();

        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $today->copy()->setTime(9, 0),
            'check_out' => $today->copy()->setTime(12, 0),
            'date' => $today->toDateString(),
            'total_hours' => 3.0,
        ]);

        $secondCheckIn = Carbon::now()->subHours(4)->subMinutes(5);
        $activeAttendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $secondCheckIn,
            'check_out' => null,
            'date' => $today->toDateString(),
            'total_hours' => 0,
        ]);

        $this->attendanceService->autoCheckOut($activeAttendance);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->employee->id,
            'action' => ActivityType::AUTO_CHECKOUT->value,
        ]);

        $log = \App\Models\ActivityLog::where('user_id', $this->employee->id)
            ->where('action', ActivityType::AUTO_CHECKOUT->value)
            ->latest()
            ->first();

        $this->assertStringContainsString('daily total:', $log->description);
        $this->assertStringContainsString('required: 7', $log->description);
    }

    public function test_no_auto_checkout_overtime_session_when_required_hours_already_met(): void
    {
        $today = Carbon::today();

        // Session 1: 7h30m - already exceeds required 7h
        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $today->copy()->setTime(8, 0),
            'check_out' => $today->copy()->setTime(15, 30),
            'date' => $today->toDateString(),
            'total_hours' => 7.5,
        ]);

        // Session 2 (overtime): 32 minutes active - should NOT be auto-checked-out
        $overtimeCheckIn = Carbon::now()->subMinutes(32);
        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $overtimeCheckIn,
            'check_out' => null,
            'date' => $today->toDateString(),
            'total_hours' => 0,
        ]);

        $exceeding = $this->attendanceRepository->findAllActiveExceedingHours();

        $this->assertCount(0, $exceeding);
    }

    public function test_no_auto_checkout_third_session_overtime_after_auto_checkout(): void
    {
        $today = Carbon::today();

        // Session 1: 3h
        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $today->copy()->setTime(9, 0),
            'check_out' => $today->copy()->setTime(12, 0),
            'date' => $today->toDateString(),
            'total_hours' => 3.0,
        ]);

        // Session 2: 4h30m (auto-checked-out, total now 7h30m)
        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $today->copy()->setTime(13, 0),
            'check_out' => $today->copy()->setTime(17, 30),
            'date' => $today->toDateString(),
            'total_hours' => 4.5,
            'is_auto_checkout' => true,
        ]);

        // Session 3 (overtime): 45 minutes active - should NOT be auto-checked-out
        $overtimeCheckIn = Carbon::now()->subMinutes(45);
        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $overtimeCheckIn,
            'check_out' => null,
            'date' => $today->toDateString(),
            'total_hours' => 0,
        ]);

        $exceeding = $this->attendanceRepository->findAllActiveExceedingHours();

        $this->assertCount(0, $exceeding);
    }

    public function test_artisan_command_skips_overtime_sessions(): void
    {
        $today = Carbon::today();

        // Session 1: already met required hours
        Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $today->copy()->setTime(8, 0),
            'check_out' => $today->copy()->setTime(15, 30),
            'date' => $today->toDateString(),
            'total_hours' => 7.5,
        ]);

        // Session 2 (overtime): should not be touched
        $overtimeCheckIn = Carbon::now()->subMinutes(45);
        $overtimeAttendance = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'check_in' => $overtimeCheckIn,
            'check_out' => null,
            'date' => $today->toDateString(),
            'total_hours' => 0,
        ]);

        Artisan::call('attendance:auto-checkout');

        $updated = Attendance::find($overtimeAttendance->id);
        $this->assertNull($updated->check_out);
        $this->assertFalse($updated->is_auto_checkout);
    }
}
