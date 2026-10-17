<?php

use App\Enums\ActivityType;
use App\Enums\LeaveStatus;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveQuota;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Repositories\ActivityLogRepository;
use App\Repositories\AttendanceRepository;
use App\Repositories\TaskRepository;
use App\Services\ActivityLogService;
use App\Services\AttendanceService;
use App\Services\LeaveService;
use App\Services\OvertimeService;
use App\Services\RepeatedTaskAlertService;
use App\Services\WhatsAppGatewayService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

/*
 * Direct tests for AttendanceService, LeaveService and ActivityLogService.
 *
 * "Now" is frozen at Wednesday 11 March 2026 14:00 (Asia/Jakarta) so elapsed hours,
 * "today" lookups and the leave year are deterministic.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(Carbon::parse('2026-03-11 14:00:00'));
    $this->team = $this->createTeam(['required_work_hours' => 7.0, 'max_leave_days_per_month' => 5]);
});

function svcRequest(): Request
{
    return Request::create('/api/v1/test', 'POST', [], [], [], [
        'REMOTE_ADDR' => '198.51.100.4',
        'HTTP_USER_AGENT' => 'PestAgent/1.0',
    ]);
}

function svcAttendance(User $user, string $checkIn, ?string $checkOut = null, ?float $totalHours = null, bool $auto = false): Attendance
{
    $in = Carbon::parse($checkIn);
    $out = $checkOut ? Carbon::parse($checkOut) : null;

    return Attendance::create([
        'user_id' => $user->id,
        'check_in' => $in,
        'check_out' => $out,
        'date' => $in->toDateString(),
        'total_hours' => $totalHours ?? ($out ? round($in->diffInMinutes($out) / 60, 2) : 0),
        'is_auto_checkout' => $auto,
    ]);
}

function svcTask(Attendance $attendance, string $title, bool $completed = false, ?string $blocker = null): Task
{
    return Task::create([
        'attendance_id' => $attendance->id,
        'title' => $title,
        'is_completed' => $completed,
        'blocker_reason' => $blocker,
    ]);
}

function svcLeave(User $user, string $start, string $end, LeaveStatus $status = LeaveStatus::PENDING): Leave
{
    return Leave::create([
        'user_id' => $user->id,
        'start_date' => $start,
        'end_date' => $end,
        'reason' => 'Existing leave',
        'status' => $status,
    ]);
}

/**
 * Replace the activity log service with one that always fails, to exercise the rollback paths.
 */
function svcBreakActivityLogging(): void
{
    $logger = Mockery::mock(ActivityLogService::class);
    $logger->shouldReceive('logActivity')->andThrow(new RuntimeException('log store down'));

    app()->instance(ActivityLogService::class, $logger);
}

// ---------------------------------------------------------------------------
// ActivityLogService
// ---------------------------------------------------------------------------

describe('ActivityLogService', function () {
    it('records the ip address and user agent of the request', function () {
        $user = $this->createEmployee();

        app(ActivityLogService::class)->logActivity($user, ActivityType::LOGIN, 'Logged in', svcRequest());

        $this->assertDatabaseHas('activity_logs', [
            'team_id' => $this->team->id,
            'user_id' => $user->id,
            'action' => 'login',
            'description' => 'Logged in',
            'ip_address' => '198.51.100.4',
            'user_agent' => 'PestAgent/1.0',
        ]);
    });

    it('records no request metadata when there is no request', function () {
        $user = $this->createEmployee();

        app(ActivityLogService::class)->logActivity($user, ActivityType::LOGOUT, 'Logged out');

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'logout',
            'ip_address' => null,
            'user_agent' => null,
        ]);
    });

    it('logs console activity as the system without an ip address', function () {
        $user = $this->createEmployee();

        app(ActivityLogService::class)->logActivitySimple($user, ActivityType::AUTO_CHECKOUT, 'Auto checkout by scheduler');

        $this->assertDatabaseHas('activity_logs', [
            'team_id' => $this->team->id,
            'user_id' => $user->id,
            'action' => 'auto_checkout',
            'description' => 'Auto checkout by scheduler',
            'ip_address' => null,
            'user_agent' => 'System',
            'created_at' => '2026-03-11 14:00:00',
        ]);
    });

    it('delegates to the repository', function () {
        $user = $this->createEmployee();
        $this->mock(ActivityLogRepository::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('create')
                ->once()
                ->with($user, ActivityType::LOGIN, 'Via repository', '198.51.100.4', 'PestAgent/1.0');
        });

        app(ActivityLogService::class)->logActivity($user, ActivityType::LOGIN, 'Via repository', svcRequest());
    });
});

// ---------------------------------------------------------------------------
// AttendanceService
// ---------------------------------------------------------------------------

