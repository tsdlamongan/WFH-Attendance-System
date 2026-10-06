<?php

use App\Enums\OvertimeStatus;
use App\Models\Attendance;
use App\Models\Task;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use Carbon\Carbon;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

/*
 * The overtime allowance is a hard cap: attendance:auto-checkout closes a running overtime
 * session as soon as the minutes worked under its overtime record reach what is allowed
 * (and immediately once the record is rejected).
 * "Now" is frozen at Wednesday 20 March 2030 18:00.
 */

function otacSession(User $user, string $checkIn, ?string $checkOut = null, ?int $overtimeId = null): Attendance
{
    $in = Carbon::parse($checkIn);
    $out = $checkOut ? Carbon::parse($checkOut) : null;

    return Attendance::create([
        'user_id' => $user->id,
        'check_in' => $in,
        'check_out' => $out,
        'date' => $in->toDateString(),
        'total_hours' => $out ? round($in->diffInMinutes($out) / 60, 2) : 0,
        'overtime_id' => $overtimeId,
    ]);
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2030-03-20 18:00:00'));
    $this->team = $this->createTeam(['required_work_hours' => 7.0]);
    $this->manager = $this->createManager();
    $this->employee = $this->createEmployee(['name' => 'Alice']);

    // The day's required hours are already met, so every session after this is overtime.
    otacSession($this->employee, '2030-03-20 08:00:00', '2030-03-20 15:00:00');
});

