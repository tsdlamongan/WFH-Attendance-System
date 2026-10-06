<?php

use App\Enums\OvertimeStatus;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Overtime;
use App\Models\User;
use App\Services\OvertimeService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

/*
 * Employee side of overtime approval: POST /overtimes and GET /overtimes/my-requests.
 * "Now" is frozen at Wednesday 20 March 2030 18:00.
 */

/**
 * Bearer header for a user. Guards are reset so several actors can be used in one test.
 */
function otrAuth(User $user): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer '.$user->createToken('auth-token')->plainTextToken];
}

function otrConnectWhatsapp($team): void
{
    $team->update([
        'whatsapp_api_secret' => 'test-api-secret',
        'whatsapp_account_unique_id' => 'test-unique-id',
        'whatsapp_connected' => true,
        'whatsapp_recipient_phone' => '628123456789',
    ]);
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(Carbon::parse('2030-03-20 18:00:00'));
    $this->team = $this->createTeam(['required_work_hours' => 7.0]);
    $this->manager = $this->createManager();
    $this->employee = $this->createEmployee(['name' => 'Alice']);
});

describe('POST /overtimes', function () {
    it('requires authentication and the employee role', function () {
        $this->postJson('/api/v1/overtimes', [])->assertUnauthorized();

        $this->postJson('/api/v1/overtimes', ['reason' => 'Deploy malam', 'planned_minutes' => 60], otrAuth($this->manager))
            ->assertForbidden();
    });

    it('creates a pending overtime request for today', function () {
        $response = $this->postJson('/api/v1/overtimes', [
            'reason' => 'Menyelesaikan rilis',
            'planned_minutes' => 90,
        ], otrAuth($this->employee));

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.date', '2030-03-20')
            ->assertJsonPath('data.planned_minutes', 90)
            ->assertJsonPath('data.approved_minutes', null)
            ->assertJsonPath('data.allowed_minutes', 90)
            ->assertJsonPath('data.source', 'employee')
            ->assertJsonPath('data.user.name', 'Alice');

        $overtime = Overtime::first();
        expect($overtime->user_id)->toBe($this->employee->id)
            ->and($overtime->status)->toBe(OvertimeStatus::PENDING)
            ->and($overtime->reason)->toBe('Menyelesaikan rilis')
            ->and($overtime->approved_by)->toBeNull();
    });

    it('logs the request exactly once with a descriptive message', function () {
        $this->postJson('/api/v1/overtimes', [
            'reason' => 'Menyelesaikan rilis',
            'planned_minutes' => 90,
        ], otrAuth($this->employee))->assertCreated();

        expect(ActivityLog::where('action', 'overtime_requested')->count())->toBe(1)
            ->and(ActivityLog::where('action', 'task_updated')->count())->toBe(0);

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->employee->id,
            'action' => 'overtime_requested',
            'description' => 'User requested overtime for 2030-03-20 (90 minutes)',
        ]);
    });

    it('validates the payload', function (array $payload, string $field) {
        $this->postJson('/api/v1/overtimes', $payload, otrAuth($this->employee))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('overtimes', 0);
    })->with([
        'missing reason' => [['planned_minutes' => 60], 'reason'],
        'reason too short' => [['reason' => 'abc', 'planned_minutes' => 60], 'reason'],
        'reason too long' => [['reason' => str_repeat('a', 501), 'planned_minutes' => 60], 'reason'],
        'missing minutes' => [['reason' => 'Deploy malam'], 'planned_minutes'],
        'minutes below minimum' => [['reason' => 'Deploy malam', 'planned_minutes' => 14], 'planned_minutes'],
        'minutes above maximum' => [['reason' => 'Deploy malam', 'planned_minutes' => 481], 'planned_minutes'],
        'minutes not a number' => [['reason' => 'Deploy malam', 'planned_minutes' => 'lama'], 'planned_minutes'],
    ]);

    it('refuses a second request while one is pending or approved for the day', function (OvertimeStatus $existing) {
        $this->createOvertime(['user_id' => $this->employee->id, 'status' => $existing]);

        $this->postJson('/api/v1/overtimes', ['reason' => 'Tambahan lagi', 'planned_minutes' => 30], otrAuth($this->employee))
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Anda sudah memiliki pengajuan lembur untuk hari ini.');

        $this->assertDatabaseCount('overtimes', 1);
    })->with([
        'pending' => [OvertimeStatus::PENDING],
        'approved' => [OvertimeStatus::APPROVED],
    ]);

    it('allows a new request after the earlier one was rejected', function () {
        $this->createOvertime(['user_id' => $this->employee->id, 'status' => OvertimeStatus::REJECTED]);

        $this->postJson('/api/v1/overtimes', ['reason' => 'Coba ajukan lagi', 'planned_minutes' => 30], otrAuth($this->employee))
            ->assertCreated();

        $this->assertDatabaseCount('overtimes', 2);
    });

    it('does not count a request from another day or another employee', function () {
        $this->createOvertime(['user_id' => $this->employee->id, 'date' => '2030-03-19']);
        $this->createOvertime(['user_id' => $this->createEmployee()->id]);

        $this->postJson('/api/v1/overtimes', ['reason' => 'Lembur hari ini', 'planned_minutes' => 30], otrAuth($this->employee))
            ->assertCreated();
    });

    it('refuses a disabled account at the service level', function () {
        $disabled = $this->createDisabledEmployee();

        expect(fn () => app(OvertimeService::class)->request($disabled, ['reason' => 'Deploy malam', 'planned_minutes' => 60]))
            ->toThrow(DomainException::class, 'Akun anda telah dinonaktifkan.');

        $this->assertDatabaseCount('overtimes', 0);
    });

    it('answers 500 with a generic message on an unexpected failure', function () {
        Log::spy();
        $this->mock(OvertimeService::class, fn ($mock) => $mock->shouldReceive('request')->andThrow(new RuntimeException('db down')));

        $this->postJson('/api/v1/overtimes', ['reason' => 'Deploy malam', 'planned_minutes' => 60], otrAuth($this->employee))
            ->assertStatus(500)
            ->assertJsonPath('message', 'Gagal mengirim pengajuan lembur.');
    });
});