describe('AttendanceService', function () {
    beforeEach(function () {
        $this->employee = $this->createEmployee(['name' => 'Alice']);
    });

    it('checks an employee in with tasks and logs the activity', function () {
        $attendance = app(AttendanceService::class)->checkIn($this->employee, [
            ['title' => 'Write report'],
            ['title' => 'Fix bug'],
        ], svcRequest());

        expect($attendance->exists)->toBeTrue()
            ->and($attendance->user_id)->toBe($this->employee->id)
            ->and($attendance->check_in->format('Y-m-d H:i:s'))->toBe('2026-03-11 14:00:00')
            ->and($attendance->check_out)->toBeNull()
            ->and($attendance->date->toDateString())->toBe('2026-03-11')
            ->and((float) $attendance->total_hours)->toBe(0.0)
            ->and($attendance->relationLoaded('tasks'))->toBeTrue()
            ->and($attendance->tasks->pluck('title')->sort()->values()->all())->toBe(['Fix bug', 'Write report'])
            ->and($attendance->tasks->every(fn (Task $task) => $task->is_completed === false))->toBeTrue();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->employee->id,
            'action' => 'check_in',
            'description' => 'User checked in with 2 tasks',
            'ip_address' => '198.51.100.4',
            'user_agent' => 'PestAgent/1.0',
        ]);
    });

    it('notifies the manager over WhatsApp when the check-in contains a standby task', function () {
        $this->team->update([
            'whatsapp_api_secret' => 'test-api-secret',
            'whatsapp_account_unique_id' => 'test-unique-id',
            'whatsapp_connected' => true,
            'whatsapp_recipient_phone' => '628123456789',
        ]);
        Http::fake(['whatsapp.perekonomian.id/api/send/whatsapp' => Http::response(['status' => 200], 200)]);

        // The standby title is matched case-insensitively and ignoring surrounding whitespace.
        app(AttendanceService::class)->checkIn($this->employee, [['title' => '  STANDBY '], ['title' => 'Docs']]);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'whatsapp.perekonomian.id/api/send/whatsapp')
            && str_contains($request->body(), 'Alice check-in dalam mode standby')
            && str_contains($request->body(), '14:00 WIB - 11 Mar 2026')
            && str_contains($request->body(), 'STANDBY, Docs'));

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->employee->id,
            'action' => 'whatsapp_standby_sent',
            'description' => 'Standby notification sent to manager via WhatsApp',
        ]);
    });

    it('does not send a WhatsApp message without a standby task or without a connected team', function () {
        Http::fake();
        $service = app(AttendanceService::class);

        // Connected team, but no standby task.
        $this->team->update([
            'whatsapp_api_secret' => 'test-api-secret',
            'whatsapp_account_unique_id' => 'test-unique-id',
            'whatsapp_connected' => true,
            'whatsapp_recipient_phone' => '628123456789',
        ]);
        $service->checkIn($this->employee, [['title' => 'Regular work']]);
        $service->checkOut($this->employee, Attendance::latest('id')->first()->id, []);

        // Standby task, but the team is no longer connected.
        $this->team->update(['whatsapp_connected' => false]);
        $service->checkIn($this->employee->fresh(), [['title' => 'Standby']]);

        Http::assertNothingSent();
        $this->assertDatabaseMissing('activity_logs', ['action' => 'whatsapp_standby_sent']);
    });

    it('still completes the check-in when the standby notification fails', function () {
        $this->team->update([
            'whatsapp_api_secret' => 'test-api-secret',
            'whatsapp_account_unique_id' => 'test-unique-id',
            'whatsapp_connected' => true,
            'whatsapp_recipient_phone' => '628123456789',
        ]);
        Http::fake(['whatsapp.perekonomian.id/api/send/whatsapp' => Http::response(['status' => 500, 'message' => 'Gateway error'], 500)]);
        Log::spy();

        $attendance = app(AttendanceService::class)->checkIn($this->employee, [['title' => 'Standby']]);

        expect($attendance->exists)->toBeTrue()
            ->and($attendance->tasks)->toHaveCount(1);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'whatsapp_standby_sent']);
        Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_starts_with($message, 'Standby WhatsApp notification failed'))->once();
    });

    it('refuses to check in a disabled account', function () {
        $disabled = $this->createDisabledEmployee();

        expect(fn () => app(AttendanceService::class)->checkIn($disabled, [['title' => 'Work']]))
            ->toThrow(Exception::class, 'Akun anda telah dinonaktifkan.');

        $this->assertDatabaseCount('attendances', 0);
    });

    it('refuses a second check-in while a session is still open', function () {
        svcAttendance($this->employee, '2026-03-11 09:00:00');

        expect(fn () => app(AttendanceService::class)->checkIn($this->employee, [['title' => 'Work']]))
            ->toThrow(Exception::class, 'You have already checked in. Please check out first.');

        $this->assertDatabaseCount('attendances', 1);
    });

    it('refuses to check in on a holiday of the team but not on another teams holiday', function () {
        $otherTeam = $this->createTeam();
        Holiday::create(['team_id' => $otherTeam->id, 'date' => '2026-03-11', 'name' => 'Other team day']);
        Holiday::create(['team_id' => $this->team->id, 'date' => '2026-03-11', 'name' => 'Founders Day']);

        expect(fn () => app(AttendanceService::class)->checkIn($this->employee, [['title' => 'Work']]))
            ->toThrow(Exception::class, 'Today is a holiday: Founders Day. You cannot check in.');

        $this->assertDatabaseCount('attendances', 0);

        Holiday::where('team_id', $this->team->id)->delete();
        $attendance = app(AttendanceService::class)->checkIn($this->employee, [['title' => 'Work']]);

        expect($attendance->exists)->toBeTrue();
    });

    it('refuses to check in during an approved leave but ignores pending and rejected ones', function () {
        svcLeave($this->employee, '2026-03-10', '2026-03-12', LeaveStatus::PENDING);
        svcLeave($this->employee, '2026-03-10', '2026-03-12', LeaveStatus::REJECTED);

        expect(app(AttendanceService::class)->checkIn($this->employee, [['title' => 'Work']])->exists)->toBeTrue();

        $onLeave = $this->createEmployee();
        svcLeave($onLeave, '2026-03-10', '2026-03-12', LeaveStatus::APPROVED);

        expect(fn () => app(AttendanceService::class)->checkIn($onLeave, [['title' => 'Work']]))
            ->toThrow(Exception::class, 'You are currently on approved leave. You cannot check in.');
        expect(Attendance::where('user_id', $onLeave->id)->count())->toBe(0);
    });

    it('rolls the whole check-in back when logging the activity fails', function () {
        Log::spy();
        svcBreakActivityLogging();

        expect(fn () => app(AttendanceService::class)->checkIn($this->employee, [['title' => 'Work']]))
            ->toThrow(RuntimeException::class, 'log store down');

        $this->assertDatabaseCount('attendances', 0);
        $this->assertDatabaseCount('tasks', 0);
        Log::shouldHaveReceived('error')->once()->with('Check-in failed: log store down');
    });

    it('checks out, calculates the hours, updates the tasks and logs the activity', function () {
        $attendance = svcAttendance($this->employee, '2026-03-11 10:00:00');
        $done = svcTask($attendance, 'Done');
        $blocked = svcTask($attendance, 'Blocked');

        $result = app(AttendanceService::class)->checkOut($this->employee, $attendance->id, [
            ['id' => $done->id, 'is_completed' => true],
            ['id' => $blocked->id, 'is_completed' => false, 'blocker_reason' => 'Waiting for API'],
        ], svcRequest());

        expect($result->check_out->format('Y-m-d H:i:s'))->toBe('2026-03-11 14:00:00')
            ->and((float) $result->total_hours)->toBe(4.0)
            ->and($result->is_auto_checkout)->toBeFalse()
            ->and($result->relationLoaded('tasks'))->toBeTrue();

        $this->assertDatabaseHas('tasks', ['id' => $done->id, 'is_completed' => true, 'blocker_reason' => null]);
        $this->assertDatabaseHas('tasks', ['id' => $blocked->id, 'is_completed' => false, 'blocker_reason' => 'Waiting for API']);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->employee->id,
            'action' => 'check_out',
            'description' => 'User checked out. Total hours: 4',
            'ip_address' => '198.51.100.4',
        ]);
    });

    it('checks out without task updates and logs a warning', function () {
        Log::spy();
        $attendance = svcAttendance($this->employee, '2026-03-11 12:30:00');
        $task = svcTask($attendance, 'Untouched');

        $result = app(AttendanceService::class)->checkOut($this->employee, $attendance->id, []);

        expect((float) $result->total_hours)->toBe(1.5)
            ->and($result->check_out)->not->toBeNull();
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'is_completed' => false, 'blocker_reason' => null]);
        Log::shouldHaveReceived('warning')->once()->with("Check-out without task updates for attendance {$attendance->id}");
    });

    it('refuses to check out an unknown attendance or one that belongs to someone else', function () {
        $colleague = $this->createEmployee();
        $foreign = svcAttendance($colleague, '2026-03-11 10:00:00');
        $service = app(AttendanceService::class);

        expect(fn () => $service->checkOut($this->employee, 999999, []))
            ->toThrow(Exception::class, 'Attendance record not found or does not belong to you.')
            ->and(fn () => $service->checkOut($this->employee, $foreign->id, []))
            ->toThrow(Exception::class, 'Attendance record not found or does not belong to you.');

        expect($foreign->fresh()->check_out)->toBeNull();
    });

    it('refuses to check out twice', function () {
        $attendance = svcAttendance($this->employee, '2026-03-11 09:00:00', '2026-03-11 12:00:00');

        expect(fn () => app(AttendanceService::class)->checkOut($this->employee, $attendance->id, []))
            ->toThrow(Exception::class, 'You have already checked out.');

        expect($attendance->fresh()->check_out->format('H:i'))->toBe('12:00');
    });

    it('rolls the check-out back when the task update or logging fails', function () {
        Log::spy();
        $attendance = svcAttendance($this->employee, '2026-03-11 10:00:00');
        svcTask($attendance, 'Task');
        svcBreakActivityLogging();

        expect(fn () => app(AttendanceService::class)->checkOut($this->employee, $attendance->id, []))
            ->toThrow(RuntimeException::class, 'log store down');

        $fresh = $attendance->fresh();
        expect($fresh->check_out)->toBeNull()
            ->and((float) $fresh->total_hours)->toBe(0.0);
        Log::shouldHaveReceived('error')->once()->with('Check-out failed: log store down');
    });

    it('auto checks out a session, flags it and annotates incomplete tasks without a blocker', function () {
        $attendance = svcAttendance($this->employee, '2026-03-11 06:00:00');
        $open = svcTask($attendance, 'Open');
        $blocked = svcTask($attendance, 'Blocked', false, 'Waiting for review');
        $done = svcTask($attendance, 'Done', true);

        $result = app(AttendanceService::class)->autoCheckOut($attendance);

        expect($result->check_out->format('Y-m-d H:i:s'))->toBe('2026-03-11 14:00:00')
            ->and((float) $result->total_hours)->toBe(8.0)
            ->and($result->is_auto_checkout)->toBeTrue()
            ->and($result->tasks->pluck('blocker_reason', 'title')->all())->toBe([
                'Open' => 'belum selesai',
                'Blocked' => 'Waiting for review',
                'Done' => null,
            ]);

        $log = ActivityLog::where('user_id', $this->employee->id)->where('action', ActivityType::AUTO_CHECKOUT)->first();
        expect($log->description)->toBe('Auto checkout - session: 8h, daily total: 8h (required: 7h)');
    });

    it('adds the sessions completed earlier the same day to the auto checkout summary', function () {
        svcAttendance($this->employee, '2026-03-11 06:00:00', '2026-03-11 08:00:00', 2.0);
        $attendance = svcAttendance($this->employee, '2026-03-11 09:00:00');

        app(AttendanceService::class)->autoCheckOut($attendance);

        $log = ActivityLog::where('user_id', $this->employee->id)->where('action', ActivityType::AUTO_CHECKOUT)->first();
        expect($log->description)->toBe('Auto checkout - session: 5h, daily total: 7h (required: 7h)');
    });

    it('auto checks out a user without a team', function () {
        $teamless = User::factory()->create(['team_id' => null, 'role' => 'employee']);
        $attendance = svcAttendance($teamless, '2026-03-11 12:00:00');

        $result = app(AttendanceService::class)->autoCheckOut($attendance);

        expect($result->is_auto_checkout)->toBeTrue();
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $teamless->id,
            'action' => 'auto_checkout',
            'description' => 'Auto checkout - session: 2h, daily total: 2h (required: h)',
        ]);
    });

    it('refuses to auto check out an attendance that is already checked out', function () {
        $attendance = svcAttendance($this->employee, '2026-03-11 06:00:00', '2026-03-11 09:00:00');

        expect(fn () => app(AttendanceService::class)->autoCheckOut($attendance))
            ->toThrow(Exception::class, "Attendance #{$attendance->id} is already checked out.");

        $this->assertDatabaseMissing('attendances', ['id' => $attendance->id, 'is_auto_checkout' => true]);
        $this->assertDatabaseCount('activity_logs', 0);
    });

    it('rolls an auto checkout back when logging fails', function () {
        Log::spy();
        $attendance = svcAttendance($this->employee, '2026-03-11 06:00:00');
        $task = svcTask($attendance, 'Open');
        svcBreakActivityLogging();

        expect(fn () => app(AttendanceService::class)->autoCheckOut($attendance))
            ->toThrow(RuntimeException::class, 'log store down');

        $fresh = $attendance->fresh();
        expect($fresh->check_out)->toBeNull()
            ->and($fresh->is_auto_checkout)->toBeFalse()
            ->and($task->fresh()->blocker_reason)->toBeNull();
        Log::shouldHaveReceived('error')->once()->with("Auto checkout failed for attendance #{$attendance->id}: log store down");
    });

    it('calculates total hours in hours rounded to two decimals', function (string $in, string $out, float $expected) {
        $hours = app(AttendanceService::class)->calculateTotalHours(Carbon::parse($in), Carbon::parse($out));

        expect($hours)->toBe($expected);
    })->with([
        'ninety minutes' => ['2026-03-11 09:00:00', '2026-03-11 10:30:00', 1.5],
        'twenty minutes' => ['2026-03-11 09:00:00', '2026-03-11 09:20:00', 0.33],
        'zero minutes' => ['2026-03-11 09:00:00', '2026-03-11 09:00:00', 0.0],
        'across midnight' => ['2026-03-10 22:00:00', '2026-03-11 02:15:00', 4.25],
    ]);

    it('reports an idle status when the user has not checked in today', function () {
        $status = app(AttendanceService::class)->getTodayStatus($this->employee);

        expect($status['date'])->toBe('2026-03-11')
            ->and($status['is_checked_in'])->toBeFalse()
            ->and($status['current_session'])->toBeNull()
            ->and($status['today_total_hours'])->toEqual(0)
            ->and($status['required_hours'])->toBe(7.0)
            ->and($status['remaining_hours'])->toEqual(7.0)
            ->and($status['previous_sessions']->all())->toBe([])
            ->and($status['last_auto_checkout'])->toBeNull();
    });

    it('reports the active session, previous sessions and the last auto checkout', function () {
        $earlier = svcAttendance($this->employee, '2026-03-11 06:00:00', '2026-03-11 08:00:00', 2.0);
        $auto = svcAttendance($this->employee, '2026-03-11 09:00:00', '2026-03-11 10:00:00', 1.0, true);
        $active = svcAttendance($this->employee, '2026-03-11 12:30:00');
        svcTask($active, 'Active task', false, 'Blocked');
        svcTask($active, 'Finished task', true);

        $status = app(AttendanceService::class)->getTodayStatus($this->employee);

        expect($status['is_checked_in'])->toBeTrue()
            ->and($status['today_total_hours'])->toEqual(4.5) // 2 + 1 + 1.5 elapsed
            ->and($status['remaining_hours'])->toEqual(2.5)
            ->and($status['current_session']['id'])->toBe($active->id)
            ->and($status['current_session']['elapsed_hours'])->toBe(1.5)
            ->and($status['current_session']['tasks'])->toHaveCount(2)
            ->and($status['current_session']['tasks'][0]['title'])->toBe('Active task')
            ->and($status['current_session']['tasks'][0]['blocker_reason'])->toBe('Blocked')
            ->and($status['current_session']['tasks'][1]['is_completed'])->toBeTrue()
            ->and($status['previous_sessions'])->toHaveCount(2)
            ->and($status['previous_sessions']->pluck('is_auto_checkout')->all())->toBe([false, true])
            ->and($status['last_auto_checkout']['id'])->toBe($auto->id)
            ->and($status['last_auto_checkout']['check_out']->format('H:i'))->toBe('10:00')
            ->and($earlier->exists)->toBeTrue();
    });

    it('never reports negative remaining hours', function () {
        svcAttendance($this->employee, '2026-03-11 02:00:00', '2026-03-11 10:00:00', 8.0);

        $status = app(AttendanceService::class)->getTodayStatus($this->employee);

        expect($status['is_checked_in'])->toBeFalse()
            ->and($status['today_total_hours'])->toEqual(8.0)
            ->and($status['remaining_hours'])->toEqual(0);
    });

    it('uses the default required hours for a user without a team', function () {
        $teamless = User::factory()->create(['team_id' => null, 'role' => 'employee']);

        $status = app(AttendanceService::class)->getTodayStatus($teamless);

        expect($status['required_hours'])->toBe(Team::DEFAULT_REQUIRED_WORK_HOURS)
            ->and($status['remaining_hours'])->toEqual(7.0);
    });

    it('loads the tasks of the active attendance when the repository did not eager load them', function () {
        $active = svcAttendance($this->employee, '2026-03-11 13:00:00');
        svcTask($active, 'Lazy task');
        $bare = Attendance::find($active->id);

        expect($bare->relationLoaded('tasks'))->toBeFalse();

        $repository = Mockery::mock(AttendanceRepository::class);
        $repository->shouldReceive('findActiveByUser')->once()->andReturn($bare);
        $repository->shouldReceive('getAllByUserAndDate')->once()->andReturn(new EloquentCollection([$bare]));

        $service = new AttendanceService(
            $repository,
            app(TaskRepository::class),
            app(ActivityLogService::class),
            app(WhatsAppGatewayService::class),
            app(OvertimeService::class),
            app(RepeatedTaskAlertService::class),
        );

        $status = $service->getTodayStatus($this->employee);

        expect($bare->relationLoaded('tasks'))->toBeTrue()
            ->and($status['current_session']['tasks'])->toHaveCount(1)
            ->and($status['current_session']['tasks'][0]['title'])->toBe('Lazy task')
            ->and($status['previous_sessions'])->toHaveCount(0);
    });
});

