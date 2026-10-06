<?php

use App\Enums\OvertimeStatus;
use App\Models\Attendance;
use App\Models\Overtime;
use App\Models\User;
use App\Services\OvertimeService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

/*
 * Manager side of overtime approval under /manager/overtimes.
 * "Now" is frozen at Wednesday 20 March 2030 18:00.
 */

/**
 * Bearer header for a user. Guards are reset so several actors can be used in one test.
 */
function motAuth(User $user): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer '.$user->createToken('auth-token')->plainTextToken];
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2030-03-20 18:00:00'));
    $this->team = $this->createTeam(['required_work_hours' => 7.0]);
    $this->manager = $this->createManager();
    $this->alice = $this->createEmployee(['name' => 'Alice']);
    $this->bob = $this->createEmployee(['name' => 'Bob']);

    // Another team, which must stay invisible and untouchable.
    $otherTeam = $this->createTeam();
    $this->outsider = User::factory()->create(['team_id' => $otherTeam->id, 'role' => 'employee', 'name' => 'Zed Outsider']);
    $this->outsiderOvertime = Overtime::factory()->create(['user_id' => $this->outsider->id]);
});

describe('access control', function () {
    it('requires authentication and the manager role', function (string $method, string $url) {
        $this->json($method, $url)->assertUnauthorized();
        $this->json($method, $url, [], motAuth($this->alice))->assertForbidden();
    })->with([
        'list' => ['GET', '/api/v1/manager/overtimes'],
        'pending count' => ['GET', '/api/v1/manager/overtimes/pending-count'],
        'assign' => ['POST', '/api/v1/manager/overtimes'],
        'approve' => ['PUT', '/api/v1/manager/overtimes/1/approve'],
        'reject' => ['PUT', '/api/v1/manager/overtimes/1/reject'],
    ]);
});

describe('GET /manager/overtimes', function () {
    it('lists the team requests newest first with employee, approver and used hours', function () {
        $older = $this->createOvertime(['user_id' => $this->alice->id, 'date' => '2030-03-18', 'created_at' => '2030-03-18 18:00:00']);
        $newer = $this->createOvertime([
            'user_id' => $this->bob->id,
            'status' => OvertimeStatus::APPROVED,
            'approved_by' => $this->manager->id,
            'approved_at' => '2030-03-20 17:00:00',
        ]);
        Attendance::create([
            'user_id' => $this->bob->id,
            'check_in' => '2030-03-20 17:00:00',
            'check_out' => '2030-03-20 18:00:00',
            'date' => '2030-03-20',
            'total_hours' => 1.0,
            'overtime_id' => $newer->id,
        ]);

        $response = $this->getJson('/api/v1/manager/overtimes', motAuth($this->manager));

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.user.name', 'Bob')
            ->assertJsonPath('data.0.approver.id', $this->manager->id)
            ->assertJsonPath('data.0.used_hours', 1)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('data.1.user.name', 'Alice');

        expect(collect($response->json('data'))->pluck('id'))->not->toContain($this->outsiderOvertime->id);
    });

    it('filters by status', function () {
        $pending = $this->createOvertime(['user_id' => $this->alice->id]);
        $this->createOvertime(['user_id' => $this->alice->id, 'status' => OvertimeStatus::APPROVED, 'date' => '2030-03-19']);
        $this->createOvertime(['user_id' => $this->bob->id, 'status' => OvertimeStatus::REJECTED, 'date' => '2030-03-18']);

        $this->getJson('/api/v1/manager/overtimes?status=pending', motAuth($this->manager))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id);

        $this->getJson('/api/v1/manager/overtimes?status=rejected', motAuth($this->manager))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'rejected');

        $this->getJson('/api/v1/manager/overtimes', motAuth($this->manager))
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('rejects an unknown status filter', function () {
        $this->getJson('/api/v1/manager/overtimes?status=archived', motAuth($this->manager))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    });

    it('answers 500 with a generic message when listing fails', function () {
        Log::spy();
        $this->createOvertime(['user_id' => $this->alice->id]);
        Overtime::retrieved(function () {
            throw new RuntimeException('db down');
        });

        $this->getJson('/api/v1/manager/overtimes', motAuth($this->manager))
            ->assertStatus(500)
            ->assertJsonPath('message', 'Gagal mengambil data lembur.');
    });
});

