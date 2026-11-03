<?php

use App\Enums\LeaveStatus;
use App\Models\Leave;
use App\Models\LeaveQuota;
use App\Models\User;
use App\Services\LeaveService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

/**
 * Bearer header for a user. Guards are reset so several actors can be used in one test.
 */
function lqecAuth(User $user): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer '.$user->createToken('auth-token')->plainTextToken];
}

beforeEach(function () {
    $this->manager = $this->createManager(['name' => 'Manager Mia']);
    $this->alice = $this->createEmployee(['name' => 'Alice', 'leave_quota_days' => 12]);
    $this->bob = $this->createEmployee(['name' => 'Bob', 'leave_quota_days' => 10]);

    $this->otherEmployee = User::factory()->create(['role' => 'employee', 'name' => 'Zed Outsider']);
});

afterEach(function () {
    LeaveQuota::flushEventListeners();
});

describe('index', function () {
    it('requires authentication and the manager role', function () {
        $this->getJson('/api/v1/manager/leave-quotas')->assertUnauthorized();
        $this->getJson('/api/v1/manager/leave-quotas', lqecAuth($this->alice))->assertForbidden();
    });

    it('lists active team members alphabetically and excludes other teams', function () {
        $response = $this->getJson('/api/v1/manager/leave-quotas', lqecAuth($this->manager));

        $response->assertOk()->assertJson(['success' => true]);
        expect(collect($response->json('data'))->pluck('name')->all())
            ->toBe(['Alice', 'Bob', 'Manager Mia']);
        expect(collect($response->json('data'))->firstWhere('name', 'Alice'))->toMatchArray([
            'user_id' => $this->alice->id,
            'email' => $this->alice->email,
            'role' => 'employee',
            'year' => Carbon::now()->year,
            'quota_days' => 12,
            'is_custom' => false,
        ]);
    });

    it('uses the requested year, custom quotas and that years leave usage', function () {
        LeaveQuota::create(['user_id' => $this->alice->id, 'year' => 2030, 'quota_days' => 20]);
        // Different year: must not leak into 2030.
        LeaveQuota::create(['user_id' => $this->alice->id, 'year' => 2031, 'quota_days' => 3]);
        Leave::factory()->create([
            'user_id' => $this->alice->id,
            'start_date' => '2030-04-01',
            'end_date' => '2030-04-03',
            'status' => LeaveStatus::APPROVED,
        ]);
        Leave::factory()->create([
            'user_id' => $this->alice->id,
            'start_date' => '2030-05-06',
            'end_date' => '2030-05-07',
            'status' => LeaveStatus::PENDING,
        ]);
        Leave::factory()->create([
            'user_id' => $this->alice->id,
            'start_date' => '2030-06-03',
            'end_date' => '2030-06-04',
            'status' => LeaveStatus::REJECTED,
        ]);

        $response = $this->getJson('/api/v1/manager/leave-quotas?year=2030', lqecAuth($this->manager));

        $alice = collect($response->json('data'))->firstWhere('name', 'Alice');
        expect($alice)->toMatchArray([
            'year' => 2030,
            'quota_days' => 20,
            'is_custom' => true,
            'used_days' => 3,
            'pending_days' => 2,
            'remaining_days' => 15,
        ]);

        $bob = collect($response->json('data'))->firstWhere('name', 'Bob');
        expect($bob)->toMatchArray([
            'quota_days' => 10,
            'is_custom' => false,
            'used_days' => 0,
            'remaining_days' => 10,
        ]);
    });

    it('excludes disabled users', function () {
        $this->createDisabledEmployee(['name' => 'Dormant Dan']);

        $names = collect(
            $this->getJson('/api/v1/manager/leave-quotas', lqecAuth($this->manager))->json('data')
        )->pluck('name')->all();

        expect($names)->not->toContain('Dormant Dan');
    });

    it('returns a generic 500 and logs when the leave summary cannot be built', function () {
        Log::spy();
        $this->mock(LeaveService::class, function ($mock) {
            $mock->shouldReceive('getLeaveSummary')->andThrow(new RuntimeException('summary broke'));
        });

        $response = $this->getJson('/api/v1/manager/leave-quotas', lqecAuth($this->manager));

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Gagal mengambil data jatah cuti.']);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Get leave quotas failed: summary broke'))
            ->once();
    });
});

