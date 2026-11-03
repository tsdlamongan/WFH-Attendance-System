<?php

use App\Enums\OvertimeStatus;
use App\Models\Attendance;
use App\Models\Overtime;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

/*
 * Overtime rules at check-in: once the day's required hours (7h) are met, a check-in is an
 * overtime session and needs a reason + duration, unless a record already exists for the day.
 * "Now" is frozen at Wednesday 20 March 2030 18:00.
 */

/**
 * Bearer header for a user. Guards are reset so several actors can be used in one test.
 */
function cioAuth(User $user): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer '.$user->createToken('auth-token')->plainTextToken];
}

/**
 * A finished session of the given length that ended before "now".
 */
function cioFinishedSession(User $user, float $hours, ?int $overtimeId = null): Attendance
{
    $out = Carbon::parse('2030-03-20 16:00:00');
    $in = $out->copy()->subMinutes((int) round($hours * 60));

    return Attendance::create([
        'user_id' => $user->id,
        'check_in' => $in,
        'check_out' => $out,
        'date' => '2030-03-20',
        'total_hours' => $hours,
        'overtime_id' => $overtimeId,
    ]);
}

function cioCheckIn(object $test, User $user, array $extra = [])
{
    return $test->postJson('/api/v1/attendance/check-in', array_merge([
        'tasks' => [['title' => 'Kerjakan laporan']],
    ], $extra), cioAuth($user));
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(Carbon::parse('2030-03-20 18:00:00'));
    $this->team = $this->createTeam(['required_work_hours' => 7.0]);
    $this->manager = $this->createManager();
    $this->employee = $this->createEmployee(['name' => 'Alice']);
});

describe('regular sessions', function () {
    it('needs no overtime before the required hours are met', function () {
        cioFinishedSession($this->employee, 3.0);

        cioCheckIn($this, $this->employee)->assertCreated();

        expect(Attendance::latest('id')->first()->overtime_id)->toBeNull();
        $this->assertDatabaseCount('overtimes', 0);
    });

    it('ignores an overtime payload sent for a regular session', function () {
        cioCheckIn($this, $this->employee, ['overtime' => ['reason' => 'Tidak perlu', 'planned_minutes' => 60]])
            ->assertCreated();

        expect(Attendance::first()->overtime_id)->toBeNull();
        $this->assertDatabaseCount('overtimes', 0);
    });

    it('does not count another day or another employee towards the required hours', function () {
        Attendance::create([
            'user_id' => $this->employee->id,
            'check_in' => '2030-03-19 09:00:00',
            'check_out' => '2030-03-19 17:00:00',
            'date' => '2030-03-19',
            'total_hours' => 8,
        ]);
        cioFinishedSession($this->createEmployee(), 8.0);

        cioCheckIn($this, $this->employee)->assertCreated();

        expect(Attendance::where('user_id', $this->employee->id)->latest('id')->first()->overtime_id)->toBeNull();
    });
});

