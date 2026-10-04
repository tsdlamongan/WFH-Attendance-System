<?php

use App\Enums\ActivityType;
use App\Enums\UserRole;
use App\Http\Requests\AttendanceStoreRequest;
use App\Models\Attendance;
use App\Models\Task;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Mockery\MockInterface;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-03-11 14:00:00'));
    $this->team = $this->createTeam(['required_work_hours' => 7.0]);
});

function cmdActiveAttendance(User $user, string $checkIn): Attendance
{
    $in = Carbon::parse($checkIn);

    return Attendance::create([
        'user_id' => $user->id,
        'check_in' => $in,
        'check_out' => null,
        'date' => $in->toDateString(),
        'total_hours' => 0,
    ]);
}

// ---------------------------------------------------------------------------
// attendance:auto-checkout
// ---------------------------------------------------------------------------

describe('attendance:auto-checkout command', function () {
    it('reports that nothing needs to be checked out and stops early', function () {
        $employee = $this->createEmployee();
        cmdActiveAttendance($employee, '2026-03-11 10:00:00'); // 4h elapsed of 7h required

        $this->artisan('attendance:auto-checkout')
            ->expectsOutputToContain('Found 0 attendance(s) exceeding required work hours.')
            ->doesntExpectOutputToContain('Auto checkout completed')
            ->assertSuccessful();

        expect(Attendance::whereNull('check_out')->count())->toBe(1);
    });

    it('auto checks out every attendance that exceeded the required hours and reports the result', function () {
        $alice = $this->createEmployee(['name' => 'Alice']);
        $bob = $this->createEmployee(['name' => 'Bob']);
        $carol = $this->createEmployee(['name' => 'Carol']);

        $aliceAttendance = cmdActiveAttendance($alice, '2026-03-11 05:00:00'); // 9h
        $bobAttendance = cmdActiveAttendance($bob, '2026-03-11 06:30:00'); // 7.5h
        $carolAttendance = cmdActiveAttendance($carol, '2026-03-11 12:00:00'); // 2h, stays open
        Task::create(['attendance_id' => $aliceAttendance->id, 'title' => 'Unfinished', 'is_completed' => false]);

        $this->artisan('attendance:auto-checkout')
            ->expectsOutputToContain('Found 2 attendance(s) exceeding required work hours.')
            ->expectsOutputToContain("Auto checking out: Alice (Attendance #{$aliceAttendance->id})")
            ->expectsOutputToContain('Successfully auto-checked out Alice')
            ->expectsOutputToContain("Auto checking out: Bob (Attendance #{$bobAttendance->id})")
            ->expectsOutputToContain('Auto checkout completed: 2 success, 0 failed.')
            ->assertSuccessful();

        $aliceFresh = $aliceAttendance->fresh();
        expect($aliceFresh->check_out->format('H:i'))->toBe('14:00')
            ->and($aliceFresh->is_auto_checkout)->toBeTrue()
            ->and((float) $aliceFresh->total_hours)->toBe(9.0)
            ->and($aliceFresh->tasks->first()->blocker_reason)->toBe('belum selesai')
            ->and($bobAttendance->fresh()->is_auto_checkout)->toBeTrue()
            ->and($carolAttendance->fresh()->check_out)->toBeNull();

        $this->assertDatabaseHas('activity_logs', ['user_id' => $alice->id, 'action' => ActivityType::AUTO_CHECKOUT->value]);
        $this->assertDatabaseHas('activity_logs', ['user_id' => $bob->id, 'action' => ActivityType::AUTO_CHECKOUT->value]);
        $this->assertDatabaseMissing('activity_logs', ['user_id' => $carol->id, 'action' => ActivityType::AUTO_CHECKOUT->value]);
    });

    it('keeps going and counts a failure when one auto checkout throws', function () {
        Log::spy();
        $alice = $this->createEmployee(['name' => 'Alice']);
        $bob = $this->createEmployee(['name' => 'Bob']);
        $failing = cmdActiveAttendance($alice, '2026-03-11 05:00:00');
        $working = cmdActiveAttendance($bob, '2026-03-11 05:00:00');

        $this->mock(AttendanceService::class, function (MockInterface $mock) use ($failing) {
            $mock->shouldReceive('autoCheckOut')
                ->twice()
                ->andReturnUsing(function (Attendance $attendance) use ($failing) {
                    if ($attendance->id === $failing->id) {
                        throw new RuntimeException('boom');
                    }

                    return $attendance;
                });
        });

        $this->artisan('attendance:auto-checkout')
            ->expectsOutputToContain('Found 2 attendance(s) exceeding required work hours.')
            ->expectsOutputToContain("Failed to auto-checkout attendance #{$failing->id}: boom")
            ->expectsOutputToContain('Successfully auto-checked out Bob')
            ->expectsOutputToContain('Auto checkout completed: 1 success, 1 failed.')
            ->assertSuccessful();

        Log::shouldHaveReceived('error')
            ->once()
            ->with("Auto checkout command failed for attendance #{$failing->id}: boom");
    });

    it('names an attendance without a user as Unknown', function () {
        $orphan = new Attendance;
        $orphan->forceFill(['id' => 4242]);

        $this->mock(AttendanceRepository::class, function (MockInterface $mock) use ($orphan) {
            $mock->shouldReceive('findAllActiveExceedingHours')->once()->andReturn(new EloquentCollection([$orphan]));
        });
        $this->mock(AttendanceService::class, function (MockInterface $mock) {
            $mock->shouldReceive('autoCheckOut')->once()->andReturnUsing(fn (Attendance $attendance) => $attendance);
        });

        $this->artisan('attendance:auto-checkout')
            ->expectsOutputToContain('Auto checking out: Unknown (Attendance #4242)')
            ->expectsOutputToContain('Auto checkout completed: 1 success, 0 failed.')
            ->assertSuccessful();
    });
});