describe('update', function () {
    it('creates a quota and responds with the new values', function () {
        $response = $this->putJson("/api/v1/manager/leave-quotas/{$this->alice->id}", [
            'year' => 2030,
            'quota_days' => 18,
        ], lqecAuth($this->manager));

        $response->assertOk()
            ->assertJsonPath('data.user_id', $this->alice->id)
            ->assertJsonPath('data.name', 'Alice')
            ->assertJsonPath('data.year', 2030)
            ->assertJsonPath('data.quota_days', 18)
            ->assertJsonPath('message', 'Jatah cuti Alice untuk tahun 2030 berhasil diperbarui.');
        $this->assertDatabaseHas('leave_quotas', ['user_id' => $this->alice->id, 'year' => 2030, 'quota_days' => 18]);
    });

    it('returns 404 for a user that does not exist', function () {
        $response = $this->putJson('/api/v1/manager/leave-quotas/999999', [
            'year' => 2030,
            'quota_days' => 18,
        ], lqecAuth($this->manager));

        $response->assertNotFound()->assertExactJson([
            'success' => false,
            'message' => 'Pengguna tidak ditemukan atau bukan anggota tim Anda.',
        ]);
        expect(LeaveQuota::count())->toBe(0);
    });

    it('returns 404 and writes nothing for a user in another team', function () {
        $response = $this->putJson("/api/v1/manager/leave-quotas/{$this->otherEmployee->id}", [
            'year' => 2030,
            'quota_days' => 18,
        ], lqecAuth($this->manager));

        $response->assertNotFound();
        $this->assertDatabaseMissing('leave_quotas', ['user_id' => $this->otherEmployee->id]);
    });

    it('validates year and quota_days', function (array $payload, array $errorKeys) {
        $response = $this->putJson("/api/v1/manager/leave-quotas/{$this->alice->id}", $payload, lqecAuth($this->manager));

        $response->assertUnprocessable()->assertJsonValidationErrors($errorKeys);
        expect(LeaveQuota::count())->toBe(0);
    })->with([
        'nothing supplied' => [[], ['year', 'quota_days']],
        'year too old' => [['year' => 2019, 'quota_days' => 5], ['year']],
        'year too far' => [['year' => 2100, 'quota_days' => 5], ['year']],
        'year not a number' => [['year' => 'twenty', 'quota_days' => 5], ['year']],
        'quota above maximum' => [['year' => 2030, 'quota_days' => 366], ['quota_days']],
        'quota fractional' => [['year' => 2030, 'quota_days' => 2.5], ['quota_days']],
        'quota not a number' => [['year' => 2030, 'quota_days' => 'many'], ['quota_days']],
    ]);

    it('accepts the boundary values of both fields', function (int $year, int $days) {
        $this->putJson("/api/v1/manager/leave-quotas/{$this->alice->id}", [
            'year' => $year,
            'quota_days' => $days,
        ], lqecAuth($this->manager))->assertOk();

        $this->assertDatabaseHas('leave_quotas', ['user_id' => $this->alice->id, 'year' => $year, 'quota_days' => $days]);
    })->with([
        'earliest year with zero days' => [2020, 0],
        'latest year with full year' => [2099, 365],
    ]);

    it('updates an existing quota instead of duplicating it', function () {
        LeaveQuota::create(['user_id' => $this->alice->id, 'year' => 2030, 'quota_days' => 5]);

        $this->putJson("/api/v1/manager/leave-quotas/{$this->alice->id}", [
            'year' => 2030,
            'quota_days' => 9,
        ], lqecAuth($this->manager))->assertOk();

        expect(LeaveQuota::where('user_id', $this->alice->id)->where('year', 2030)->count())->toBe(1)
            ->and(LeaveQuota::where('user_id', $this->alice->id)->value('quota_days'))->toBe(9);
    });

    it('returns a generic 500 and stores nothing when saving fails', function () {
        Log::spy();
        LeaveQuota::creating(function () {
            throw new RuntimeException('constraint failure');
        });

        $response = $this->putJson("/api/v1/manager/leave-quotas/{$this->alice->id}", [
            'year' => 2030,
            'quota_days' => 18,
        ], lqecAuth($this->manager));

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Gagal memperbarui jatah cuti.']);
        expect(LeaveQuota::count())->toBe(0);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Update leave quota failed: constraint failure'))
            ->once();
    });
});