describe('GET /manager/overtimes/pending-count', function () {
    it('counts only pending requests of the manager team', function () {
        $this->createOvertime(['user_id' => $this->alice->id]);
        $this->createOvertime(['user_id' => $this->bob->id]);
        $this->createOvertime(['user_id' => $this->bob->id, 'status' => OvertimeStatus::APPROVED, 'date' => '2030-03-19']);
        $this->createOvertime(['user_id' => $this->alice->id, 'status' => OvertimeStatus::REJECTED, 'date' => '2030-03-18']);

        $this->getJson('/api/v1/manager/overtimes/pending-count', motAuth($this->manager))
            ->assertOk()
            ->assertJsonPath('data.count', 2);
    });

    it('answers 500 with a generic message when counting fails', function () {
        Log::spy();
        // count() hydrates no models, so fail the query itself through a global scope.
        Overtime::addGlobalScope('boom', function () {
            throw new RuntimeException('db down');
        });

        try {
            $this->getJson('/api/v1/manager/overtimes/pending-count', motAuth($this->manager))
                ->assertStatus(500)
                ->assertJsonPath('message', 'Gagal mengambil jumlah pengajuan lembur.');
        } finally {
            Overtime::clearBootedModels();
        }
    });
});

describe('PUT /manager/overtimes/{id}/approve', function () {
    it('approves a pending request and records the decision', function () {
        $overtime = $this->createOvertime(['user_id' => $this->alice->id, 'planned_minutes' => 120]);

        $this->putJson("/api/v1/manager/overtimes/{$overtime->id}/approve", [
            'approved_minutes' => 60,
            'notes' => 'Cukup satu jam saja',
        ], motAuth($this->manager))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Pengajuan lembur disetujui.')
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.planned_minutes', 120)
            ->assertJsonPath('data.approved_minutes', 60)
            ->assertJsonPath('data.allowed_minutes', 60)
            ->assertJsonPath('data.notes', 'Cukup satu jam saja')
            ->assertJsonPath('data.approver.id', $this->manager->id);

        $fresh = $overtime->fresh();
        expect($fresh->status)->toBe(OvertimeStatus::APPROVED)
            ->and($fresh->approved_by)->toBe($this->manager->id)
            ->and($fresh->approved_at->toDateTimeString())->toBe('2030-03-20 18:00:00');

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->manager->id,
            'action' => 'overtime_approved',
            'description' => 'Manager approved overtime request for Alice on 2030-03-20',
        ]);
    });

    it('keeps the requested duration when no override is given', function () {
        $overtime = $this->createOvertime(['user_id' => $this->alice->id, 'planned_minutes' => 90]);

        $this->putJson("/api/v1/manager/overtimes/{$overtime->id}/approve", [], motAuth($this->manager))
            ->assertOk()
            ->assertJsonPath('data.approved_minutes', null)
            ->assertJsonPath('data.allowed_minutes', 90);
    });

    it('validates the approved duration and notes', function (array $payload, string $field) {
        $overtime = $this->createOvertime(['user_id' => $this->alice->id]);

        $this->putJson("/api/v1/manager/overtimes/{$overtime->id}/approve", $payload, motAuth($this->manager))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        expect($overtime->fresh()->isPending())->toBeTrue();
    })->with([
        'minutes below minimum' => [['approved_minutes' => 14], 'approved_minutes'],
        'minutes above maximum' => [['approved_minutes' => 481], 'approved_minutes'],
        'minutes not a number' => [['approved_minutes' => 'lama'], 'approved_minutes'],
        'notes too long' => [['notes' => str_repeat('a', 501)], 'notes'],
    ]);

    it('cannot decide a request twice', function () {
        $overtime = $this->createOvertime(['user_id' => $this->alice->id]);

        $this->putJson("/api/v1/manager/overtimes/{$overtime->id}/approve", [], motAuth($this->manager))->assertOk();

        $this->putJson("/api/v1/manager/overtimes/{$overtime->id}/approve", ['approved_minutes' => 480], motAuth($this->manager))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Pengajuan lembur ini sudah diputuskan.');

        $this->putJson("/api/v1/manager/overtimes/{$overtime->id}/reject", ['notes' => 'Berubah pikiran'], motAuth($this->manager))
            ->assertUnprocessable();

        expect($overtime->fresh()->status)->toBe(OvertimeStatus::APPROVED)
            ->and($overtime->fresh()->approved_minutes)->toBeNull();
    });

    it('refuses a request from another team and reports unknown ids', function () {
        $this->putJson("/api/v1/manager/overtimes/{$this->outsiderOvertime->id}/approve", [], motAuth($this->manager))
            ->assertForbidden()
            ->assertJsonPath('message', 'Tidak berwenang memproses pengajuan lembur ini.');

        expect($this->outsiderOvertime->fresh()->isPending())->toBeTrue();

        $this->putJson('/api/v1/manager/overtimes/999999/approve', [], motAuth($this->manager))
            ->assertNotFound()
            ->assertJsonPath('message', 'Pengajuan lembur tidak ditemukan.');
    });

    it('answers 500 with a generic message on an unexpected failure', function () {
        Log::spy();
        $overtime = $this->createOvertime(['user_id' => $this->alice->id]);
        $this->mock(OvertimeService::class, fn ($mock) => $mock->shouldReceive('approve')->andThrow(new RuntimeException('db down')));

        $this->putJson("/api/v1/manager/overtimes/{$overtime->id}/approve", [], motAuth($this->manager))
            ->assertStatus(500)
            ->assertJsonPath('message', 'Gagal memproses pengajuan lembur.');
    });
});

