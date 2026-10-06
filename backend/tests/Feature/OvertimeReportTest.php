<?php

use App\Enums\OvertimeStatus;
use App\Models\Attendance;
use App\Models\Overtime;
use App\Models\User;
use App\Services\ReportService;
use Carbon\Carbon;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

/*
 * Reports count only manager-approved overtime from the approval go-live date on
 * (config attendance.overtime_approval_starts_on, default 2026-10-05). Earlier days keep the
 * legacy rule: every hour above the team's required hours.
 *
 * Day under test: Wednesday 20 March 2030, required hours = 7.
 */

function otrpSession(User $user, string $in, string $out, ?int $overtimeId = null): Attendance
{
    $checkIn = Carbon::parse("2030-03-20 {$in}");
    $checkOut = Carbon::parse("2030-03-20 {$out}");

    return Attendance::create([
        'user_id' => $user->id,
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'date' => '2030-03-20',
        'total_hours' => round($checkIn->diffInMinutes($checkOut) / 60, 2),
        'overtime_id' => $overtimeId,
    ]);
}

function otrpOvertime(User $user, OvertimeStatus $status): Overtime
{
    return Overtime::factory()->create(['user_id' => $user->id, 'date' => '2030-03-20', 'status' => $status]);
}

/**
 * @return array<string, array<string, mixed>> report rows keyed by employee name
 */
function otrpDailyRows(array $report): array
{
    return collect($report['employees'])->keyBy('employee.name')->all();
}

beforeEach(function () {
    $this->team = $this->createTeam(['required_work_hours' => 7.0]);
    $this->manager = $this->createManager();
    $this->service = app(ReportService::class);

    $this->alice = $this->createEmployee(['name' => 'Alice']); // 7h + 1h approved overtime
    $this->bob = $this->createEmployee(['name' => 'Bob']); // 7h + 1.5h pending overtime
    $this->carol = $this->createEmployee(['name' => 'Carol']); // 7h + 1h rejected overtime
    $this->dave = $this->createEmployee(['name' => 'Dave']); // 8h in one go, no overtime record
    $this->eve = $this->createEmployee(['name' => 'Eve']); // 3h

    otrpSession($this->alice, '08:00:00', '15:00:00');
    otrpSession($this->alice, '16:00:00', '17:00:00', otrpOvertime($this->alice, OvertimeStatus::APPROVED)->id);
    otrpSession($this->bob, '08:00:00', '15:00:00');
    otrpSession($this->bob, '16:00:00', '17:30:00', otrpOvertime($this->bob, OvertimeStatus::PENDING)->id);
    otrpSession($this->carol, '08:00:00', '15:00:00');
    otrpSession($this->carol, '16:00:00', '17:00:00', otrpOvertime($this->carol, OvertimeStatus::REJECTED)->id);
    otrpSession($this->dave, '08:00:00', '16:00:00');
    otrpSession($this->eve, '08:00:00', '11:00:00');
});

describe('daily attendance report', function () {
    it('counts only approved overtime and shows pending overtime separately', function () {
        $rows = otrpDailyRows($this->service->getDailyAttendanceReport(Carbon::parse('2030-03-20'), $this->team->id));

        expect($rows['Alice']['overtime_hours'])->toBe(1.0)
            ->and($rows['Alice']['pending_overtime_hours'])->toBe(0.0)
            ->and($rows['Alice']['status'])->toBe('overtime')
            ->and($rows['Bob']['overtime_hours'])->toBe(0.0)
            ->and($rows['Bob']['pending_overtime_hours'])->toBe(1.5)
            ->and($rows['Bob']['status'])->toBe('complete')
            ->and($rows['Carol']['overtime_hours'])->toBe(0.0)
            ->and($rows['Carol']['pending_overtime_hours'])->toBe(0.0)
            ->and($rows['Carol']['status'])->toBe('complete')
            ->and($rows['Dave']['overtime_hours'])->toBe(0.0)
            ->and($rows['Dave']['daily_total_hours'])->toBe(8.0)
            ->and($rows['Dave']['status'])->toBe('complete')
            ->and($rows['Eve']['overtime_hours'])->toBe(0.0)
            ->and($rows['Eve']['status'])->toBe('incomplete');
    });

    it('keeps the legacy rule for days before the approval go-live date', function () {
        config(['attendance.overtime_approval_starts_on' => '2030-03-21']);

        $rows = otrpDailyRows($this->service->getDailyAttendanceReport(Carbon::parse('2030-03-20'), $this->team->id));

        expect($rows['Alice']['overtime_hours'])->toBe(1.0)
            ->and($rows['Bob']['overtime_hours'])->toBe(1.5)
            ->and($rows['Bob']['pending_overtime_hours'])->toBe(0.0)
            ->and($rows['Carol']['overtime_hours'])->toBe(1.0)
            ->and($rows['Dave']['overtime_hours'])->toBe(1.0)
            ->and($rows['Dave']['status'])->toBe('overtime')
            ->and($rows['Eve']['status'])->toBe('incomplete');
    });

    it('applies the approval rule on the go-live date itself and always when unset', function () {
        config(['attendance.overtime_approval_starts_on' => '2030-03-20']);
        $onGoLive = otrpDailyRows($this->service->getDailyAttendanceReport(Carbon::parse('2030-03-20'), $this->team->id));

        config(['attendance.overtime_approval_starts_on' => null]);
        $unset = otrpDailyRows($this->service->getDailyAttendanceReport(Carbon::parse('2030-03-20'), $this->team->id));

        foreach ([$onGoLive, $unset] as $rows) {
            expect($rows['Dave']['overtime_hours'])->toBe(0.0)
                ->and($rows['Alice']['overtime_hours'])->toBe(1.0);
        }
    });
});

