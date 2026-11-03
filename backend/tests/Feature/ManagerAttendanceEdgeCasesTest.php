<?php

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Task;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

/**
 * Bearer header for a user. Guards are reset so several actors can be used in one test.
 */
function maecAuth(User $user): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer '.$user->createToken('auth-token')->plainTextToken];
}

beforeEach(function () {
    $this->manager = $this->createManager();
    $this->employee = $this->createEmployee(['name' => 'Alice Employee']);
    $this->secondEmployee = $this->createEmployee(['name' => 'Bob Employee']);

    $this->attendance = Attendance::factory()->create([
        'user_id' => $this->employee->id,
        'check_in' => Carbon::parse('2030-03-15 09:00:00'),
        'check_out' => Carbon::parse('2030-03-15 17:00:00'),
        'date' => '2030-03-15',
        'total_hours' => 8.0,
    ]);

    // An employee in a different team with an attendance record.
    $this->otherEmployee = User::factory()->create(['role' => 'employee']);
    $this->otherAttendance = Attendance::factory()->create([
        'user_id' => $this->otherEmployee->id,
        'date' => '2030-03-15',
        'check_in' => Carbon::parse('2030-03-15 08:00:00'),
        'check_out' => Carbon::parse('2030-03-15 16:00:00'),
    ]);
});

describe('index', function () {
    it('requires authentication and the manager role', function () {
        $this->getJson('/api/v1/manager/attendances')->assertUnauthorized();
        $this->getJson('/api/v1/manager/attendances', maecAuth($this->employee))->assertForbidden();
    });

    it('defaults to ten rows per page and reports pagination metadata', function () {
        foreach (range(1, 14) as $day) {
            Attendance::factory()->create([
                'user_id' => $this->employee->id,
                'date' => Carbon::parse('2030-04-01')->addDays($day)->toDateString(),
                'check_in' => Carbon::parse('2030-04-01 09:00:00')->addDays($day),
                'check_out' => Carbon::parse('2030-04-01 17:00:00')->addDays($day),
            ]);
        }

        $response = $this->getJson('/api/v1/manager/attendances', maecAuth($this->manager));

        $response->assertOk()->assertJsonCount(10, 'data');
        expect($response->json('pagination'))->toBe([
            'current_page' => 1,
            'last_page' => 2,
            'per_page' => 10,
            'total' => 15,
            'from' => 1,
            'to' => 10,
        ]);
    });

    it('serves the requested page and honours per_page', function () {
        foreach (range(1, 14) as $day) {
            Attendance::factory()->create([
                'user_id' => $this->employee->id,
                'date' => Carbon::parse('2030-04-01')->addDays($day)->toDateString(),
                'check_in' => Carbon::parse('2030-04-01 09:00:00')->addDays($day),
                'check_out' => Carbon::parse('2030-04-01 17:00:00')->addDays($day),
            ]);
        }

        $second = $this->getJson('/api/v1/manager/attendances?page=2', maecAuth($this->manager));
        $second->assertOk()->assertJsonCount(5, 'data');
        expect($second->json('pagination.current_page'))->toBe(2)
            ->and($second->json('pagination.from'))->toBe(11)
            ->and($second->json('pagination.to'))->toBe(15);

        $all = $this->getJson('/api/v1/manager/attendances?per_page=50', maecAuth($this->manager));
        $all->assertOk()->assertJsonCount(15, 'data');
        expect($all->json('pagination.last_page'))->toBe(1)
            ->and($all->json('pagination.per_page'))->toBe(50);
    });

    it('lists the newest attendance first and never includes other teams', function () {
        $newer = Attendance::factory()->create([
            'user_id' => $this->secondEmployee->id,
            'date' => '2030-03-20',
            'check_in' => Carbon::parse('2030-03-20 09:00:00'),
            'check_out' => Carbon::parse('2030-03-20 17:00:00'),
        ]);

        $response = $this->getJson('/api/v1/manager/attendances', maecAuth($this->manager));

        $ids = collect($response->json('data'))->pluck('id')->all();
        expect($ids)->toBe([$newer->id, $this->attendance->id])
            ->and($ids)->not->toContain($this->otherAttendance->id)
            ->and($response->json('pagination.total'))->toBe(2);
    });

    it('treats the start and end dates as inclusive', function () {
        $before = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'date' => '2030-03-14',
            'check_in' => Carbon::parse('2030-03-14 09:00:00'),
            'check_out' => Carbon::parse('2030-03-14 17:00:00'),
        ]);
        $after = Attendance::factory()->create([
            'user_id' => $this->employee->id,
            'date' => '2030-03-16',
            'check_in' => Carbon::parse('2030-03-16 09:00:00'),
            'check_out' => Carbon::parse('2030-03-16 17:00:00'),
        ]);

        $response = $this->getJson(
            '/api/v1/manager/attendances?start_date=2030-03-15&end_date=2030-03-15',
            maecAuth($this->manager)
        );

        $ids = collect($response->json('data'))->pluck('id')->all();
        expect($ids)->toBe([$this->attendance->id])
            ->and($ids)->not->toContain($before->id, $after->id);
    });

    it('cannot be used to read another teams attendance through user_id', function () {
        $response = $this->getJson(
            "/api/v1/manager/attendances?user_id={$this->otherEmployee->id}",
            maecAuth($this->manager)
        );

        $response->assertOk()->assertJsonCount(0, 'data');
        expect($response->json('pagination.total'))->toBe(0)
            ->and($response->json('pagination.from'))->toBeNull();
    });

    it('answers invalid filters with a 422 validation error instead of a 500', function (string $query, string $field) {
        Log::spy();

        $response = $this->getJson("/api/v1/manager/attendances?{$query}", maecAuth($this->manager));

        $response->assertUnprocessable()->assertJsonValidationErrors([$field]);
        Log::shouldNotHaveReceived('error');
    })->with([
        'malformed start date' => ['start_date=15-03-2030', 'start_date'],
        'end before start' => ['start_date=2030-03-15&end_date=2030-03-14', 'end_date'],
        'unsupported page size' => ['per_page=7', 'per_page'],
        'unknown user' => ['user_id=999999', 'user_id'],
    ]);

    it('returns a generic 500 and logs when the repository fails', function () {
        Log::spy();
        $this->mock(AttendanceRepository::class, function ($mock) {
            $mock->shouldReceive('getPaginatedInDateRange')->once()->andThrow(new RuntimeException('replica lag'));
        });

        $response = $this->getJson('/api/v1/manager/attendances', maecAuth($this->manager));

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Failed to get attendances']);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Get attendances failed: replica lag'))
            ->once();
    });
});