describe('overtime sessions', function () {
    beforeEach(function () {
        cioFinishedSession($this->employee, 7.0);
    });

    it('requires a reason and duration when no overtime exists yet', function () {
        cioCheckIn($this, $this->employee)
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Check-in lembur memerlukan alasan dan durasi lembur.');

        expect(Attendance::count())->toBe(1);
        $this->assertDatabaseCount('overtimes', 0);
    });

    it('creates a pending request from the check-in payload and links the session', function () {
        cioCheckIn($this, $this->employee, ['overtime' => ['reason' => 'Mengejar tenggat rilis', 'planned_minutes' => 90]])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $overtime = Overtime::first();
        $attendance = Attendance::latest('id')->first();

        expect($overtime->user_id)->toBe($this->employee->id)
            ->and($overtime->status)->toBe(OvertimeStatus::PENDING)
            ->and($overtime->source)->toBe('employee')
            ->and($overtime->planned_minutes)->toBe(90)
            ->and($overtime->reason)->toBe('Mengejar tenggat rilis')
            ->and($overtime->date->toDateString())->toBe('2030-03-20')
            ->and($attendance->overtime_id)->toBe($overtime->id)
            ->and($attendance->isActive())->toBeTrue();

        $this->assertDatabaseHas('activity_logs', ['user_id' => $this->employee->id, 'action' => 'overtime_requested']);
        $this->assertDatabaseHas('activity_logs', ['user_id' => $this->employee->id, 'action' => 'check_in']);
    });

    it('validates a partial overtime payload', function (array $overtime, string $field) {
        cioCheckIn($this, $this->employee, ['overtime' => $overtime])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        expect(Attendance::count())->toBe(1);
    })->with([
        'only reason' => [['reason' => 'Mengejar tenggat'], 'overtime.planned_minutes'],
        'only minutes' => [['planned_minutes' => 60], 'overtime.reason'],
        'reason too short' => [['reason' => 'abc', 'planned_minutes' => 60], 'overtime.reason'],
        'minutes too low' => [['reason' => 'Mengejar tenggat', 'planned_minutes' => 10], 'overtime.planned_minutes'],
        'minutes too high' => [['reason' => 'Mengejar tenggat', 'planned_minutes' => 500], 'overtime.planned_minutes'],
    ]);

    it('reuses a request made in advance without asking again', function (OvertimeStatus $status) {
        $existing = $this->createOvertime([
            'user_id' => $this->employee->id,
            'status' => $status,
            'approved_by' => $status === OvertimeStatus::APPROVED ? $this->manager->id : null,
        ]);

        cioCheckIn($this, $this->employee)->assertCreated();

        expect(Attendance::latest('id')->first()->overtime_id)->toBe($existing->id);
        $this->assertDatabaseCount('overtimes', 1);
    })->with([
        'pending' => [OvertimeStatus::PENDING],
        'approved' => [OvertimeStatus::APPROVED],
    ]);

    it('ignores a payload when a record already exists', function () {
        $existing = $this->createOvertime(['user_id' => $this->employee->id]);

        cioCheckIn($this, $this->employee, ['overtime' => ['reason' => 'Alasan baru lagi', 'planned_minutes' => 240]])
            ->assertCreated();

        $this->assertDatabaseCount('overtimes', 1);
        expect($existing->fresh()->planned_minutes)->toBe(60);
    });

    it('blocks check-in once the overtime of the day was rejected', function () {
        $this->createOvertime(['user_id' => $this->employee->id, 'status' => OvertimeStatus::REJECTED]);

        cioCheckIn($this, $this->employee, ['overtime' => ['reason' => 'Coba lagi saja', 'planned_minutes' => 60]])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Pengajuan lembur Anda hari ini ditolak. Anda tidak dapat check-in lembur.');

        expect(Attendance::count())->toBe(1);
        $this->assertDatabaseCount('overtimes', 1);
    });

    it('lets a manager assignment replace an earlier rejection', function () {
        $this->createOvertime(['user_id' => $this->employee->id, 'status' => OvertimeStatus::REJECTED]);
        $assigned = $this->createOvertime([
            'user_id' => $this->employee->id,
            'status' => OvertimeStatus::APPROVED,
            'source' => 'manager',
            'approved_by' => $this->manager->id,
        ]);

        cioCheckIn($this, $this->employee)->assertCreated();

        expect(Attendance::latest('id')->first()->overtime_id)->toBe($assigned->id);
    });

    it('blocks check-in when the granted duration is already used up', function () {
        $overtime = $this->createOvertime([
            'user_id' => $this->employee->id,
            'status' => OvertimeStatus::APPROVED,
            'approved_by' => $this->manager->id,
            'planned_minutes' => 120,
            'approved_minutes' => 60,
        ]);
        cioFinishedSession($this->employee, 1.0, $overtime->id);

        cioCheckIn($this, $this->employee)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Durasi lembur yang diajukan hari ini sudah habis. Hubungi manager untuk menambah durasi.');

        expect(Attendance::count())->toBe(2);
    });

    it('still allows a check-in while part of the duration is left', function () {
        $overtime = $this->createOvertime([
            'user_id' => $this->employee->id,
            'status' => OvertimeStatus::APPROVED,
            'approved_by' => $this->manager->id,
            'planned_minutes' => 120,
        ]);
        cioFinishedSession($this->employee, 1.0, $overtime->id);

        cioCheckIn($this, $this->employee)->assertCreated();

        expect(Attendance::latest('id')->first()->overtime_id)->toBe($overtime->id);
    });

    it('creates no overtime when the check-in is refused for another reason', function () {
        // An active session already exists, so check-in is refused before any overtime is created.
        Attendance::create([
            'user_id' => $this->employee->id,
            'check_in' => '2030-03-20 17:00:00',
            'date' => '2030-03-20',
            'total_hours' => 0,
        ]);

        cioCheckIn($this, $this->employee, ['overtime' => ['reason' => 'Mengejar tenggat', 'planned_minutes' => 60]])
            ->assertUnprocessable();

        $this->assertDatabaseCount('overtimes', 0);
    });
});