// ---------------------------------------------------------------------------
// LeaveService
// ---------------------------------------------------------------------------

describe('LeaveService', function () {
    beforeEach(function () {
        $this->leaves = app(LeaveService::class);
        $this->employee = $this->createEmployee(['name' => 'Alice', 'leave_quota_days' => 12]);
        $this->manager = $this->createManager(['name' => 'Boss']);
    });

    it('creates a pending leave request and logs it', function () {
        $leave = $this->leaves->requestLeave($this->employee, [
            'start_date' => '2026-03-16',
            'end_date' => '2026-03-18',
            'reason' => 'Family trip',
        ], svcRequest());

        expect($leave->exists)->toBeTrue()
            ->and($leave->status)->toBe(LeaveStatus::PENDING)
            ->and($leave->isPending())->toBeTrue()
            ->and($leave->start_date->toDateString())->toBe('2026-03-16')
            ->and($leave->end_date->toDateString())->toBe('2026-03-18')
            ->and($leave->relationLoaded('user'))->toBeTrue()
            ->and($leave->user->is($this->employee))->toBeTrue();

        $this->assertDatabaseHas('leaves', ['id' => $leave->id, 'user_id' => $this->employee->id, 'reason' => 'Family trip', 'status' => 'pending']);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->employee->id,
            'action' => 'leave_requested',
            'description' => 'User requested leave from 2026-03-16 to 2026-03-18 (3 days)',
            'ip_address' => '198.51.100.4',
        ]);
    });

    it('refuses leave requests from a disabled account', function () {
        $disabled = $this->createDisabledEmployee();

        expect(fn () => $this->leaves->requestLeave($disabled, ['start_date' => '2026-03-16', 'end_date' => '2026-03-16', 'reason' => 'Rest']))
            ->toThrow(Exception::class, 'Akun anda telah dinonaktifkan.');

        $this->assertDatabaseCount('leaves', 0);
    });

    it('rejects a request that exceeds the annual quota and reports the usage', function () {
        $user = $this->createEmployee(['leave_quota_days' => 5]);
        svcLeave($user, '2026-02-02', '2026-02-03', LeaveStatus::APPROVED);
        svcLeave($user, '2026-03-03', '2026-03-03', LeaveStatus::PENDING);

        expect(fn () => $this->leaves->requestLeave($user, ['start_date' => '2026-03-16', 'end_date' => '2026-03-18', 'reason' => 'Trip']))
            ->toThrow(Exception::class, 'Jatah cuti tidak mencukupi. Anda memiliki sisa 2 hari dari total 5 hari jatah cuti tahunan. (Terpakai: 2, Menunggu Persetujuan: 1, Diajukan: 3)');

        expect(Leave::where('user_id', $user->id)->count())->toBe(2);
    });

    it('allows a request that uses exactly the remaining quota', function () {
        $user = $this->createEmployee(['leave_quota_days' => 5]);
        svcLeave($user, '2026-02-02', '2026-02-03', LeaveStatus::APPROVED);

        $leave = $this->leaves->requestLeave($user, ['start_date' => '2026-03-16', 'end_date' => '2026-03-18', 'reason' => 'Trip']);

        expect($leave->exists)->toBeTrue();
    });

    it('does not count leaves of other years or rejected leaves against the annual quota', function () {
        $user = $this->createEmployee(['leave_quota_days' => 5]);
        svcLeave($user, '2025-11-03', '2025-11-14', LeaveStatus::APPROVED);
        svcLeave($user, '2026-01-05', '2026-01-09', LeaveStatus::REJECTED);

        $leave = $this->leaves->requestLeave($user, ['start_date' => '2026-03-16', 'end_date' => '2026-03-20', 'reason' => 'Trip']);

        expect($leave->exists)->toBeTrue();
    });

    it('prefers the yearly leave quota record over the user default', function () {
        $user = $this->createEmployee(['leave_quota_days' => 12]);
        LeaveQuota::create(['user_id' => $user->id, 'year' => 2026, 'quota_days' => 2]);

        expect(fn () => $this->leaves->requestLeave($user, ['start_date' => '2026-03-16', 'end_date' => '2026-03-18', 'reason' => 'Trip']))
            ->toThrow(Exception::class, 'dari total 2 hari jatah cuti tahunan');
    });

    it('rejects a request that exceeds the monthly limit', function () {
        svcLeave($this->employee, '2026-03-02', '2026-03-05', LeaveStatus::APPROVED);

        expect(fn () => $this->leaves->requestLeave($this->employee, ['start_date' => '2026-03-09', 'end_date' => '2026-03-10', 'reason' => 'Trip']))
            ->toThrow(Exception::class, 'Batas cuti bulanan terlampaui untuk bulan Maret 2026. Maksimal 5 hari per bulan. Anda sudah memiliki 4 hari di bulan ini, dan mengajukan 2 hari lagi.');
    });

    it('only counts the part of an existing leave that falls inside the month', function () {
        // 27 Feb - 2 Mar: only 1 and 2 March belong to March.
        svcLeave($this->employee, '2026-02-27', '2026-03-02', LeaveStatus::APPROVED);

        expect(fn () => $this->leaves->requestLeave($this->employee, ['start_date' => '2026-03-09', 'end_date' => '2026-03-12', 'reason' => 'Trip']))
            ->toThrow(Exception::class, 'Anda sudah memiliki 2 hari di bulan ini, dan mengajukan 4 hari lagi.');

        // 3 days still fit: 2 existing + 3 requested = 5.
        $leave = $this->leaves->requestLeave($this->employee, ['start_date' => '2026-03-09', 'end_date' => '2026-03-11', 'reason' => 'Trip']);
        expect($leave->exists)->toBeTrue();
    });

    it('does not count rejected leaves against the monthly limit', function () {
        svcLeave($this->employee, '2026-03-02', '2026-03-06', LeaveStatus::REJECTED);

        $leave = $this->leaves->requestLeave($this->employee, ['start_date' => '2026-03-09', 'end_date' => '2026-03-13', 'reason' => 'Trip']);

        expect($leave->exists)->toBeTrue();
    });

    it('checks each month a request spans against the team monthly limit', function () {
        $team = $this->createTeam(['max_leave_days_per_month' => 3]);
        $user = $this->createUser(['team_id' => $team->id, 'leave_quota_days' => 12]);

        // 31 March is a single day (allowed), 1-4 April is 4 days (too many for a limit of 3).
        expect(fn () => $this->leaves->requestLeave($user, ['start_date' => '2026-03-31', 'end_date' => '2026-04-04', 'reason' => 'Long trip']))
            ->toThrow(Exception::class, 'Batas cuti bulanan terlampaui untuk bulan April 2026. Maksimal 3 hari per bulan. Anda sudah memiliki 0 hari di bulan ini, dan mengajukan 4 hari lagi.');

        expect(Leave::where('user_id', $user->id)->count())->toBe(0);
    });

    it('accepts a single-month request that exactly fills the monthly limit', function () {
        // 16-20 March is exactly 5 days, the limit of the team.
        $leave = $this->leaves->requestLeave($this->employee, ['start_date' => '2026-03-16', 'end_date' => '2026-03-20', 'reason' => 'Trip']);

        expect($leave->exists)->toBeTrue();
    });

    it('rejects a single-month request one day over the limit with whole-day numbers in the message', function () {
        expect(fn () => $this->leaves->requestLeave($this->employee, ['start_date' => '2026-03-16', 'end_date' => '2026-03-21', 'reason' => 'Trip']))
            ->toThrow(Exception::class, 'Batas cuti bulanan terlampaui untuk bulan Maret 2026. Maksimal 5 hari per bulan. Anda sudah memiliki 0 hari di bulan ini, dan mengajukan 6 hari lagi.');

        $this->assertDatabaseCount('leaves', 0);
    });

    it('accepts a cross-month request whose months each stay within the limit, even when a month hits it exactly', function (int $limit, string $start, string $end) {
        $team = $this->createTeam(['max_leave_days_per_month' => $limit]);
        $user = $this->createUser(['team_id' => $team->id, 'leave_quota_days' => 100]);

        $leave = $this->leaves->requestLeave($user, ['start_date' => $start, 'end_date' => $end, 'reason' => 'Long trip']);

        expect($leave->exists)->toBeTrue()
            ->and($leave->start_date->toDateString())->toBe($start)
            ->and($leave->end_date->toDateString())->toBe($end);
    })->with([
        'first month exactly at the limit (3 + 2 days)' => [3, '2026-03-29', '2026-04-02'],
        'first month exactly at the limit, short second month (2 + 1 days)' => [2, '2026-02-27', '2026-03-01'],
        'last month exactly at the limit (1 + 3 days)' => [3, '2026-03-31', '2026-04-03'],
        'a whole 31-day month in the middle (2 + 31 + 2 days)' => [31, '2026-02-27', '2026-04-02'],
        'across a year end (2 + 3 days)' => [3, '2026-12-30', '2027-01-03'],
        'across a leap day (2 + 2 days)' => [2, '2028-02-28', '2028-03-01'],
    ]);

    it('reports the exact whole-day count of the first month that exceeds the limit', function (int $limit, string $start, string $end, string $month, int $requested) {
        $team = $this->createTeam(['max_leave_days_per_month' => $limit]);
        $user = $this->createUser(['team_id' => $team->id, 'leave_quota_days' => 100]);

        expect(fn () => $this->leaves->requestLeave($user, ['start_date' => $start, 'end_date' => $end, 'reason' => 'Long trip']))
            ->toThrow(Exception::class, "Batas cuti bulanan terlampaui untuk bulan {$month}. Maksimal {$limit} hari per bulan. Anda sudah memiliki 0 hari di bulan ini, dan mengajukan {$requested} hari lagi.");

        expect(Leave::where('user_id', $user->id)->count())->toBe(0);
    })->with([
        'first month is over (4 + 2 days)' => [3, '2026-03-28', '2026-04-02', 'Maret 2026', 4],
        'last month is over (1 + 4 days)' => [3, '2026-03-31', '2026-04-04', 'April 2026', 4],
        'middle month is over (2 + 31 + 2 days)' => [30, '2026-02-27', '2026-04-02', 'Maret 2026', 31],
        'new year month is over (2 + 4 days)' => [3, '2026-12-30', '2027-01-04', 'Januari 2027', 4],
    ]);

    it('counts a last month that holds only its first day', function () {
        // 31 March - 1 April: April gets one day, which does not fit next to the 5 days already approved there.
        svcLeave($this->employee, '2026-04-06', '2026-04-10', LeaveStatus::APPROVED);

        expect(fn () => $this->leaves->requestLeave($this->employee, ['start_date' => '2026-03-31', 'end_date' => '2026-04-01', 'reason' => 'Short trip']))
            ->toThrow(Exception::class, 'Batas cuti bulanan terlampaui untuk bulan April 2026. Maksimal 5 hari per bulan. Anda sudah memiliki 5 hari di bulan ini, dan mengajukan 1 hari lagi.');

        // With only 4 April days already approved the same request fits (4 + 1 = 5).
        Leave::query()->delete();
        svcLeave($this->employee, '2026-04-06', '2026-04-09', LeaveStatus::APPROVED);

        expect($this->leaves->requestLeave($this->employee, ['start_date' => '2026-03-31', 'end_date' => '2026-04-01', 'reason' => 'Short trip'])->exists)->toBeTrue();
    });

    it('adds the existing days of each month to the days requested in it', function () {
        // 30 March - 2 April approved: 2 days in March, 2 in April.
        svcLeave($this->employee, '2026-03-30', '2026-04-02', LeaveStatus::APPROVED);

        // March: 2 existing + 4 requested (24-27 March) = 6 > 5.
        expect(fn () => $this->leaves->requestLeave($this->employee, ['start_date' => '2026-03-24', 'end_date' => '2026-03-27', 'reason' => 'Trip']))
            ->toThrow(Exception::class, 'Batas cuti bulanan terlampaui untuk bulan Maret 2026. Maksimal 5 hari per bulan. Anda sudah memiliki 2 hari di bulan ini, dan mengajukan 4 hari lagi.');

        // April: 2 existing + 3 requested (20-22 April) = 5, exactly the limit.
        expect($this->leaves->requestLeave($this->employee, ['start_date' => '2026-04-20', 'end_date' => '2026-04-22', 'reason' => 'Trip'])->exists)->toBeTrue();
    });

    it('accepts a request that spans two months when each month stays within the limit', function () {
        $leave = $this->leaves->requestLeave($this->employee, ['start_date' => '2026-03-30', 'end_date' => '2026-04-03', 'reason' => 'Long trip']);

        expect($leave->exists)->toBeTrue()
            ->and($leave->start_date->toDateString())->toBe('2026-03-30')
            ->and($leave->end_date->toDateString())->toBe('2026-04-03');
    });

    it('applies the default monthly limit to a user without a team', function () {
        $teamless = User::factory()->create(['team_id' => null, 'role' => 'employee', 'leave_quota_days' => 12]);

        expect(fn () => $this->leaves->requestLeave($teamless, ['start_date' => '2026-03-16', 'end_date' => '2026-03-21', 'reason' => 'Trip']))
            ->toThrow(Exception::class, 'Maksimal '.Team::DEFAULT_MAX_LEAVE_DAYS_PER_MONTH.' hari per bulan.');
    });

    it('rejects a request that overlaps a pending or approved leave', function () {
        $team = $this->createTeam(['max_leave_days_per_month' => 20]);
        $user = $this->createUser(['team_id' => $team->id, 'leave_quota_days' => 12]);
        svcLeave($user, '2026-03-16', '2026-03-18', LeaveStatus::PENDING);
        svcLeave($user, '2026-03-24', '2026-03-26', LeaveStatus::APPROVED);

        $overlapMessage = 'Anda sudah memiliki pengajuan cuti untuk rentang tanggal ini.';

        // Partial overlap at the end, at the start, and fully surrounding an existing leave.
        expect(fn () => $this->leaves->requestLeave($user, ['start_date' => '2026-03-17', 'end_date' => '2026-03-19', 'reason' => 'x']))
            ->toThrow(Exception::class, $overlapMessage)
            ->and(fn () => $this->leaves->requestLeave($user, ['start_date' => '2026-03-14', 'end_date' => '2026-03-16', 'reason' => 'x']))
            ->toThrow(Exception::class, $overlapMessage)
            ->and(fn () => $this->leaves->requestLeave($user, ['start_date' => '2026-03-23', 'end_date' => '2026-03-27', 'reason' => 'x']))
            ->toThrow(Exception::class, $overlapMessage)
            ->and(fn () => $this->leaves->requestLeave($user, ['start_date' => '2026-03-25', 'end_date' => '2026-03-25', 'reason' => 'x']))
            ->toThrow(Exception::class, $overlapMessage);

        // Adjacent dates and rejected leaves do not block.
        svcLeave($user, '2026-03-02', '2026-03-03', LeaveStatus::REJECTED);
        expect($this->leaves->requestLeave($user, ['start_date' => '2026-03-02', 'end_date' => '2026-03-03', 'reason' => 'ok'])->exists)->toBeTrue()
            ->and($this->leaves->requestLeave($user, ['start_date' => '2026-03-19', 'end_date' => '2026-03-20', 'reason' => 'ok'])->exists)->toBeTrue();
    });

    it('rolls the leave request back when logging fails', function () {
        Log::spy();
        svcBreakActivityLogging();

        expect(fn () => app(LeaveService::class)->requestLeave($this->employee, ['start_date' => '2026-03-16', 'end_date' => '2026-03-17', 'reason' => 'Trip']))
            ->toThrow(RuntimeException::class, 'log store down');

        $this->assertDatabaseCount('leaves', 0);
        Log::shouldHaveReceived('error')->once()->with('Leave request failed: log store down');
    });

    it('counts leave days inclusively', function (string $start, string $end, int $expected) {
        expect($this->leaves->calculateLeaveDays(Carbon::parse($start), Carbon::parse($end)))->toBe($expected);
    })->with([
        'single day' => ['2026-03-16', '2026-03-16', 1],
        'three days' => ['2026-03-16', '2026-03-18', 3],
        'across a month end' => ['2026-03-30', '2026-04-02', 4],
        'across a year end' => ['2025-12-30', '2026-01-02', 4],
        'ignores the time of day' => ['2026-03-30 18:00:00', '2026-04-01 06:00:00', 3],
        'up to the last instant of a month' => ['2026-03-30', '2026-03-31 23:59:59.999999', 2],
    ]);

    it('resolves the quota of a year from the quota table or the user default', function () {
        $user = $this->createEmployee(['leave_quota_days' => 12]);
        LeaveQuota::create(['user_id' => $user->id, 'year' => 2026, 'quota_days' => 20]);
        LeaveQuota::create(['user_id' => $this->createEmployee()->id, 'year' => 2027, 'quota_days' => 99]);

        expect($this->leaves->getQuotaForYear($user, 2026))->toBe(20)
            ->and($this->leaves->getQuotaForYear($user, 2025))->toBe(12)
            ->and($this->leaves->getQuotaForYear($user, 2027))->toBe(12);
    });

    it('summarises used, pending and remaining leave days for the current year', function () {
        $user = $this->createEmployee(['leave_quota_days' => 12]);
        svcLeave($user, '2026-01-05', '2026-01-07', LeaveStatus::APPROVED); // 3
        svcLeave($user, '2026-02-02', '2026-02-03', LeaveStatus::APPROVED); // 2
        svcLeave($user, '2026-04-06', '2026-04-06', LeaveStatus::PENDING); // 1
        svcLeave($user, '2026-05-04', '2026-05-08', LeaveStatus::REJECTED); // ignored
        svcLeave($user, '2025-06-02', '2025-06-06', LeaveStatus::APPROVED); // other year

        expect($this->leaves->getLeaveSummary($user))->toBe([
            'year' => 2026,
            'total_quota' => 12,
            'used_days' => 5,
            'pending_days' => 1,
            'remaining_days' => 6,
            'max_per_month' => 5,
        ]);
    });

    it('summarises another year with its own quota and never reports negative remaining days', function () {
        $user = $this->createEmployee(['leave_quota_days' => 12]);
        LeaveQuota::create(['user_id' => $user->id, 'year' => 2025, 'quota_days' => 3]);
        svcLeave($user, '2025-06-02', '2025-06-06', LeaveStatus::APPROVED); // 5 > 3

        $summary = $this->leaves->getLeaveSummary($user, 2025);

        expect($summary['year'])->toBe(2025)
            ->and($summary['total_quota'])->toBe(3)
            ->and($summary['used_days'])->toBe(5)
            ->and($summary['remaining_days'])->toBe(0);
    });

    it('uses the team monthly limit in the summary, or the default without a team', function () {
        $team = $this->createTeam(['max_leave_days_per_month' => 8]);
        $member = $this->createUser(['team_id' => $team->id]);
        $teamless = User::factory()->create(['team_id' => null, 'role' => 'employee']);

        expect($this->leaves->getLeaveSummary($member)['max_per_month'])->toBe(8)
            ->and($this->leaves->getLeaveSummary($teamless)['max_per_month'])->toBe(Team::DEFAULT_MAX_LEAVE_DAYS_PER_MONTH);
    });

    it('lets a manager edit the dates of a leave and logs each change', function () {
        $leave = svcLeave($this->employee, '2026-03-16', '2026-03-18');

        $result = $this->leaves->updateLeave($leave, $this->manager, [
            'start_date' => '2026-03-17',
            'end_date' => '2026-03-20',
        ], svcRequest());

        expect($result->start_date->toDateString())->toBe('2026-03-17')
            ->and($result->end_date->toDateString())->toBe('2026-03-20')
            ->and($result->relationLoaded('user'))->toBeTrue()
            ->and($result->relationLoaded('approver'))->toBeTrue();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->manager->id,
            'action' => 'leave_edited',
            'description' => "Manager edited leave #{$leave->id} for Alice: start_date: 2026-03-16 -> 2026-03-17, end_date: 2026-03-18 -> 2026-03-20",
            'ip_address' => '198.51.100.4',
        ]);
    });

    it('does not log an edit when the submitted dates equal the current ones', function () {
        $leave = svcLeave($this->employee, '2026-03-16', '2026-03-18');

        $this->leaves->updateLeave($leave, $this->manager, ['start_date' => '2026-03-16', 'end_date' => '2026-03-18']);
        $this->leaves->updateLeave($leave->fresh(), $this->manager, []);

        expect(ActivityLog::where('action', ActivityType::LEAVE_EDITED)->count())->toBe(0)
            ->and($leave->fresh()->start_date->toDateString())->toBe('2026-03-16');
    });

    it('lets a manager correct the created and approved timestamps', function () {
        $leave = svcLeave($this->employee, '2026-03-16', '2026-03-18');

        $result = $this->leaves->updateLeave($leave, $this->manager, [
            'created_at' => '2026-03-01 08:00:00',
            'approved_at' => '2026-03-12 09:30:00',
        ]);

        expect($result->created_at->format('Y-m-d H:i:s'))->toBe('2026-03-01 08:00:00')
            ->and($result->approved_at->format('Y-m-d H:i:s'))->toBe('2026-03-12 09:30:00');

        $log = ActivityLog::where('action', ActivityType::LEAVE_EDITED)->first();
        expect($log->description)->toContain('created_at: 2026-03-11T14:00:00+07:00 -> 2026-03-01 08:00:00')
            ->and($log->description)->toContain('approved_at: null -> 2026-03-12 09:30:00');

        // Editing an existing approval timestamp reports the old value instead of "null".
        $this->leaves->updateLeave($result, $this->manager, ['approved_at' => '2026-03-13 10:00:00']);
        $second = ActivityLog::where('action', ActivityType::LEAVE_EDITED)->latest('id')->first();
        expect($second->description)->toContain('approved_at: 2026-03-12T09:30:00+07:00 -> 2026-03-13 10:00:00');
    });

    it('rolls an edit back when logging fails', function () {
        Log::spy();
        $leave = svcLeave($this->employee, '2026-03-16', '2026-03-18');
        svcBreakActivityLogging();

        expect(fn () => app(LeaveService::class)->updateLeave($leave, $this->manager, ['start_date' => '2026-03-17']))
            ->toThrow(RuntimeException::class, 'log store down');

        expect($leave->fresh()->start_date->toDateString())->toBe('2026-03-16');
        Log::shouldHaveReceived('error')->once()->with('Update leave failed: log store down');
    });

    it('approves a leave request', function () {
        $leave = svcLeave($this->employee, '2026-03-16', '2026-03-18');

        $result = $this->leaves->approveLeave($leave, $this->manager, 'Enjoy your trip', svcRequest());

        expect($result->status)->toBe(LeaveStatus::APPROVED)
            ->and($result->isApproved())->toBeTrue()
            ->and($result->approved_by)->toBe($this->manager->id)
            ->and($result->approved_at->format('Y-m-d H:i:s'))->toBe('2026-03-11 14:00:00')
            ->and($result->notes)->toBe('Enjoy your trip')
            ->and($result->approver->is($this->manager))->toBeTrue()
            ->and($result->user->is($this->employee))->toBeTrue();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->manager->id,
            'action' => 'leave_approved',
            'description' => 'Manager approved leave request for Alice from 2026-03-16 to 2026-03-18',
            'ip_address' => '198.51.100.4',
        ]);
    });

    it('rejects a leave request', function () {
        $leave = svcLeave($this->employee, '2026-03-16', '2026-03-18');

        $result = $this->leaves->rejectLeave($leave, $this->manager, 'Team is short staffed');

        expect($result->status)->toBe(LeaveStatus::REJECTED)
            ->and($result->isRejected())->toBeTrue()
            ->and($result->approved_by)->toBe($this->manager->id)
            ->and($result->notes)->toBe('Team is short staffed');

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->manager->id,
            'action' => 'leave_rejected',
            'description' => 'Manager rejected leave request for Alice from 2026-03-16 to 2026-03-18',
        ]);
    });

    it('stores no notes when approving or rejecting without them', function () {
        $approved = $this->leaves->approveLeave(svcLeave($this->employee, '2026-03-16', '2026-03-16'), $this->manager);
        $rejected = $this->leaves->rejectLeave(svcLeave($this->employee, '2026-03-23', '2026-03-23'), $this->manager);

        expect($approved->notes)->toBeNull()
            ->and($rejected->notes)->toBeNull();
    });

    it('rolls an approval back when logging fails', function () {
        Log::spy();
        $leave = svcLeave($this->employee, '2026-03-16', '2026-03-18');
        svcBreakActivityLogging();

        expect(fn () => app(LeaveService::class)->approveLeave($leave, $this->manager, 'ok'))
            ->toThrow(RuntimeException::class, 'log store down');

        $fresh = $leave->fresh();
        expect($fresh->status)->toBe(LeaveStatus::PENDING)
            ->and($fresh->approved_by)->toBeNull()
            ->and($fresh->approved_at)->toBeNull();
        Log::shouldHaveReceived('error')->once()->with('Approve leave failed: log store down');
    });

    it('rolls a rejection back when logging fails', function () {
        Log::spy();
        $leave = svcLeave($this->employee, '2026-03-16', '2026-03-18');
        svcBreakActivityLogging();

        expect(fn () => app(LeaveService::class)->rejectLeave($leave, $this->manager, 'no'))
            ->toThrow(RuntimeException::class, 'log store down');

        expect($leave->fresh()->status)->toBe(LeaveStatus::PENDING);
        Log::shouldHaveReceived('error')->once()->with('Reject leave failed: log store down');
    });
});