describe('store', function () {
    $payload = fn (array $overrides = []) => array_merge([
        'user_id' => null,
        'date' => '2030-05-06',
        'check_in' => '2030-05-06T09:00:00',
        'check_out' => '2030-05-06T13:30:00',
        'tasks' => [['title' => 'Backfilled task one'], ['title' => 'Backfilled task two']],
        'reason' => 'Karyawan lupa melakukan absen',
    ], $overrides);

    it('creates the attendance with computed hours and its tasks', function () use ($payload) {
        $response = $this->postJson('/api/v1/manager/attendances', $payload([
            'user_id' => $this->employee->id,
        ]), maecAuth($this->manager));

        $response->assertCreated()
            ->assertJsonPath('message', 'Absensi berhasil ditambahkan')
            ->assertJsonPath('data.user_id', $this->employee->id)
            ->assertJsonPath('data.total_hours', 4.5)
            ->assertJsonCount(2, 'data.tasks');

        $attendance = Attendance::find($response->json('data.id'));
        expect($attendance->date->format('Y-m-d'))->toBe('2030-05-06')
            ->and($attendance->tasks->pluck('title')->all())
            ->toBe(['Backfilled task one', 'Backfilled task two'])
            ->and($attendance->tasks->every(fn ($task) => $task->is_completed === false))->toBeTrue();
    });

    it('requires authentication', function () use ($payload) {
        $this->postJson('/api/v1/manager/attendances', $payload(['user_id' => $this->employee->id]))
            ->assertUnauthorized();
    });

    it('requires every mandatory field', function () {
        $response = $this->postJson('/api/v1/manager/attendances', [], maecAuth($this->manager));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id', 'date', 'check_in', 'tasks', 'reason']);
        expect(Attendance::count())->toBe(2);
    });

    it('rejects an unknown user', function () use ($payload) {
        $response = $this->postJson('/api/v1/manager/attendances', $payload([
            'user_id' => 999999,
        ]), maecAuth($this->manager));

        $response->assertUnprocessable()->assertJsonValidationErrors(['user_id']);
        expect($response->json('errors.user_id.0'))->toBe('Karyawan tidak ditemukan.');
    });

    it('rejects a check out that is not after the check in', function () use ($payload) {
        $response = $this->postJson('/api/v1/manager/attendances', $payload([
            'user_id' => $this->employee->id,
            'check_out' => '2030-05-06T09:00:00',
        ]), maecAuth($this->manager));

        $response->assertUnprocessable()->assertJsonValidationErrors(['check_out']);
        expect($response->json('errors.check_out.0'))->toBe('Waktu check-out harus setelah check-in.');
    });

    it('rejects more than twenty tasks and untitled tasks', function () use ($payload) {
        $tooMany = $this->postJson('/api/v1/manager/attendances', $payload([
            'user_id' => $this->employee->id,
            'tasks' => array_fill(0, 21, ['title' => 'x']),
        ]), maecAuth($this->manager));
        $tooMany->assertUnprocessable()->assertJsonValidationErrors(['tasks']);

        $untitled = $this->postJson('/api/v1/manager/attendances', $payload([
            'user_id' => $this->employee->id,
            'tasks' => [['title' => '']],
        ]), maecAuth($this->manager));
        $untitled->assertUnprocessable()->assertJsonValidationErrors(['tasks.0.title']);

        expect(Attendance::count())->toBe(2);
    });

    it('requires a reason of at least ten characters', function () use ($payload) {
        $response = $this->postJson('/api/v1/manager/attendances', $payload([
            'user_id' => $this->employee->id,
            'reason' => 'too short',
        ]), maecAuth($this->manager));

        $response->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        expect($response->json('errors.reason.0'))->toBe('Alasan minimal 10 karakter.');
    });

    it('rejects creating attendance for a user in another team', function () use ($payload) {
        $response = $this->postJson('/api/v1/manager/attendances', $payload([
            'user_id' => $this->otherEmployee->id,
        ]), maecAuth($this->manager));

        $response->assertUnprocessable()->assertJsonValidationErrors(['user_id']);
        expect(Attendance::where('user_id', $this->otherEmployee->id)->count())->toBe(1);
    });

    it('returns a generic 500 and logs when persisting fails', function () use ($payload) {
        Log::spy();
        $this->mock(AttendanceRepository::class, function ($mock) {
            $mock->shouldReceive('create')->once()->andThrow(new RuntimeException('deadlock'));
        });

        $response = $this->postJson('/api/v1/manager/attendances', $payload([
            'user_id' => $this->employee->id,
        ]), maecAuth($this->manager));

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Gagal menambah absensi']);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Create attendance failed: deadlock'))
            ->once();
    });
});