// ---------------------------------------------------------------------------
// AttendanceStoreRequest (POST /api/v1/manager/attendances)
// ---------------------------------------------------------------------------

describe('AttendanceStoreRequest', function () {
    beforeEach(function () {
        $this->manager = $this->createManager(['name' => 'Boss']);
        $this->employee = $this->createEmployee(['name' => 'Alice']);
        $this->otherTeam = $this->createTeam();
        $this->outsider = User::factory()->create(['team_id' => $this->otherTeam->id, 'role' => 'employee', 'name' => 'Zed']);

        $this->payload = fn (array $overrides = []) => array_merge([
            'user_id' => $this->employee->id,
            'date' => '2026-03-10',
            'check_in' => '2026-03-10 09:00:00',
            'check_out' => '2026-03-10 17:00:00',
            'tasks' => [['title' => 'Backfilled task']],
            'reason' => 'Forgot to check in yesterday',
        ], $overrides);

        $this->post = function (array $payload, ?User $as = null) {
            $token = ($as ?? $this->manager)->createToken('auth-token')->plainTextToken;

            return $this->postJson('/api/v1/manager/attendances', $payload, ['Authorization' => "Bearer {$token}"]);
        };
    });

    it('lets a manager add an attendance for an employee of the team', function () {
        $response = ($this->post)(($this->payload)());

        $response->assertCreated()
            ->assertJson(['success' => true, 'message' => 'Absensi berhasil ditambahkan'])
            ->assertJsonPath('data.user_id', $this->employee->id)
            ->assertJsonPath('data.date', '2026-03-10')
            ->assertJsonPath('data.total_hours', 8)
            ->assertJsonPath('data.tasks.0.title', 'Backfilled task');

        $this->assertDatabaseHas('attendances', ['user_id' => $this->employee->id, 'total_hours' => 8.0]);
        $this->assertDatabaseHas('tasks', ['title' => 'Backfilled task']);
    });

    it('accepts an open attendance without a check-out', function () {
        $response = ($this->post)(($this->payload)(['check_out' => null]));

        $response->assertCreated()
            ->assertJsonPath('data.check_out', null)
            ->assertJsonPath('data.total_hours', 0);
    });

    it('lets a super admin add an attendance for an employee of any team', function () {
        $admin = $this->createUser(['role' => UserRole::SUPER_ADMIN, 'team_id' => null, 'name' => 'Root']);

        $response = ($this->post)(($this->payload)(['user_id' => $this->outsider->id]), $admin);

        $response->assertCreated()->assertJsonPath('data.user_id', $this->outsider->id);
        $this->assertDatabaseHas('attendances', ['user_id' => $this->outsider->id]);
    });

    it('requires a user and does not look anyone up when the user is missing', function () {
        $payload = ($this->payload)();
        unset($payload['user_id']);

        $response = ($this->post)($payload);

        $response->assertStatus(422)
            ->assertJsonPath('errors.user_id.0', 'Karyawan wajib dipilih.')
            ->assertJsonCount(1, 'errors');
        $this->assertDatabaseCount('attendances', 0);
    });

    it('rejects a user that does not exist', function () {
        $response = ($this->post)(($this->payload)(['user_id' => 999999]));

        $response->assertStatus(422)
            ->assertJsonPath('errors.user_id.0', 'Karyawan tidak ditemukan.')
            ->assertJsonCount(1, 'errors');
        $this->assertDatabaseCount('attendances', 0);
    });

    it('stops a manager from adding attendance for another team', function () {
        $response = ($this->post)(($this->payload)(['user_id' => $this->outsider->id]));

        $response->assertStatus(422)
            ->assertJsonPath('errors.user_id.0', 'Anda hanya dapat menambah absensi untuk karyawan dalam tim Anda.');
        $this->assertDatabaseCount('attendances', 0);
        $this->assertDatabaseCount('tasks', 0);
    });

    it('requires the check-in to fall on the attendance date', function () {
        $response = ($this->post)(($this->payload)(['date' => '2026-03-10', 'check_in' => '2026-03-09 23:30:00', 'check_out' => '2026-03-10 07:00:00']));

        $response->assertStatus(422)
            ->assertJsonPath('errors.check_in.0', 'Tanggal check-in harus sama dengan tanggal absensi.');
        $this->assertDatabaseCount('attendances', 0);
    });

    it('answers an unparseable date or check-in with a 422 validation error instead of crashing', function (array $overrides, string $field, string $message) {
        $response = ($this->post)(($this->payload)($overrides));

        $response->assertStatus(422)->assertJsonValidationErrors([$field => $message]);
        expect($response->json('errors.check_in') ?? [])->not->toContain('Tanggal check-in harus sama dengan tanggal absensi.');
        $this->assertDatabaseCount('attendances', 0);
        $this->assertDatabaseCount('tasks', 0);
    })->with([
        'date is not a date' => [['date' => 'not-a-date'], 'date', 'Tanggal tidak valid.'],
        'date does not exist on the calendar' => [['date' => '2026-02-30'], 'date', 'Tanggal tidak valid.'],
        'date is an array' => [['date' => ['2026-03-10']], 'date', 'Tanggal tidak valid.'],
        'check-in is not a date' => [['check_in' => 'garbage'], 'check_in', 'Waktu check-in tidak valid.'],
        'check-in is an array' => [['check_in' => ['2026-03-10 09:00:00']], 'check_in', 'Waktu check-in tidak valid.'],
    ]);

    it('reports every unparseable date field at once', function () {
        $response = ($this->post)(($this->payload)(['date' => 'nope', 'check_in' => 'nada', 'check_out' => 'never']));

        $response->assertStatus(422)->assertJsonValidationErrors(['date', 'check_in', 'check_out']);
        expect($response->json('errors.date.0'))->toBe('Tanggal tidak valid.')
            ->and($response->json('errors.check_in.0'))->toBe('Waktu check-in tidak valid.');
        $this->assertDatabaseCount('attendances', 0);
    });

    it('rejects invalid input with specific messages', function (array $overrides, string $field, string $message) {
        $response = ($this->post)(($this->payload)($overrides));

        $response->assertStatus(422)->assertJsonValidationErrors([$field => $message]);
        $this->assertDatabaseCount('attendances', 0);
    })->with([
        'missing date' => [['date' => null], 'date', 'Tanggal wajib diisi.'],
        'missing check-in' => [['check_in' => null], 'check_in', 'Waktu check-in wajib diisi.'],
        'check-out before check-in' => [['check_out' => '2026-03-10 08:00:00'], 'check_out', 'Waktu check-out harus setelah check-in.'],
        'check-out equal to check-in' => [['check_out' => '2026-03-10 09:00:00'], 'check_out', 'Waktu check-out harus setelah check-in.'],
        'no tasks' => [['tasks' => null], 'tasks', 'Minimal satu tugas wajib diisi.'],
        'empty task list' => [['tasks' => []], 'tasks', 'Minimal satu tugas wajib diisi.'],
        'task without title' => [['tasks' => [['title' => '']]], 'tasks.0.title', 'Judul tugas wajib diisi.'],
        'task title too long' => [['tasks' => [['title' => str_repeat('x', 256)]]], 'tasks.0.title', 'Judul tugas maksimal 255 karakter.'],
        'missing reason' => [['reason' => null], 'reason', 'Alasan wajib diisi untuk audit.'],
        'reason too short' => [['reason' => 'too short'], 'reason', 'Alasan minimal 10 karakter.'],
    ]);

    it('allows at most 20 tasks', function () {
        $tasks = array_map(fn ($i) => ['title' => "Task {$i}"], range(1, 21));

        $response = ($this->post)(($this->payload)(['tasks' => $tasks]));

        $response->assertStatus(422)->assertJsonValidationErrors(['tasks']);

        $accepted = ($this->post)(($this->payload)(['tasks' => array_slice($tasks, 0, 20)]));
        $accepted->assertCreated();
        $this->assertDatabaseCount('tasks', 20);
    });

    it('only authorises managers and super admins', function () {
        $admin = $this->createUser(['role' => UserRole::SUPER_ADMIN, 'team_id' => null]);
        $request = new AttendanceStoreRequest;

        expect($request->authorize())->toBeFalse(); // guest

        $this->actingAs($this->employee);
        expect($request->authorize())->toBeFalse();

        $this->actingAs($this->manager);
        expect($request->authorize())->toBeTrue();

        $this->actingAs($admin);
        expect($request->authorize())->toBeTrue();
    });

    it('keeps managers inside their team while super admins may cross teams', function () {
        $admin = $this->createUser(['role' => UserRole::SUPER_ADMIN, 'team_id' => null]);
        $payload = ($this->payload)(['user_id' => $this->outsider->id]);

        $validate = function (User $actor) use ($payload) {
            $this->actingAs($actor);
            $request = AttendanceStoreRequest::create('/api/v1/manager/attendances', 'POST', $payload);
            $validator = Validator::make($payload, $request->rules(), $request->messages());
            $request->withValidator($validator);

            // The "after" hook reads the acting user, so it has to run while that user is logged in.
            return [$validator->passes(), $validator->errors()->first('user_id')];
        };

        [$managerPasses, $managerError] = $validate($this->manager);
        [$adminPasses, $adminError] = $validate($admin);

        expect($managerPasses)->toBeFalse()
            ->and($managerError)->toBe('Anda hanya dapat menambah absensi untuk karyawan dalam tim Anda.')
            ->and($adminPasses)->toBeTrue()
            ->and($adminError)->toBe('');
    });

    it('blocks employees from the endpoint', function () {
        ($this->post)(($this->payload)(), $this->employee)->assertForbidden();

        $this->assertDatabaseCount('attendances', 0);
    });

    it('blocks guests from the endpoint', function () {
        $this->postJson('/api/v1/manager/attendances', ($this->payload)())->assertUnauthorized();

        $this->assertDatabaseCount('attendances', 0);
    });
});