describe('monthly attendance report', function () {
    it('totals approved overtime per employee and tracks pending overtime', function () {
        $report = $this->service->getMonthlyAttendanceReport(Carbon::parse('2030-03-18'), Carbon::parse('2030-03-23'), $this->team->id);
        $rows = collect($report['employees'])->keyBy('employee.name');

        expect($rows['Alice']['total_overtime_hours'])->toBe(1.0)
            ->and($rows['Alice']['total_pending_overtime_hours'])->toBe(0.0)
            ->and($rows['Alice']['daily_details'][0]['overtime_hours'])->toBe(1.0)
            ->and($rows['Alice']['daily_details'][0]['status'])->toBe('overtime')
            ->and($rows['Bob']['total_overtime_hours'])->toBe(0.0)
            ->and($rows['Bob']['total_pending_overtime_hours'])->toBe(1.5)
            ->and($rows['Bob']['daily_details'][0]['pending_overtime_hours'])->toBe(1.5)
            ->and($rows['Carol']['total_overtime_hours'])->toBe(0.0)
            ->and($rows['Dave']['total_overtime_hours'])->toBe(0.0)
            ->and($rows['Dave']['daily_details'][0]['status'])->toBe('complete');

        // Employees with approved overtime are listed first.
        expect($report['employees'][0]['employee']['name'])->toBe('Alice');
    });

    it('keeps the legacy total-versus-expected balance for ranges before the go-live date', function () {
        config(['attendance.overtime_approval_starts_on' => '2031-01-01']);

        // 18-23 March 2030 has 6 working days = 48 expected hours; nobody worked that much.
        $report = $this->service->getMonthlyAttendanceReport(Carbon::parse('2030-03-18'), Carbon::parse('2030-03-23'), $this->team->id);

        foreach ($report['employees'] as $row) {
            expect($row['total_overtime_hours'])->toBe(0.0);
        }
        expect(collect($report['employees'])->keyBy('employee.name')['Dave']['daily_details'][0]['overtime_hours'])->toBe(1.0);
    });
});

describe('employee report', function () {
    it('sums approved overtime into the summary and exposes it per day', function () {
        $report = $this->service->getEmployeeReport($this->alice, Carbon::parse('2030-03-18'), Carbon::parse('2030-03-23'));

        expect($report['summary']['overtime_hours'])->toBe(1.0)
            ->and($report['attendances'][0]['overtime_hours'])->toBe(1.0)
            ->and($report['attendances'][0]['pending_overtime_hours'])->toBe(0.0)
            ->and($report['attendances'][0]['status'])->toBe('overtime');

        $bob = $this->service->getEmployeeReport($this->bob, Carbon::parse('2030-03-18'), Carbon::parse('2030-03-23'));
        expect($bob['summary']['overtime_hours'])->toBe(0.0)
            ->and($bob['attendances'][0]['pending_overtime_hours'])->toBe(1.5)
            ->and($bob['attendances'][0]['status'])->toBe('complete');
    });

    it('still counts incomplete days and keeps the deficit untouched', function () {
        $report = $this->service->getEmployeeReport($this->eve, Carbon::parse('2030-03-18'), Carbon::parse('2030-03-23'));

        expect($report['summary']['incomplete_days'])->toBe(1)
            ->and($report['summary']['overtime_hours'])->toBe(0.0)
            ->and($report['summary']['deficit_hours'])->toBe(45.0) // 48 expected - 3 worked
            ->and($report['attendances'][0]['status'])->toBe('incomplete');
    });

    it('keeps the legacy balance for a range entirely before the go-live date', function () {
        config(['attendance.overtime_approval_starts_on' => '2031-01-01']);

        // Dave worked 8h in a single day, so 8 - 48 expected is no overtime in the legacy balance either.
        $report = $this->service->getEmployeeReport($this->dave, Carbon::parse('2030-03-18'), Carbon::parse('2030-03-23'));
        expect($report['summary']['overtime_hours'])->toBe(0.0)
            ->and($report['attendances'][0]['overtime_hours'])->toBe(1.0)
            ->and($report['attendances'][0]['status'])->toBe('overtime');

        // A single working day makes the 8h/day balance meaningful: 9h worked => 1h overtime.
        otrpSession($this->eve, '12:00:00', '21:00:00'); // Eve: 3h + 9h = 12h on the 20th
        $oneDay = $this->service->getEmployeeReport($this->eve, Carbon::parse('2030-03-20'), Carbon::parse('2030-03-20'));
        expect($oneDay['summary']['overtime_hours'])->toBe(4.0);
    });
});