describe('update', function () {
    $payload = fn (array $overrides = []) => array_merge([
        'date' => '2030-03-15',
        'check_in' => '2030-03-15T10:00:00',
        'check_out' => '2030-03-15T16:30:00',
        'reason' => 'Koreksi jam kerja karyawan',
    ], $overrides);

    it('returns 404 when the attendance does not exist', function () use ($payload) {
        $response = $this->putJson('/api/v1/manager/attendances/999999', $payload(), maecAuth($this->manager));

        $response->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Attendance not found']);
    });

    it('returns 403 and changes nothing for another teams attendance', function () use ($payload) {
        $response = $this->putJson(
            "/api/v1/manager/attendances/{$this->otherAttendance->id}",
            $payload(['date' => '2030-03-15']),
            maecAuth($this->manager)
        );

        $response->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Unauthorized to edit this attendance']);
        expect($this->otherAttendance->fresh()->check_in->format('H:i'))->toBe('08:00');
    });

    it('recomputes total hours and records the reason in the activity log', function () use ($payload) {
        $response = $this->putJson(
            "/api/v1/manager/attendances/{$this->attendance->id}",
            $payload(),
            maecAuth($this->manager)
        );

        $response->assertOk()
            ->assertJsonPath('message', 'Attendance updated successfully')
            ->assertJsonPath('data.total_hours', 6.5);

        $fresh = $this->attendance->fresh();
        expect($fresh->check_in->format('H:i'))->toBe('10:00')
            ->and($fresh->check_out->format('H:i'))->toBe('16:30')
            ->and((float) $fresh->total_hours)->toBe(6.5);

        $log = ActivityLog::where('action', ActivityType::ATTENDANCE_EDITED->value)
            ->where('description', 'like', "Edited attendance #{$this->attendance->id}%")
            ->first();
        expect($log)->not->toBeNull()
            ->and($log->user_id)->toBe($this->manager->id)
            ->and($log->description)->toContain('Alice Employee')
            ->and($log->description)->toContain('Reason: Koreksi jam kerja karyawan');
    });

    it('clears the check out and zeroes the hours when check_out is null', function () use ($payload) {
        $response = $this->putJson(
            "/api/v1/manager/attendances/{$this->attendance->id}",
            $payload(['check_out' => null]),
            maecAuth($this->manager)
        );

        $response->assertOk()
            ->assertJsonPath('data.check_out', null)
            ->assertJsonPath('data.total_hours', 0);
        expect($this->attendance->fresh()->check_out)->toBeNull();
    });

    it('treats an omitted check_out as null instead of failing with a 500', function () use ($payload) {
        $body = $payload();
        unset($body['check_out']);

        $response = $this->putJson(
            "/api/v1/manager/attendances/{$this->attendance->id}",
            $body,
            maecAuth($this->manager)
        );

        $response->assertOk()
            ->assertJsonPath('data.check_out', null)
            ->assertJsonPath('data.total_hours', 0);
        $fresh = $this->attendance->fresh();
        expect($fresh->check_out)->toBeNull()
            ->and($fresh->check_in->format('H:i'))->toBe('10:00')
            ->and((float) $fresh->total_hours)->toBe(0.0);
    });

    it('rejects a check out that is not after the check in', function () use ($payload) {
        $response = $this->putJson(
            "/api/v1/manager/attendances/{$this->attendance->id}",
            $payload(['check_in' => '2030-03-15T10:00:00', 'check_out' => '2030-03-15T10:00:00']),
            maecAuth($this->manager)
        );

        $response->assertUnprocessable()->assertJsonValidationErrors(['check_out']);
        expect($this->attendance->fresh()->check_in->format('H:i'))->toBe('09:00');
    });

    it('requires a reason of at least ten characters', function () use ($payload) {
        $response = $this->putJson(
            "/api/v1/manager/attendances/{$this->attendance->id}",
            $payload(['reason' => 'short']),
            maecAuth($this->manager)
        );

        $response->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    });

    it('returns a generic 500, keeps the data and logs nothing when saving fails', function () use ($payload) {
        Log::spy();
        $this->partialMock(AttendanceRepository::class, function ($mock) {
            $mock->shouldReceive('update')->once()->andThrow(new RuntimeException('write timeout'));
        });

        $response = $this->putJson(
            "/api/v1/manager/attendances/{$this->attendance->id}",
            $payload(),
            maecAuth($this->manager)
        );

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Failed to update attendance']);
        expect($this->attendance->fresh()->check_in->format('H:i'))->toBe('09:00');
        $this->assertDatabaseMissing('activity_logs', ['action' => ActivityType::ATTENDANCE_EDITED->value]);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Update attendance failed: write timeout'))
            ->once();
    });
});