describe('PUT /manager/overtimes/{id}/reject', function () {
    it('rejects a pending request with a mandatory note', function () {
        $overtime = $this->createOvertime(['user_id' => $this->alice->id]);

        $this->putJson("/api/v1/manager/overtimes/{$overtime->id}/reject", [], motAuth($this->manager))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('notes');
        expect($overtime->fresh()->isPending())->toBeTrue();

        $this->putJson("/api/v1/manager/overtimes/{$overtime->id}/reject", ['notes' => 'Tidak ada tenggat mendesak'], motAuth($this->manager))
            ->assertOk()
            ->assertJsonPath('message', 'Pengajuan lembur ditolak.')
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.allowed_minutes', 0)
            ->assertJsonPath('data.notes', 'Tidak ada tenggat mendesak');

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->manager->id,
            'action' => 'overtime_rejected',
            'description' => 'Manager rejected overtime request for Alice on 2030-03-20',
        ]);
    });

    it('refuses a request from another team', function () {
        $this->putJson("/api/v1/manager/overtimes/{$this->outsiderOvertime->id}/reject", ['notes' => 'Bukan tim saya'], motAuth($this->manager))
            ->assertForbidden();

        expect($this->outsiderOvertime->fresh()->isPending())->toBeTrue();
    });
});