describe('bulk update', function () {
    it('requires authentication and the manager role', function () {
        $payload = ['year' => 2030, 'quota_days' => 14];

        $this->postJson('/api/v1/manager/leave-quotas/bulk', $payload)->assertUnauthorized();
        $this->postJson('/api/v1/manager/leave-quotas/bulk', $payload, lqecAuth($this->alice))->assertForbidden();
        expect(LeaveQuota::count())->toBe(0);
    });

    it('validates the payload', function (array $payload, array $errorKeys) {
        $response = $this->postJson('/api/v1/manager/leave-quotas/bulk', $payload, lqecAuth($this->manager));

        $response->assertUnprocessable()->assertJsonValidationErrors($errorKeys);
        expect(LeaveQuota::count())->toBe(0);
    })->with([
        'nothing supplied' => [[], ['year', 'quota_days']],
        'negative quota' => [['year' => 2030, 'quota_days' => -1], ['quota_days']],
        'year out of range' => [['year' => 1999, 'quota_days' => 10], ['year']],
    ]);

    it('applies to active team members only and reports how many were affected', function () {
        $this->createDisabledEmployee(['name' => 'Dormant Dan']);

        $response = $this->postJson('/api/v1/manager/leave-quotas/bulk', [
            'year' => 2030,
            'quota_days' => 14,
        ], lqecAuth($this->manager));

        $response->assertOk()
            ->assertJsonPath('data.affected_users', 3)
            ->assertJsonPath('data.year', 2030)
            ->assertJsonPath('data.quota_days', 14)
            ->assertJsonPath('message', 'Jatah cuti 14 hari untuk tahun 2030 berhasil diterapkan ke 3 pengguna.');

        $quotaUserIds = LeaveQuota::where('year', 2030)->pluck('user_id')->sort()->values()->all();
        expect($quotaUserIds)->toBe(collect([$this->manager->id, $this->alice->id, $this->bob->id])->sort()->values()->all());
        expect(LeaveQuota::where('user_id', $this->otherEmployee->id)->exists())->toBeFalse();
    });

    it('overwrites existing quotas for the year and leaves other years alone', function () {
        LeaveQuota::create(['user_id' => $this->alice->id, 'year' => 2030, 'quota_days' => 2]);
        LeaveQuota::create(['user_id' => $this->alice->id, 'year' => 2031, 'quota_days' => 7]);

        $this->postJson('/api/v1/manager/leave-quotas/bulk', [
            'year' => 2030,
            'quota_days' => 21,
        ], lqecAuth($this->manager))->assertOk();

        expect(LeaveQuota::where('user_id', $this->alice->id)->where('year', 2030)->count())->toBe(1)
            ->and(LeaveQuota::where('user_id', $this->alice->id)->where('year', 2030)->value('quota_days'))->toBe(21)
            ->and(LeaveQuota::where('user_id', $this->alice->id)->where('year', 2031)->value('quota_days'))->toBe(7);
    });

    it('is atomic: a failure part way through rolls every quota back', function () {
        Log::spy();
        $calls = 0;
        LeaveQuota::creating(function () use (&$calls) {
            if (++$calls === 2) {
                throw new RuntimeException('second insert failed');
            }
        });

        $response = $this->postJson('/api/v1/manager/leave-quotas/bulk', [
            'year' => 2030,
            'quota_days' => 14,
        ], lqecAuth($this->manager));

        $response->assertStatus(500)->assertExactJson([
            'success' => false,
            'message' => 'Gagal memperbarui jatah cuti secara massal.',
        ]);
        expect($calls)->toBe(2)
            ->and(LeaveQuota::count())->toBe(0);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Bulk update leave quotas failed: second insert failed'))
            ->once();
    });
});
