<?php

use App\Enums\LeaveStatus;
use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\Task;
use App\Models\User;
use App\Services\ReportService;
use Carbon\Carbon;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

/*
 * Direct tests for ReportService.
 *
 * "Now" is frozen at Wednesday 11 March 2026 14:00 (Asia/Jakarta). In March 2026
 * the Sundays are the 1st, 8th, 15th, 22nd and 29th, so every range below can be
 * counted by hand.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-03-11 14:00:00'));
    $this->team = $this->createTeam(['required_work_hours' => 7.0]);
    $this->reports = app(ReportService::class);
});

function reportAttendance(User $user, string $checkIn, ?string $checkOut = null, array $tasks = []): Attendance
{
    $in = Carbon::parse($checkIn);
    $out = $checkOut ? Carbon::parse($checkOut) : null;

    $attendance = Attendance::create([
        'user_id' => $user->id,
        'check_in' => $in,
        'check_out' => $out,
        'date' => $in->toDateString(),
        'total_hours' => $out ? round($in->diffInMinutes($out) / 60, 2) : 0,
    ]);

    foreach ($tasks as $task) {
        Task::create([
            'attendance_id' => $attendance->id,
            'title' => $task['title'],
            'is_completed' => $task['is_completed'] ?? false,
            'blocker_reason' => $task['blocker_reason'] ?? null,
        ]);
    }

    return $attendance;
}

function reportLeave(User $user, string $start, string $end, LeaveStatus $status = LeaveStatus::APPROVED, string $reason = 'Family matters'): Leave
{
    return Leave::create([
        'user_id' => $user->id,
        'start_date' => $start,
        'end_date' => $end,
        'reason' => $reason,
        'status' => $status,
    ]);
}

function reportHoliday(int $teamId, string $date, string $name = 'Holiday'): Holiday
{
    return Holiday::create(['team_id' => $teamId, 'date' => $date, 'name' => $name]);
}

function reportDates(array $dailyData): array
{
    return array_column($dailyData, 'date');
}

function reportStatuses(array $dailyData): array
{
    return array_column($dailyData, 'status');
}

// ---------------------------------------------------------------------------
// getEmployeeReport
// ---------------------------------------------------------------------------

it('reports zero activity and a full deficit for a range without attendance', function () {
    $employee = $this->createEmployee();

    $report = $this->reports->getEmployeeReport($employee, Carbon::parse('2026-03-02'), Carbon::parse('2026-03-08'));

    expect($report['attendances'])->toBe([])
        ->and($report['summary'])->toEqual([
            'working_days' => 6,
            'expected_hours' => 48,
            'total_days_worked' => 0,
            'total_leave_days' => 0,
            'total_hours' => 0,
            'average_hours_per_day' => 0,
            'overtime_hours' => 0,
            'deficit_hours' => 48,
            'incomplete_days' => 0,
            'task_completion_rate' => 0,
        ]);
});

it('excludes Sundays and only the own team holidays from the working days', function () {
    $employee = $this->createEmployee();
    $otherTeam = $this->createTeam();

    reportHoliday($this->team->id, '2026-03-04', 'Own team holiday');
    reportHoliday($otherTeam->id, '2026-03-05', 'Other team holiday');

    $report = $this->reports->getEmployeeReport($employee, Carbon::parse('2026-03-02'), Carbon::parse('2026-03-08'));

    // Mon..Sat = 6 days, minus own holiday on Wednesday; Sunday never counts.
    expect($report['summary']['working_days'])->toBe(5)
        ->and($report['summary']['expected_hours'])->toBe(40);
});

it('classifies each day, sorts sessions and aggregates task statistics', function () {
    $employee = $this->createEmployee();

    // Monday: exactly the required 7 hours -> complete.
    reportAttendance($employee, '2026-03-02 09:00:00', '2026-03-02 16:00:00', [
        ['title' => 'Write spec', 'is_completed' => true],
        ['title' => 'Review PR', 'is_completed' => false, 'blocker_reason' => 'Waiting for author'],
    ]);

    // Tuesday: 4 hours -> incomplete.
    reportAttendance($employee, '2026-03-03 09:00:00', '2026-03-03 13:00:00', [
        ['title' => 'Triage bugs', 'is_completed' => false, 'blocker_reason' => 'No access'],
    ]);

    // Wednesday: two sessions (afternoon one inserted first) totalling 8 hours -> overtime.
    reportAttendance($employee, '2026-03-04 13:00:00', '2026-03-04 18:00:00', [
        ['title' => 'Afternoon task', 'is_completed' => true],
    ]);
    reportAttendance($employee, '2026-03-04 09:00:00', '2026-03-04 12:00:00', [
        ['title' => 'Morning task', 'is_completed' => true],
    ]);

    $report = $this->reports->getEmployeeReport($employee, Carbon::parse('2026-03-02'), Carbon::parse('2026-03-07'));
    $summary = $report['summary'];

    expect($summary['working_days'])->toBe(6)
        ->and($summary['expected_hours'])->toBe(48)
        ->and($summary['total_days_worked'])->toBe(3)
        ->and($summary['total_leave_days'])->toBe(0)
        ->and($summary['total_hours'])->toEqual(19.0)
        ->and($summary['average_hours_per_day'])->toEqual(6.33)
        ->and($summary['overtime_hours'])->toEqual(0.0)
        ->and($summary['deficit_hours'])->toEqual(29.0)
        ->and($summary['incomplete_days'])->toBe(1)
        ->and($summary['task_completion_rate'])->toEqual(60.0);

    // Newest day first.
    expect(reportDates($report['attendances']))->toBe(['2026-03-04', '2026-03-03', '2026-03-02'])
        ->and(reportStatuses($report['attendances']))->toBe(['overtime', 'incomplete', 'complete']);

    // Sessions are ordered by check-in regardless of insertion order.
    $wednesday = $report['attendances'][0];
    expect($wednesday['daily_total_hours'])->toEqual(8.0)
        ->and($wednesday['sessions'])->toHaveCount(2)
        ->and($wednesday['sessions'][0]['check_in']->format('H:i'))->toBe('09:00')
        ->and($wednesday['sessions'][1]['check_in']->format('H:i'))->toBe('13:00')
        ->and($wednesday['sessions'][0]['tasks']->pluck('title')->all())->toBe(['Morning task']);

    $monday = $report['attendances'][2];
    expect($monday['sessions'][0]['tasks_completed'])->toBe(1)
        ->and($monday['sessions'][0]['tasks_incomplete'])->toBe(1)
        ->and($monday['sessions'][0]['tasks'][1]['blocker_reason'])->toBe('Waiting for author')
        ->and($monday['sessions'][0]['tasks'][1]['is_completed'])->toBeFalse();
});

it('applies the team required hours when classifying a day', function (float $required, float $worked, string $expectedStatus) {
    $team = $this->createTeam(['required_work_hours' => $required]);
    $employee = $this->createUser(['team_id' => $team->id]);

    $in = Carbon::parse('2026-03-02 08:00:00');
    reportAttendance($employee, $in->toDateTimeString(), $in->copy()->addMinutes((int) ($worked * 60))->toDateTimeString());

    $report = $this->reports->getEmployeeReport($employee, Carbon::parse('2026-03-02'), Carbon::parse('2026-03-02'));

    expect($report['attendances'][0]['status'])->toBe($expectedStatus)
        ->and($report['summary']['incomplete_days'])->toBe($expectedStatus === 'incomplete' ? 1 : 0);
})->with([
    'under an 8h requirement' => [8.0, 7.0, 'incomplete'],
    'exactly an 8h requirement' => [8.0, 8.0, 'complete'],
    'over an 8h requirement' => [8.0, 9.0, 'overtime'],
    'exactly a 7h requirement' => [7.0, 7.0, 'complete'],
    'over a 4.5h requirement' => [4.5, 5.0, 'overtime'],
]);

it('counts approved leave as expected hours but skips Sundays, holidays and days with attendance', function () {
    $employee = $this->createEmployee();

    // Fri 6, Sat 7, Sun 8, Mon 9 (holiday), Tue 10 (worked).
    reportLeave($employee, '2026-03-06', '2026-03-10');
    reportHoliday($this->team->id, '2026-03-09');
    reportAttendance($employee, '2026-03-10 09:00:00', '2026-03-10 16:00:00');

    $report = $this->reports->getEmployeeReport($employee, Carbon::parse('2026-03-02'), Carbon::parse('2026-03-14'));

    expect($report['summary']['working_days'])->toBe(11)
        ->and($report['summary']['expected_hours'])->toBe(88)
        ->and($report['summary']['total_days_worked'])->toBe(1)
        ->and($report['summary']['total_leave_days'])->toBe(2)
        ->and($report['summary']['total_hours'])->toEqual(23.0)
        ->and($report['summary']['deficit_hours'])->toEqual(65.0)
        ->and($report['summary']['overtime_hours'])->toEqual(0.0);

    expect(reportDates($report['attendances']))->toBe(['2026-03-10', '2026-03-07', '2026-03-06'])
        ->and(reportStatuses($report['attendances']))->toBe(['complete', 'on_leave', 'on_leave']);

    $leaveDay = $report['attendances'][1];
    expect($leaveDay['sessions'])->toBe([])
        ->and($leaveDay['daily_total_hours'])->toBe(8);
});

it('clamps a leave that starts before the requested range', function () {
    $employee = $this->createEmployee();
    reportLeave($employee, '2026-02-25', '2026-03-03');

    $report = $this->reports->getEmployeeReport($employee, Carbon::parse('2026-03-02'), Carbon::parse('2026-03-14'));

    expect($report['summary']['total_leave_days'])->toBe(2)
        ->and(reportDates($report['attendances']))->toBe(['2026-03-03', '2026-03-02']);
});

it('clamps a leave that ends after the requested range', function () {
    $employee = $this->createEmployee();
    reportLeave($employee, '2026-03-13', '2026-03-20');

    $report = $this->reports->getEmployeeReport($employee, Carbon::parse('2026-03-02'), Carbon::parse('2026-03-14'));

    expect($report['summary']['total_leave_days'])->toBe(2)
        ->and(reportDates($report['attendances']))->toBe(['2026-03-14', '2026-03-13']);
});

it('counts a leave that fully surrounds the requested range', function () {
    $employee = $this->createEmployee();
    reportLeave($employee, '2026-03-01', '2026-03-31');

    $report = $this->reports->getEmployeeReport($employee, Carbon::parse('2026-03-04'), Carbon::parse('2026-03-06'));

    expect($report['summary']['total_leave_days'])->toBe(3)
        ->and($report['summary']['total_hours'])->toEqual(24.0)
        ->and($report['summary']['deficit_hours'])->toEqual(0.0)
        ->and(reportStatuses($report['attendances']))->toBe(['on_leave', 'on_leave', 'on_leave']);
});

it('ignores pending, rejected and other users leaves', function () {
    $employee = $this->createEmployee();
    $colleague = $this->createEmployee();

    reportLeave($employee, '2026-03-02', '2026-03-06', LeaveStatus::PENDING);
    reportLeave($employee, '2026-03-02', '2026-03-06', LeaveStatus::REJECTED);
    reportLeave($colleague, '2026-03-02', '2026-03-06', LeaveStatus::APPROVED);

    $report = $this->reports->getEmployeeReport($employee, Carbon::parse('2026-03-02'), Carbon::parse('2026-03-07'));

    expect($report['summary']['total_leave_days'])->toBe(0)
        ->and($report['attendances'])->toBe([]);
});

it('falls back to the default required hours and all holidays for a user without a team', function () {
    $loner = User::factory()->create(['team_id' => null, 'role' => 'employee']);
    reportHoliday($this->team->id, '2026-03-04');
    reportAttendance($loner, '2026-03-02 09:00:00', '2026-03-02 16:00:00');

    $report = $this->reports->getEmployeeReport($loner, Carbon::parse('2026-03-02'), Carbon::parse('2026-03-07'));

    // Without a team there is no holiday filter, so the holiday of any team applies.
    expect($report['summary']['working_days'])->toBe(5)
        ->and($report['attendances'][0]['status'])->toBe('complete'); // default requirement is 7h
});

it('delegates the manager employee report to the employee report', function () {
    $employee = $this->createEmployee();
    reportAttendance($employee, '2026-03-03 09:00:00', '2026-03-03 12:30:00', [['title' => 'Docs']]);

    $start = Carbon::parse('2026-03-02');
    $end = Carbon::parse('2026-03-07');

    $forManager = $this->reports->getEmployeeReportForManager($employee, $start, $end);

    expect(json_encode($forManager))->toBe(json_encode($this->reports->getEmployeeReport($employee, $start, $end)))
        ->and($forManager['summary']['total_days_worked'])->toBe(1);
});

// ---------------------------------------------------------------------------
// getManagerDashboard
// ---------------------------------------------------------------------------

it('builds the manager dashboard for today with checked in, on leave and checked out employees', function () {
    $alice = $this->createEmployee(['name' => 'Alice']);
    $bob = $this->createEmployee(['name' => 'Bob']);
    $carol = $this->createEmployee(['name' => 'Carol']);
    $this->createDisabledEmployee(['name' => 'Disabled Dan']);
    $this->createManager(['name' => 'Boss']);
    $otherTeam = $this->createTeam();
    User::factory()->create(['team_id' => $otherTeam->id, 'role' => 'employee', 'name' => 'Zed']);

    // Alice: finished a 2h session this morning and is checked in since 12:00 (2h ago).
    reportAttendance($alice, '2026-03-11 08:00:00', '2026-03-11 10:00:00');
    reportAttendance($alice, '2026-03-11 12:00:00');

    // Bob: on approved leave.
    reportLeave($bob, '2026-03-10', '2026-03-12', LeaveStatus::APPROVED, 'Medical appointment');

    // Carol: checked out after 8h today, plus 7h on Monday of the same week.
    reportAttendance($carol, '2026-03-11 06:00:00', '2026-03-11 14:00:00');
    reportAttendance($carol, '2026-03-09 09:00:00', '2026-03-09 16:00:00');

    $dashboard = $this->reports->getManagerDashboard(null, $this->team->id);
    $byName = collect($dashboard['employees'])->keyBy('name');

    expect($dashboard['summary'])->toEqual([
        'total_employees' => 3,
        'checked_in_now' => 1,
        'on_leave' => 1,
        'average_daily_hours' => 6.0, // (4h + 8h) / 2 employees with hours today
    ])
        ->and($byName->keys()->sort()->values()->all())->toBe(['Alice', 'Bob', 'Carol']);

    $aliceRow = $byName['Alice'];
    expect($aliceRow['status'])->toBe('checked_in')
        ->and($aliceRow['id'])->toBe($alice->id)
        ->and($aliceRow['email'])->toBe($alice->email)
        ->and($aliceRow['current_session']['elapsed_hours'])->toEqual(2.0)
        ->and($aliceRow['current_session']['check_in']->format('H:i'))->toBe('12:00')
        ->and($aliceRow['today_total_hours'])->toEqual(4.0)
        ->and($aliceRow['week_total_hours'])->toEqual(2.0)
        ->and($aliceRow['month_total_hours'])->toEqual(2.0)
        ->and($aliceRow)->not->toHaveKey('leave');

    $bobRow = $byName['Bob'];
    expect($bobRow['status'])->toBe('on_leave')
        ->and($bobRow['current_session'])->toBeNull()
        ->and($bobRow['today_total_hours'])->toEqual(0.0)
        ->and($bobRow['leave'])->toBe([
            'start_date' => '2026-03-10',
            'end_date' => '2026-03-12',
            'reason' => 'Medical appointment',
        ]);

    $carolRow = $byName['Carol'];
    expect($carolRow['status'])->toBe('checked_out')
        ->and($carolRow['current_session'])->toBeNull()
        ->and($carolRow['today_total_hours'])->toEqual(8.0)
        ->and($carolRow['week_total_hours'])->toEqual(15.0)
        ->and($carolRow['month_total_hours'])->toEqual(15.0);
});

it('includes employees of every team on the dashboard when no team is given', function () {
    $this->createEmployee(['name' => 'Alice']);
    $otherTeam = $this->createTeam();
    User::factory()->create(['team_id' => $otherTeam->id, 'role' => 'employee', 'name' => 'Zed']);

    $everyone = $this->reports->getManagerDashboard();
    $scoped = $this->reports->getManagerDashboard(null, $otherTeam->id);

    expect($everyone['summary']['total_employees'])->toBe(2)
        ->and($scoped['summary']['total_employees'])->toBe(1)
        ->and($scoped['employees'][0]['name'])->toBe('Zed');
});

it('builds the dashboard for a past date using that dates leaves and hours', function () {
    $bob = $this->createEmployee(['name' => 'Bob']);
    $carol = $this->createEmployee(['name' => 'Carol']);
    $dan = $this->createEmployee(['name' => 'Dan']);

    reportLeave($bob, '2026-03-09', '2026-03-09');
    reportLeave($dan, '2026-03-10', '2026-03-12'); // does not cover the 9th
    reportAttendance($carol, '2026-03-09 09:00:00', '2026-03-09 16:00:00');

    $dashboard = $this->reports->getManagerDashboard(Carbon::parse('2026-03-09'), $this->team->id);
    $byName = collect($dashboard['employees'])->keyBy('name');

    expect($dashboard['summary'])->toEqual([
        'total_employees' => 3,
        'checked_in_now' => 0,
        'on_leave' => 1,
        'average_daily_hours' => 7.0,
    ])
        ->and($byName['Bob']['status'])->toBe('on_leave')
        ->and($byName['Dan']['status'])->toBe('checked_out')
        ->and($byName['Dan'])->not->toHaveKey('leave')
        ->and($byName['Carol']['status'])->toBe('checked_out')
        ->and($byName['Carol']['today_total_hours'])->toEqual(7.0)
        ->and($byName['Carol']['week_total_hours'])->toEqual(7.0);
});

it('reports a checked in employee as checked in even when an approved leave covers today', function () {
    $alice = $this->createEmployee(['name' => 'Alice']);
    reportLeave($alice, '2026-03-11', '2026-03-11');
    reportAttendance($alice, '2026-03-11 13:00:00');

    $dashboard = $this->reports->getManagerDashboard(null, $this->team->id);

    expect($dashboard['summary']['checked_in_now'])->toBe(1)
        ->and($dashboard['summary']['on_leave'])->toBe(0)
        ->and($dashboard['employees'][0]['status'])->toBe('checked_in')
        ->and($dashboard['employees'][0]['leave']['start_date'])->toBe('2026-03-11');
});

it('returns an empty dashboard with a zero average when the team has no employees', function () {
    $dashboard = $this->reports->getManagerDashboard(Carbon::parse('2026-03-11'), $this->team->id);

    expect($dashboard)->toBe([
        'summary' => [
            'total_employees' => 0,
            'checked_in_now' => 0,
            'on_leave' => 0,
            'average_daily_hours' => 0,
        ],
        'employees' => [],
    ]);
});

// ---------------------------------------------------------------------------
// getDailyAttendanceReport
// ---------------------------------------------------------------------------

it('builds the daily attendance report with statuses, overtime, sessions and leave', function () {
    $alice = $this->createEmployee(['name' => 'Alice']);
    $bob = $this->createEmployee(['name' => 'Bob']);
    $carol = $this->createEmployee(['name' => 'Carol']);
    $dave = $this->createEmployee(['name' => 'Dave']);
    $eve = $this->createEmployee(['name' => 'Eve']);
    $frank = $this->createDisabledEmployee(['name' => 'Frank']);
    $otherTeam = $this->createTeam();
    $zed = User::factory()->create(['team_id' => $otherTeam->id, 'role' => 'employee', 'name' => 'Zed']);

    // Alice: afternoon session inserted first, 3h + 5h = 8h -> overtime of 1h.
    reportAttendance($alice, '2026-03-11 13:00:00', '2026-03-11 18:00:00');
    reportAttendance($alice, '2026-03-11 09:00:00', '2026-03-11 12:00:00', [
        ['title' => 'Plan sprint', 'is_completed' => true],
        ['title' => 'Fix login', 'is_completed' => false, 'blocker_reason' => 'Waiting for API'],
    ]);
    reportAttendance($bob, '2026-03-11 09:00:00', '2026-03-11 13:00:00');
    reportAttendance($eve, '2026-03-11 09:00:00', '2026-03-11 16:00:00');
    reportAttendance($frank, '2026-03-11 09:00:00', '2026-03-11 16:00:00');
    reportAttendance($zed, '2026-03-11 09:00:00', '2026-03-11 16:00:00');

    // Carol is on approved leave; Dave only has a pending one.
    reportLeave($carol, '2026-03-10', '2026-03-12');
    reportLeave($dave, '2026-03-10', '2026-03-12', LeaveStatus::PENDING);

    $report = $this->reports->getDailyAttendanceReport(Carbon::parse('2026-03-11'), $this->team->id);

    expect($report['date'])->toBe('2026-03-11')
        ->and($report['required_hours'])->toBe(7.0)
        ->and(array_map(fn ($row) => $row['employee']['name'], $report['employees']))
        ->toBe(['Alice', 'Bob', 'Carol', 'Eve']);

    [$aliceRow, $bobRow, $carolRow, $eveRow] = $report['employees'];

    expect($aliceRow['employee'])->toBe(['id' => $alice->id, 'name' => 'Alice', 'email' => $alice->email])
        ->and($aliceRow['status'])->toBe('overtime')
        ->and($aliceRow['daily_total_hours'])->toEqual(8.0)
        ->and($aliceRow['overtime_hours'])->toEqual(1.0)
        ->and($aliceRow['required_hours'])->toBe(7.0)
        ->and($aliceRow['sessions'])->toHaveCount(2)
        ->and($aliceRow['sessions'][0]['session_number'])->toBe(1)
        ->and($aliceRow['sessions'][0]['check_in']->format('H:i'))->toBe('09:00')
        ->and($aliceRow['sessions'][0]['tasks_completed'])->toBe(1)
        ->and($aliceRow['sessions'][0]['tasks_incomplete'])->toBe(1)
        ->and($aliceRow['sessions'][0]['tasks']->pluck('blocker_reason')->all())->toBe([null, 'Waiting for API'])
        ->and($aliceRow['sessions'][1]['session_number'])->toBe(2)
        ->and($aliceRow['sessions'][1]['check_in']->format('H:i'))->toBe('13:00');

    expect($bobRow['status'])->toBe('incomplete')
        ->and($bobRow['overtime_hours'])->toEqual(0.0)
        ->and($bobRow['daily_total_hours'])->toEqual(4.0);

    expect($eveRow['status'])->toBe('complete')
        ->and($eveRow['overtime_hours'])->toEqual(0.0);

    expect($carolRow['status'])->toBe('on_leave')
        ->and($carolRow['daily_total_hours'])->toBe(0)
        ->and($carolRow['overtime_hours'])->toBe(0)
        ->and($carolRow['sessions'])->toBe([])
        ->and($carolRow)->not->toHaveKey('required_hours');
});

it('uses the team required hours in the daily report', function () {
    $team = $this->createTeam(['required_work_hours' => 4.0]);
    $employee = $this->createUser(['team_id' => $team->id, 'name' => 'Short Shift']);
    reportAttendance($employee, '2026-03-11 09:00:00', '2026-03-11 14:00:00');

    $report = $this->reports->getDailyAttendanceReport(Carbon::parse('2026-03-11'), $team->id);

    expect($report['required_hours'])->toBe(4.0)
        ->and($report['employees'][0]['status'])->toBe('overtime')
        ->and($report['employees'][0]['overtime_hours'])->toEqual(1.0)
        ->and($report['employees'][0]['required_hours'])->toBe(4.0);
});

it('covers all teams with the default required hours when no team is given to the daily report', function () {
    $alice = $this->createEmployee(['name' => 'Alice']);
    $otherTeam = $this->createTeam(['required_work_hours' => 4.0]);
    $zed = User::factory()->create(['team_id' => $otherTeam->id, 'role' => 'employee', 'name' => 'Zed']);

    reportAttendance($alice, '2026-03-11 09:00:00', '2026-03-11 13:00:00');
    reportAttendance($zed, '2026-03-11 09:00:00', '2026-03-11 13:00:00');

    $report = $this->reports->getDailyAttendanceReport(Carbon::parse('2026-03-11'));

    expect($report['required_hours'])->toBe(7.0)
        ->and(array_map(fn ($row) => $row['employee']['name'], $report['employees']))->toBe(['Alice', 'Zed'])
        ->and($report['employees'][0]['status'])->toBe('incomplete');
});

it('returns no employees and the default required hours for an unknown team in the daily report', function () {
    $this->createEmployee();

    $report = $this->reports->getDailyAttendanceReport(Carbon::parse('2026-03-11'), 999999);

    expect($report['required_hours'])->toBe(7.0)
        ->and($report['employees'])->toBe([]);
});

// ---------------------------------------------------------------------------
// getMonthlyAttendanceReport
// ---------------------------------------------------------------------------

it('builds the monthly report with totals, overtime, deficit and leave days', function () {
    $alice = $this->createEmployee(['name' => 'Alice']);
    $bob = $this->createEmployee(['name' => 'Bob']);
    $carol = $this->createEmployee(['name' => 'Carol']);
    $dave = $this->createEmployee(['name' => 'Dave']);
    $this->createDisabledEmployee(['name' => 'Disabled']);
    $this->createManager(['name' => 'Manager']);

    // Alice: 10h + 9h + 8h = 27h over three working days (24h expected) -> +3h.
    reportAttendance($alice, '2026-03-09 08:00:00', '2026-03-09 18:00:00');
    reportAttendance($alice, '2026-03-10 08:00:00', '2026-03-10 17:00:00');
    reportAttendance($alice, '2026-03-11 13:00:00', '2026-03-11 17:00:00', [['title' => 'Late task', 'is_completed' => true]]);
    reportAttendance($alice, '2026-03-11 08:00:00', '2026-03-11 12:00:00');

    // Bob: a single 8h day -> 16h deficit.
    reportAttendance($bob, '2026-03-09 09:00:00', '2026-03-09 17:00:00');

    // Carol: approved leave on 9th and 10th (+16h) and 4h worked on the 11th.
    reportLeave($carol, '2026-03-09', '2026-03-10');
    reportAttendance($carol, '2026-03-11 09:00:00', '2026-03-11 13:00:00');

    $report = $this->reports->getMonthlyAttendanceReport(Carbon::parse('2026-03-09'), Carbon::parse('2026-03-11'), $this->team->id);

    expect($report['start_date'])->toBe('2026-03-09')
        ->and($report['end_date'])->toBe('2026-03-11')
        ->and($report['required_hours'])->toBe(7.0)
        ->and($report['working_days'])->toBe(3)
        ->and($report['expected_total_hours'])->toBe(24)
        ->and($report['employees'])->toHaveCount(4);

    // Most overtime first; the remaining employees keep their relative order.
    $rows = collect($report['employees'])->keyBy(fn ($row) => $row['employee']['name']);
    expect($report['employees'][0]['employee']['name'])->toBe('Alice');

    $aliceRow = $rows['Alice'];
    expect($aliceRow['employee']['id'])->toBe($alice->id)
        ->and($aliceRow['total_hours'])->toEqual(27.0)
        ->and($aliceRow['total_overtime_hours'])->toEqual(3.0)
        ->and($aliceRow['total_deficit_hours'])->toEqual(0.0)
        ->and($aliceRow['total_days_worked'])->toBe(3)
        ->and($aliceRow['total_leave_days'])->toBe(0)
        ->and(reportDates($aliceRow['daily_details']))->toBe(['2026-03-11', '2026-03-10', '2026-03-09'])
        ->and(reportStatuses($aliceRow['daily_details']))->toBe(['overtime', 'overtime', 'overtime'])
        ->and($aliceRow['daily_details'][0]['daily_total_hours'])->toEqual(8.0)
        ->and($aliceRow['daily_details'][0]['overtime_hours'])->toEqual(1.0)
        ->and($aliceRow['daily_details'][0]['sessions'])->toHaveCount(2)
        ->and($aliceRow['daily_details'][0]['sessions'][0]['session_number'])->toBe(1)
        ->and($aliceRow['daily_details'][0]['sessions'][0]['check_in']->format('H:i'))->toBe('08:00')
        ->and($aliceRow['daily_details'][0]['sessions'][1]['tasks'][0]['title'])->toBe('Late task');

    $bobRow = $rows['Bob'];
    expect($bobRow['total_hours'])->toEqual(8.0)
        ->and($bobRow['total_overtime_hours'])->toEqual(0.0)
        ->and($bobRow['total_deficit_hours'])->toEqual(16.0)
        ->and($bobRow['total_days_worked'])->toBe(1);

    $carolRow = $rows['Carol'];
    expect($carolRow['total_hours'])->toEqual(20.0)
        ->and($carolRow['total_deficit_hours'])->toEqual(4.0)
        ->and($carolRow['total_days_worked'])->toBe(1)
        ->and($carolRow['total_leave_days'])->toBe(2)
        ->and(reportStatuses($carolRow['daily_details']))->toBe(['incomplete', 'on_leave', 'on_leave'])
        ->and($carolRow['daily_details'][1])->toBe([
            'date' => '2026-03-10',
            'daily_total_hours' => 8,
            'overtime_hours' => 0,
            'status' => 'on_leave',
            'sessions' => [],
        ]);

    $daveRow = $rows['Dave'];
    expect($daveRow['total_hours'])->toEqual(0.0)
        ->and($daveRow['total_deficit_hours'])->toEqual(24.0)
        ->and($daveRow['daily_details'])->toBe([])
        ->and($dave->id)->toBe($daveRow['employee']['id']);
});

it('removes holidays, Sundays and worked days from monthly leave days', function () {
    $eve = $this->createEmployee(['name' => 'Eve']);
    $gina = $this->createEmployee(['name' => 'Gina']);

    reportHoliday($this->team->id, '2026-03-10');

    // Eve: leave Sat 7 .. Wed 11 with Sunday 8, holiday 10 and a worked 11th.
    reportLeave($eve, '2026-03-07', '2026-03-11');
    reportAttendance($eve, '2026-03-11 09:00:00', '2026-03-11 17:00:00');

    // Gina: leave covering the whole range via an enclosing leave.
    reportLeave($gina, '2026-03-01', '2026-03-31');

    $report = $this->reports->getMonthlyAttendanceReport(Carbon::parse('2026-03-07'), Carbon::parse('2026-03-11'), $this->team->id);
    $rows = collect($report['employees'])->keyBy(fn ($row) => $row['employee']['name']);

    // Working days: Sat 7, Mon 9, Wed 11 (Sunday 8 and holiday 10 are excluded).
    expect($report['working_days'])->toBe(3)
        ->and($report['expected_total_hours'])->toBe(24);

    expect($rows['Eve']['total_leave_days'])->toBe(2) // Sat 7 and Mon 9
        ->and(reportDates($rows['Eve']['daily_details']))->toBe(['2026-03-11', '2026-03-09', '2026-03-07'])
        ->and($rows['Eve']['total_hours'])->toEqual(24.0)
        ->and($rows['Eve']['total_deficit_hours'])->toEqual(0.0);

    expect($rows['Gina']['total_leave_days'])->toBe(3) // Sat 7, Mon 9, Wed 11
        ->and($rows['Gina']['total_hours'])->toEqual(24.0);
});

it('scopes the monthly report to a team and falls back to defaults without one', function () {
    $this->createEmployee(['name' => 'Alice']);
    $otherTeam = $this->createTeam(['required_work_hours' => 5.0]);
    User::factory()->create(['team_id' => $otherTeam->id, 'role' => 'employee', 'name' => 'Zed']);
    reportHoliday($otherTeam->id, '2026-03-10');

    $start = Carbon::parse('2026-03-09');
    $end = Carbon::parse('2026-03-11');

    $teamReport = $this->reports->getMonthlyAttendanceReport($start, $end, $otherTeam->id);
    $allReport = $this->reports->getMonthlyAttendanceReport($start, $end);

    expect($teamReport['required_hours'])->toBe(5.0)
        ->and($teamReport['working_days'])->toBe(2)
        ->and(array_map(fn ($row) => $row['employee']['name'], $teamReport['employees']))->toBe(['Zed']);

    // Without a team every team's holidays count and the default 7h applies.
    expect($allReport['required_hours'])->toBe(7.0)
        ->and($allReport['working_days'])->toBe(2)
        ->and($allReport['employees'])->toHaveCount(2);
});

// ---------------------------------------------------------------------------
// getCheckInTimeReport
// ---------------------------------------------------------------------------

it('ranks employees by check-in consistency using the default window', function () {
    $alice = $this->createEmployee(['name' => 'Alice']);
    $bob = $this->createEmployee(['name' => 'Bob']);
    $carol = $this->createEmployee(['name' => 'Carol']);
    $dave = $this->createEmployee(['name' => 'Dave']);
    $this->createEmployee(['name' => 'Nobody']); // no attendance -> skipped
    $this->createDisabledEmployee(['name' => 'Disabled']);

    // Alice: 09:30 (on time), 10:30 (late), 08:59 + 09:30 (earliest session is early -> late).
    reportAttendance($alice, '2026-03-09 09:30:00', '2026-03-09 17:00:00');
    reportAttendance($alice, '2026-03-10 10:30:00', '2026-03-10 17:00:00');
    reportAttendance($alice, '2026-03-11 09:30:00', '2026-03-11 12:00:00');
    reportAttendance($alice, '2026-03-11 08:59:00', '2026-03-11 09:20:00');

    // Bob: boundaries 09:00:00 and 10:00:00 are both inside the window.
    reportAttendance($bob, '2026-03-09 09:00:00', '2026-03-09 17:00:00');
    reportAttendance($bob, '2026-03-10 10:00:00', '2026-03-10 17:00:00');

    // Carol: one second after the window closes.
    reportAttendance($carol, '2026-03-09 10:00:01', '2026-03-09 17:00:00');

    // Dave: same 100% rate as Bob -> ordered by name.
    reportAttendance($dave, '2026-03-09 09:45:00', '2026-03-09 17:00:00');

    $report = $this->reports->getCheckInTimeReport(Carbon::parse('2026-03-09'), Carbon::parse('2026-03-11'));

    expect($report['start_date'])->toBe('2026-03-09')
        ->and($report['end_date'])->toBe('2026-03-11')
        ->and($report['window_start'])->toBe('09:00')
        ->and($report['window_end'])->toBe('10:00')
        ->and(array_map(fn ($row) => $row['employee']['name'], $report['employees']))
        ->toBe(['Bob', 'Dave', 'Alice', 'Carol']);

    $rows = collect($report['employees'])->keyBy(fn ($row) => $row['employee']['name']);

    expect($rows['Bob']['consistency_rate'])->toEqual(100.0)
        ->and($rows['Bob']['on_time_days'])->toBe(2)
        ->and($rows['Bob']['late_days'])->toBe(0)
        ->and($rows['Bob']['employee']['id'])->toBe($bob->id);

    expect($rows['Alice']['total_days'])->toBe(3)
        ->and($rows['Alice']['on_time_days'])->toBe(1)
        ->and($rows['Alice']['late_days'])->toBe(2)
        ->and($rows['Alice']['consistency_rate'])->toEqual(33.33)
        ->and($rows['Alice']['details'])->toBe([
            ['date' => '2026-03-11', 'check_in_time' => '08:59', 'is_on_time' => false],
            ['date' => '2026-03-10', 'check_in_time' => '10:30', 'is_on_time' => false],
            ['date' => '2026-03-09', 'check_in_time' => '09:30', 'is_on_time' => true],
        ]);

    expect($rows['Carol']['consistency_rate'])->toEqual(0.0)
        ->and($rows['Carol']['late_days'])->toBe(1);
});

it('uses the check-in window configured for the team', function () {
    $team = $this->createTeam(['check_in_window_start' => '08:00:00', 'check_in_window_end' => '08:30:00']);
    $other = $this->createTeam();
    $early = $this->createUser(['team_id' => $team->id, 'name' => 'Early Bird']);
    $outsider = $this->createUser(['team_id' => $other->id, 'name' => 'Other Team']);

    reportAttendance($early, '2026-03-09 08:15:00', '2026-03-09 16:00:00');
    reportAttendance($early, '2026-03-10 09:30:00', '2026-03-10 16:00:00');
    reportAttendance($outsider, '2026-03-09 08:15:00', '2026-03-09 16:00:00');

    $report = $this->reports->getCheckInTimeReport(Carbon::parse('2026-03-09'), Carbon::parse('2026-03-11'), $team->id);

    expect($report['window_start'])->toBe('08:00')
        ->and($report['window_end'])->toBe('08:30')
        ->and($report['employees'])->toHaveCount(1)
        ->and($report['employees'][0]['employee']['name'])->toBe('Early Bird')
        ->and($report['employees'][0]['on_time_days'])->toBe(1)
        ->and($report['employees'][0]['late_days'])->toBe(1)
        ->and($report['employees'][0]['consistency_rate'])->toEqual(50.0);
});

it('falls back to the default window for an unknown team and skips attendance outside the range', function () {
    $alice = $this->createEmployee(['name' => 'Alice']);
    reportAttendance($alice, '2026-03-02 09:30:00', '2026-03-02 17:00:00');

    $report = $this->reports->getCheckInTimeReport(Carbon::parse('2026-03-09'), Carbon::parse('2026-03-11'), 999999);

    expect($report['window_start'])->toBe('09:00')
        ->and($report['window_end'])->toBe('10:00')
        ->and($report['employees'])->toBe([]);
});