describe('WhatsApp notification on overtime check-in', function () {
    beforeEach(function () {
        $this->team->update([
            'whatsapp_api_secret' => 'test-api-secret',
            'whatsapp_account_unique_id' => 'test-unique-id',
            'whatsapp_connected' => true,
            'whatsapp_recipient_phone' => '628123456789',
        ]);
        cioFinishedSession($this->employee, 7.0);
    });

    it('notifies the manager once when the check-in creates the request', function () {
        Http::fake(['whatsapp.perekonomian.id/api/send/whatsapp' => Http::response(['status' => 200], 200)]);

        cioCheckIn($this, $this->employee, ['overtime' => ['reason' => 'Mengejar tenggat rilis', 'planned_minutes' => 90]])
            ->assertCreated();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->body(), 'Alice mengajukan lembur 90 menit'));
    });

    it('stays silent when the check-in reuses an existing record', function () {
        Http::fake();
        $this->createOvertime(['user_id' => $this->employee->id]);

        cioCheckIn($this, $this->employee)->assertCreated();

        Http::assertNothingSent();
    });
});

describe('GET /attendance/today overtime fields', function () {
    it('does not ask for overtime before the required hours are met', function () {
        cioFinishedSession($this->employee, 3.0);

        $this->getJson('/api/v1/attendance/today', cioAuth($this->employee))
            ->assertOk()
            ->assertJsonPath('data.requires_overtime_request', false)
            ->assertJsonPath('data.overtime', null);
    });

    it('asks for overtime once the required hours are met and nothing is on file', function () {
        cioFinishedSession($this->employee, 7.0);

        $this->getJson('/api/v1/attendance/today', cioAuth($this->employee))
            ->assertOk()
            ->assertJsonPath('data.requires_overtime_request', true)
            ->assertJsonPath('data.overtime', null);
    });

    it('describes the overtime of the day with its remaining minutes', function () {
        cioFinishedSession($this->employee, 7.0);
        $overtime = $this->createOvertime([
            'user_id' => $this->employee->id,
            'status' => OvertimeStatus::APPROVED,
            'approved_by' => $this->manager->id,
            'planned_minutes' => 120,
            'approved_minutes' => 90,
            'reason' => 'Mengejar tenggat rilis',
            'notes' => 'Oke',
        ]);
        // 30 minutes into a running overtime session.
        Attendance::create([
            'user_id' => $this->employee->id,
            'check_in' => '2030-03-20 17:30:00',
            'date' => '2030-03-20',
            'total_hours' => 0,
            'overtime_id' => $overtime->id,
        ]);

        $this->getJson('/api/v1/attendance/today', cioAuth($this->employee))
            ->assertOk()
            ->assertJsonPath('data.requires_overtime_request', false)
            ->assertJsonPath('data.overtime.id', $overtime->id)
            ->assertJsonPath('data.overtime.status', 'approved')
            ->assertJsonPath('data.overtime.date', '2030-03-20')
            ->assertJsonPath('data.overtime.reason', 'Mengejar tenggat rilis')
            ->assertJsonPath('data.overtime.notes', 'Oke')
            ->assertJsonPath('data.overtime.planned_minutes', 120)
            ->assertJsonPath('data.overtime.approved_minutes', 90)
            ->assertJsonPath('data.overtime.allowed_minutes', 90)
            ->assertJsonPath('data.overtime.used_minutes', 30)
            ->assertJsonPath('data.overtime.remaining_minutes', 60);
    });

    it('reports a rejected request with nothing allowed and no new request needed', function () {
        cioFinishedSession($this->employee, 7.0);
        $this->createOvertime(['user_id' => $this->employee->id, 'status' => OvertimeStatus::REJECTED]);

        $this->getJson('/api/v1/attendance/today', cioAuth($this->employee))
            ->assertOk()
            ->assertJsonPath('data.requires_overtime_request', false)
            ->assertJsonPath('data.overtime.status', 'rejected')
            ->assertJsonPath('data.overtime.allowed_minutes', 0)
            ->assertJsonPath('data.overtime.remaining_minutes', 0);
    });

    it('never reports a negative remainder when more was worked than allowed', function () {
        cioFinishedSession($this->employee, 7.0);
        $overtime = $this->createOvertime(['user_id' => $this->employee->id, 'planned_minutes' => 30]);
        cioFinishedSession($this->employee, 1.0, $overtime->id);

        $this->getJson('/api/v1/attendance/today', cioAuth($this->employee))
            ->assertJsonPath('data.overtime.used_minutes', 60)
            ->assertJsonPath('data.overtime.remaining_minutes', 0);
    });
});