describe('WhatsApp notification to the manager', function () {
    it('sends a heads-up for a new request', function () {
        otrConnectWhatsapp($this->team);
        Http::fake(['whatsapp.perekonomian.id/api/send/whatsapp' => Http::response(['status' => 200], 200)]);

        $this->postJson('/api/v1/overtimes', ['reason' => 'Menyelesaikan rilis', 'planned_minutes' => 90], otrAuth($this->employee))
            ->assertCreated();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->body(), 'Pengajuan Lembur')
            && str_contains($request->body(), 'Alice mengajukan lembur 90 menit')
            && str_contains($request->body(), 'Menyelesaikan rilis'));

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->employee->id,
            'action' => 'whatsapp_overtime_sent',
        ]);
    });

    it('stays silent when the team has no connected WhatsApp or recipient', function () {
        Http::fake();

        $this->postJson('/api/v1/overtimes', ['reason' => 'Tanpa WhatsApp', 'planned_minutes' => 30], otrAuth($this->employee))
            ->assertCreated();

        otrConnectWhatsapp($this->team);
        $this->team->update(['whatsapp_recipient_phone' => null]);
        $this->createOvertime(['user_id' => $this->employee->id, 'status' => OvertimeStatus::REJECTED]);
        $this->postJson('/api/v1/overtimes', ['reason' => 'Tanpa penerima', 'planned_minutes' => 30], otrAuth($this->employee))
            ->assertCreated();

        Http::assertNothingSent();
    });

    it('never blocks the request when the gateway fails', function () {
        otrConnectWhatsapp($this->team);
        Http::fake(['whatsapp.perekonomian.id/api/send/whatsapp' => Http::response(['status' => 500], 500)]);
        Log::spy();

        $this->postJson('/api/v1/overtimes', ['reason' => 'Gateway mati', 'planned_minutes' => 30], otrAuth($this->employee))
            ->assertCreated();

        $this->assertDatabaseCount('overtimes', 1);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'whatsapp_overtime_sent']);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_starts_with($message, 'Overtime WhatsApp notification failed'))
            ->once();
    });
});

describe('GET /overtimes/my-requests', function () {
    it('requires authentication and the employee role', function () {
        $this->getJson('/api/v1/overtimes/my-requests')->assertUnauthorized();
        $this->getJson('/api/v1/overtimes/my-requests', otrAuth($this->manager))->assertForbidden();
    });

    it('lists only the caller own requests, newest first, with decision details and used hours', function () {
        $older = $this->createOvertime(['user_id' => $this->employee->id, 'date' => '2030-03-18', 'created_at' => '2030-03-18 18:00:00']);
        $newer = $this->createOvertime([
            'user_id' => $this->employee->id,
            'status' => OvertimeStatus::APPROVED,
            'approved_by' => $this->manager->id,
            'approved_at' => '2030-03-20 17:00:00',
            'approved_minutes' => 45,
            'notes' => 'Oke, jangan terlalu malam',
        ]);
        Attendance::create([
            'user_id' => $this->employee->id,
            'check_in' => '2030-03-20 17:00:00',
            'check_out' => '2030-03-20 17:30:00',
            'date' => '2030-03-20',
            'total_hours' => 0.5,
            'overtime_id' => $newer->id,
        ]);
        $this->createOvertime(); // someone else's

        $response = $this->getJson('/api/v1/overtimes/my-requests', otrAuth($this->employee));

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.status', 'approved')
            ->assertJsonPath('data.0.approved_minutes', 45)
            ->assertJsonPath('data.0.allowed_minutes', 45)
            ->assertJsonPath('data.0.notes', 'Oke, jangan terlalu malam')
            ->assertJsonPath('data.0.approver.id', $this->manager->id)
            ->assertJsonPath('data.0.used_hours', 0.5)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('data.1.used_hours', 0);
    });

    it('answers 500 with a generic message when listing fails', function () {
        Log::spy();
        $this->createOvertime(['user_id' => $this->employee->id]);
        Overtime::retrieved(function () {
            throw new RuntimeException('db down');
        });

        $this->getJson('/api/v1/overtimes/my-requests', otrAuth($this->employee))
            ->assertStatus(500)
            ->assertJsonPath('message', 'Gagal mengambil data lembur.');
    });
});