describe('POST /manager/overtimes (assign)', function () {
    it('grants an auto-approved overtime to a team employee', function () {
        $this->postJson('/api/v1/manager/overtimes', [
            'user_id' => $this->alice->id,
            'reason' => 'Migrasi data malam ini',
            'planned_minutes' => 120,
        ], motAuth($this->manager))
            ->assertCreated()
            ->assertJsonPath('message', 'Lembur berhasil ditugaskan.')
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.source', 'manager')
            ->assertJsonPath('data.date', '2030-03-20')
            ->assertJsonPath('data.allowed_minutes', 120)
            ->assertJsonPath('data.user.name', 'Alice')
            ->assertJsonPath('data.approver.id', $this->manager->id);

        $overtime = Overtime::where('user_id', $this->alice->id)->first();
        expect($overtime->approved_by)->toBe($this->manager->id)
            ->and($overtime->approved_at->toDateTimeString())->toBe('2030-03-20 18:00:00');

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->manager->id,
            'action' => 'overtime_assigned',
            'description' => 'Manager assigned overtime to Alice for 2030-03-20 (120 minutes)',
        ]);
    });

    it('validates the payload and the employee', function (array $overrides, string $field) {
        $payload = array_merge(['user_id' => $this->alice->id, 'reason' => 'Migrasi data', 'planned_minutes' => 60], $overrides);

        $this->postJson('/api/v1/manager/overtimes', $payload, motAuth($this->manager))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'missing employee' => [['user_id' => null], 'user_id'],
        'unknown employee' => [['user_id' => 999999], 'user_id'],
        'reason too short' => [['reason' => 'abc'], 'reason'],
        'minutes too low' => [['planned_minutes' => 5], 'planned_minutes'],
        'minutes too high' => [['planned_minutes' => 600], 'planned_minutes'],
    ]);

    it('only accepts employees of the manager own team', function () {
        $this->postJson('/api/v1/manager/overtimes', [
            'user_id' => $this->outsider->id,
            'reason' => 'Lintas tim',
            'planned_minutes' => 60,
        ], motAuth($this->manager))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $otherManager = $this->createManager();
        $this->postJson('/api/v1/manager/overtimes', [
            'user_id' => $otherManager->id,
            'reason' => 'Manager bukan karyawan',
            'planned_minutes' => 60,
        ], motAuth($this->manager))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $this->assertDatabaseMissing('overtimes', ['reason' => 'Lintas tim']);
    });

    it('asks the manager to decide a pending request first', function () {
        $this->createOvertime(['user_id' => $this->alice->id]);

        $this->postJson('/api/v1/manager/overtimes', [
            'user_id' => $this->alice->id,
            'reason' => 'Tambahan penugasan',
            'planned_minutes' => 60,
        ], motAuth($this->manager))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Masih ada pengajuan lembur karyawan ini yang menunggu keputusan. Setujui atau tolak terlebih dahulu.');

        $this->assertDatabaseCount('overtimes', 2); // Alice's pending one + the outsider's
    });

    it('can extend an approved overtime or override a rejection', function (OvertimeStatus $latest) {
        $this->createOvertime(['user_id' => $this->alice->id, 'status' => $latest]);

        $this->postJson('/api/v1/manager/overtimes', [
            'user_id' => $this->alice->id,
            'reason' => 'Perpanjangan lembur',
            'planned_minutes' => 45,
        ], motAuth($this->manager))
            ->assertCreated();

        $latestRecord = app(OvertimeService::class)->latestForDay($this->alice, Carbon::today());
        expect($latestRecord->status)->toBe(OvertimeStatus::APPROVED)
            ->and($latestRecord->planned_minutes)->toBe(45);
    })->with([
        'approved' => [OvertimeStatus::APPROVED],
        'rejected' => [OvertimeStatus::REJECTED],
    ]);

    it('refuses a disabled employee', function () {
        $disabled = $this->createDisabledEmployee();

        $this->postJson('/api/v1/manager/overtimes', [
            'user_id' => $disabled->id,
            'reason' => 'Akun nonaktif',
            'planned_minutes' => 60,
        ], motAuth($this->manager))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Akun karyawan telah dinonaktifkan.');
    });

    it('answers 500 with a generic message on an unexpected failure', function () {
        Log::spy();
        $this->mock(OvertimeService::class, fn ($mock) => $mock->shouldReceive('assign')->andThrow(new RuntimeException('db down')));

        $this->postJson('/api/v1/manager/overtimes', [
            'user_id' => $this->alice->id,
            'reason' => 'Migrasi data',
            'planned_minutes' => 60,
        ], motAuth($this->manager))
            ->assertStatus(500)
            ->assertJsonPath('message', 'Gagal menugaskan lembur.');
    });
});