describe('Overtime model', function () {
    it('caps by approved minutes, then planned minutes, and nothing once rejected', function () {
        $pending = Overtime::factory()->make(['planned_minutes' => 45]);
        $approved = Overtime::factory()->approved(null, 20)->make(['planned_minutes' => 45]);
        $approvedAsAsked = Overtime::factory()->approved()->make(['planned_minutes' => 45]);
        $rejected = Overtime::factory()->rejected()->make(['planned_minutes' => 45]);

        expect($pending->allowedMinutes())->toBe(45)
            ->and($approved->allowedMinutes())->toBe(20)
            ->and($approvedAsAsked->allowedMinutes())->toBe(45)
            ->and($rejected->allowedMinutes())->toBe(0);
    });

    it('sums finished sessions and the running one', function () {
        $overtime = $this->createOvertime(['user_id' => $this->employee->id]);
        cioFinishedSession($this->employee, 0.5, $overtime->id); // 30 min
        Attendance::create([
            'user_id' => $this->employee->id,
            'check_in' => '2030-03-20 17:15:00', // 45 min before the frozen "now"
            'date' => '2030-03-20',
            'total_hours' => 0,
            'overtime_id' => $overtime->id,
        ]);

        expect(round($overtime->usedMinutes()))->toBe(75.0);
    });

    it('links to its sessions both ways and exposes status helpers', function () {
        $overtime = $this->createOvertime(['user_id' => $this->employee->id]);
        $attendance = cioFinishedSession($this->employee, 1.0, $overtime->id);

        expect($attendance->overtime->is($overtime))->toBeTrue()
            ->and($overtime->attendances)->toHaveCount(1)
            ->and($overtime->isPending())->toBeTrue()
            ->and($overtime->isApproved())->toBeFalse()
            ->and($overtime->isRejected())->toBeFalse();
    });
});