describe('destroy', function () {
    it('returns 404 when the attendance does not exist', function () {
        $response = $this->deleteJson('/api/v1/manager/attendances/999999', [
            'reason' => 'Data duplikat dihapus',
        ], maecAuth($this->manager));

        $response->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Attendance not found']);
    });

    it('returns 403, deletes nothing and logs nothing for another teams attendance', function () {
        $task = Task::factory()->create(['attendance_id' => $this->otherAttendance->id]);

        $response = $this->deleteJson("/api/v1/manager/attendances/{$this->otherAttendance->id}", [
            'reason' => 'Mencoba menghapus tim lain',
        ], maecAuth($this->manager));

        $response->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Unauthorized to delete this attendance']);
        $this->assertDatabaseHas('attendances', ['id' => $this->otherAttendance->id]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
        $this->assertDatabaseMissing('activity_logs', ['action' => ActivityType::ATTENDANCE_DELETED->value]);
    });

    it('checks team ownership before validating the reason', function () {
        $response = $this->deleteJson("/api/v1/manager/attendances/{$this->otherAttendance->id}", [
            'reason' => 'short',
        ], maecAuth($this->manager));

        $response->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Unauthorized to delete this attendance']);
        $this->assertDatabaseHas('attendances', ['id' => $this->otherAttendance->id]);
    });

    it('returns 403 instead of a 500 when the attendance owner cannot be resolved', function () {
        $orphan = new Attendance(['user_id' => 999999]);
        $orphan->id = 424242;
        $this->partialMock(AttendanceRepository::class, function ($mock) use ($orphan) {
            $mock->shouldReceive('findById')->with(424242)->once()->andReturn($orphan);
            $mock->shouldNotReceive('delete');
        });

        $response = $this->deleteJson('/api/v1/manager/attendances/424242', [
            'reason' => 'Data tanpa pemilik dihapus',
        ], maecAuth($this->manager));

        $response->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Unauthorized to delete this attendance']);
        $this->assertDatabaseMissing('activity_logs', ['action' => ActivityType::ATTENDANCE_DELETED->value]);
    });

    it('requires a reason of at least ten characters', function () {
        $response = $this->deleteJson("/api/v1/manager/attendances/{$this->attendance->id}", [
            'reason' => 'too short',
        ], maecAuth($this->manager));

        $response->assertUnprocessable()
            ->assertExactJson(['success' => false, 'message' => 'Reason is required (minimum 10 characters)']);
        $this->assertDatabaseHas('attendances', ['id' => $this->attendance->id]);
    });

    it('accepts a reason of exactly ten characters', function () {
        $response = $this->deleteJson("/api/v1/manager/attendances/{$this->attendance->id}", [
            'reason' => '0123456789',
        ], maecAuth($this->manager));

        $response->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Attendance deleted successfully']);
        $this->assertDatabaseMissing('attendances', ['id' => $this->attendance->id]);
    });

    it('deletes the attendance together with its tasks and logs the reason', function () {
        $task = Task::factory()->create(['attendance_id' => $this->attendance->id]);

        $this->deleteJson("/api/v1/manager/attendances/{$this->attendance->id}", [
            'reason' => 'Absensi ganda, dihapus',
        ], maecAuth($this->manager))->assertOk();

        $this->assertDatabaseMissing('attendances', ['id' => $this->attendance->id]);
        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
        $log = ActivityLog::where('action', ActivityType::ATTENDANCE_DELETED->value)
            ->where('description', 'like', "Deleted attendance #{$this->attendance->id}%")
            ->first();
        expect($log)->not->toBeNull()
            ->and($log->user_id)->toBe($this->manager->id)
            ->and($log->description)->toContain('Reason: Absensi ganda, dihapus');
    });

    it('forbids employees and unauthenticated users', function () {
        $this->deleteJson("/api/v1/manager/attendances/{$this->attendance->id}", [
            'reason' => 'Data duplikat dihapus',
        ])->assertUnauthorized();
        $this->deleteJson("/api/v1/manager/attendances/{$this->attendance->id}", [
            'reason' => 'Data duplikat dihapus',
        ], maecAuth($this->employee))->assertForbidden();

        $this->assertDatabaseHas('attendances', ['id' => $this->attendance->id]);
    });

    it('returns a generic 500 and keeps the attendance when deletion fails', function () {
        Log::spy();
        $this->partialMock(AttendanceRepository::class, function ($mock) {
            $mock->shouldReceive('delete')->once()->andThrow(new RuntimeException('fk violation'));
        });

        $response = $this->deleteJson("/api/v1/manager/attendances/{$this->attendance->id}", [
            'reason' => 'Data duplikat dihapus',
        ], maecAuth($this->manager));

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Failed to delete attendance']);
        $this->assertDatabaseHas('attendances', ['id' => $this->attendance->id]);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Delete attendance failed: fk violation'))
            ->once();
    });
});