describe('attendance:auto-checkout and the overtime cap', function () {
    it('closes an overtime session when the allowed minutes are used up', function () {
        $overtime = $this->createOvertime([
            'user_id' => $this->employee->id,
            'status' => OvertimeStatus::APPROVED,
            'approved_by' => $this->manager->id,
            'planned_minutes' => 60,
        ]);
        $session = otacSession($this->employee, '2030-03-20 17:00:00', null, $overtime->id); // exactly 60 min
        Task::create(['attendance_id' => $session->id, 'title' => 'Belum beres', 'is_completed' => false]);

        $this->artisan('attendance:auto-checkout')
            ->expectsOutputToContain('Found 1 attendance(s) exceeding required work hours.')
            ->expectsOutputToContain('Auto checkout completed: 1 success, 0 failed.')
            ->assertSuccessful();

        $fresh = $session->fresh();
        expect($fresh->check_out->format('H:i'))->toBe('18:00')
            ->and($fresh->is_auto_checkout)->toBeTrue()
            ->and((float) $fresh->total_hours)->toBe(1.0)
            ->and($fresh->tasks->first()->blocker_reason)->toBe('belum selesai');

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->employee->id,
            'action' => 'auto_checkout',
            'description' => 'Auto checkout - overtime limit reached - session: 1h, daily total: 8h (overtime allowed: 60 minutes)',
        ]);
    });

    it('leaves a session running while allowance is left', function () {
        $overtime = $this->createOvertime(['user_id' => $this->employee->id, 'planned_minutes' => 60]);
        $session = otacSession($this->employee, '2030-03-20 17:01:00', null, $overtime->id); // 59 min

        $this->artisan('attendance:auto-checkout')
            ->expectsOutputToContain('Found 0 attendance(s) exceeding required work hours.')
            ->assertSuccessful();

        expect($session->fresh()->check_out)->toBeNull();
    });

    it('caps by the minutes the manager approved instead of the ones requested', function () {
        $overtime = $this->createOvertime([
            'user_id' => $this->employee->id,
            'status' => OvertimeStatus::APPROVED,
            'approved_by' => $this->manager->id,
            'planned_minutes' => 120,
            'approved_minutes' => 30,
        ]);
        $session = otacSession($this->employee, '2030-03-20 17:30:00', null, $overtime->id); // 30 min

        $this->artisan('attendance:auto-checkout')->assertSuccessful();

        expect($session->fresh()->is_auto_checkout)->toBeTrue();
    });

    it('counts earlier finished sessions under the same record', function () {
        $overtime = $this->createOvertime(['user_id' => $this->employee->id, 'planned_minutes' => 90]);
        otacSession($this->employee, '2030-03-20 15:30:00', '2030-03-20 16:30:00', $overtime->id); // 60 min used
        $session = otacSession($this->employee, '2030-03-20 17:30:00', null, $overtime->id); // +30 min = 90

        $this->artisan('attendance:auto-checkout')->assertSuccessful();

        expect($session->fresh()->is_auto_checkout)->toBeTrue();
    });

    it('applies the requested minutes while the request is still pending', function () {
        $overtime = $this->createOvertime(['user_id' => $this->employee->id, 'planned_minutes' => 45]);
        $session = otacSession($this->employee, '2030-03-20 17:15:00', null, $overtime->id); // 45 min

        $this->artisan('attendance:auto-checkout')->assertSuccessful();

        expect($session->fresh()->is_auto_checkout)->toBeTrue();
    });

    it('closes a running session as soon as its overtime is rejected', function () {
        $overtime = $this->createOvertime(['user_id' => $this->employee->id, 'planned_minutes' => 120]);
        $session = otacSession($this->employee, '2030-03-20 17:55:00', null, $overtime->id); // only 5 min in

        $this->artisan('attendance:auto-checkout')->assertSuccessful();
        expect($session->fresh()->check_out)->toBeNull();

        $overtime->update(['status' => OvertimeStatus::REJECTED, 'approved_by' => $this->manager->id, 'notes' => 'Tidak perlu']);

        $this->artisan('attendance:auto-checkout')->assertSuccessful();

        $fresh = $session->fresh();
        expect($fresh->is_auto_checkout)->toBeTrue()
            ->and((float) $fresh->total_hours)->toBe(0.08);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'auto_checkout',
            'description' => 'Auto checkout - overtime limit reached - session: 0.08h, daily total: 7.08h (overtime allowed: 0 minutes)',
        ]);
    });

    it('does not touch sessions without an overtime record or finished ones', function () {
        $other = $this->createEmployee();
        $regular = otacSession($other, '2030-03-20 16:00:00'); // 2h regular session, not over the 7h either
        $overtime = $this->createOvertime(['user_id' => $this->employee->id, 'planned_minutes' => 15]);
        $finished = otacSession($this->employee, '2030-03-20 16:00:00', '2030-03-20 17:00:00', $overtime->id);

        $this->artisan('attendance:auto-checkout')
            ->expectsOutputToContain('Found 0 attendance(s) exceeding required work hours.')
            ->assertSuccessful();

        expect($regular->fresh()->check_out)->toBeNull()
            ->and($finished->fresh()->is_auto_checkout)->toBeFalse();
    });

    it('closes a session that trips both limits only once', function () {
        $overtime = $this->createOvertime(['user_id' => $this->employee->id, 'planned_minutes' => 60]);
        $other = $this->createEmployee(['name' => 'Bob']);
        // Linked to overtime, with fewer than 7 completed hours behind it and already 8h long:
        // both the required-hours rule and the overtime cap pick it up.
        $overtimeBob = $this->createOvertime(['user_id' => $other->id, 'planned_minutes' => 60]);
        $session = otacSession($other, '2030-03-20 10:00:00', null, $overtimeBob->id);

        $this->artisan('attendance:auto-checkout')
            ->expectsOutputToContain('Found 1 attendance(s) exceeding required work hours.')
            ->expectsOutputToContain('Auto checkout completed: 1 success, 0 failed.')
            ->assertSuccessful();

        expect($session->fresh()->is_auto_checkout)->toBeTrue()
            ->and($overtime->fresh()->isPending())->toBeTrue();
    });
});

describe('AttendanceRepository::findAllActiveOvertimeOverLimit', function () {
    it('returns only running overtime sessions that reached their allowance', function () {
        $repository = app(AttendanceRepository::class);
        $over = $this->createOvertime(['user_id' => $this->employee->id, 'planned_minutes' => 30]);
        $overSession = otacSession($this->employee, '2030-03-20 17:00:00', null, $over->id); // 60 > 30

        $bob = $this->createEmployee();
        $under = $this->createOvertime(['user_id' => $bob->id, 'planned_minutes' => 120]);
        otacSession($bob, '2030-03-20 17:30:00', null, $under->id); // 30 < 120

        $carol = $this->createEmployee();
        otacSession($carol, '2030-03-20 17:00:00'); // no overtime record

        expect($repository->findAllActiveOvertimeOverLimit()->pluck('id')->all())->toBe([$overSession->id]);
    });
});
