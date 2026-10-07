<?php

use App\Enums\ActivityType;
use App\Models\Attendance;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\RepeatedTaskAlertService;
use App\Services\WhatsAppGatewayService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

/*
 * Standby detection on task titles and the "same unfinished task N check-ins in a row" alert.
 *
 * "Now" is frozen at Wednesday 11 March 2026 14:00 (Asia/Jakarta); earlier sessions are
 * created directly in the database, the session under test goes through the real check-in.
 */

const RTA_GATEWAY = 'whatsapp.perekonomian.id/api/send/whatsapp';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(Carbon::parse('2026-03-11 14:00:00'));
    $this->team = $this->createTeam(['required_work_hours' => 7.0]);
    $this->employee = $this->createEmployee(['name' => 'Alice']);
});

function rtaConnectWhatsApp(Team $team): void
{
    $team->update([
        'whatsapp_api_secret' => 'test-api-secret',
        'whatsapp_account_unique_id' => 'test-unique-id',
        'whatsapp_connected' => true,
        'whatsapp_recipient_phone' => '628123456789',
    ]);
}

/**
 * A closed 09:00-17:00 session on $day. $tasks maps a title to `true` (completed), `false`
 * (open, no blocker) or `[false, 'blocker text']`.
 */
function rtaSession(User $user, string $day, array $tasks): Attendance
{
    $attendance = Attendance::create([
        'user_id' => $user->id,
        'check_in' => Carbon::parse("{$day} 09:00:00"),
        'check_out' => Carbon::parse("{$day} 17:00:00"),
        'date' => $day,
        'total_hours' => 8,
    ]);

    foreach ($tasks as $title => $state) {
        [$completed, $blocker] = is_array($state) ? $state : [$state, null];
        Task::create([
            'attendance_id' => $attendance->id,
            'title' => $title,
            'is_completed' => $completed,
            'blocker_reason' => $blocker,
        ]);
    }

    return $attendance;
}

function rtaCheckIn(User $user, array $titles): Attendance
{
    return app(AttendanceService::class)->checkIn(
        $user,
        array_map(fn (string $title) => ['title' => $title], $titles)
    );
}

function rtaSentBodies(): array
{
    $bodies = [];
    Http::assertSent(function ($request) use (&$bodies) {
        $bodies[] = (string) $request->body();

        return true;
    });

    return $bodies;
}

// ---------------------------------------------------------------------------
// Standby detection
// ---------------------------------------------------------------------------

describe('standby detection', function () {
    it('recognises standby-like titles', function (string $title) {
        expect(Task::isStandbyTitle($title))->toBeTrue();
    })->with([
        'Standby',
        'standby',
        'STANDBY',
        'stand by',
        'Stand-by',
        'stand_by',
        'stand  by',
        'standbye',
        'stanby',
        'stan by',
        'standbay',
        'Hari ini standby di rumah',
        'On standby menunggu arahan',
        'Standby: tunggu instruksi dari pak Budi',
        '  stand by  ',
    ]);

    it('leaves ordinary titles alone', function (?string $title) {
        expect(Task::isStandbyTitle($title))->toBeFalse();
    })->with([
        'Standard meeting',
        'Standing desk setup',
        'Review coding standards',
        'Understand by heart',
        'Bystander report',
        'Menyiapkan stand pameran',
        'Stand booth bazar',
        'Fix login bug',
        '',
        null,
    ]);

    it('normalizes titles for comparison', function () {
        expect(Task::normalizeTitle("  FIX   Login\tbug "))->toBe('fix login bug')
            ->and(Task::normalizeTitle(null))->toBe('');
    });

    it('sends the standby notification for a title that merely mentions standby', function () {
        rtaConnectWhatsApp($this->team);
        Http::fake([RTA_GATEWAY => Http::response(['status' => 200], 200)]);

        $attendance = rtaCheckIn($this->employee, ['Hari ini stand by di rumah', 'Docs']);

        expect($attendance->isStandby())->toBeTrue();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->body(), 'Alice check-in dalam mode standby')
            && str_contains($request->body(), 'Hari ini stand by di rumah, Docs'));
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->employee->id,
            'action' => ActivityType::WHATSAPP_STANDBY_SENT->value,
        ]);
    });

    it('does not treat a title such as "Standard meeting" as standby', function () {
        rtaConnectWhatsApp($this->team);
        Http::fake();

        $attendance = rtaCheckIn($this->employee, ['Standard meeting']);

        expect($attendance->isStandby())->toBeFalse();
        Http::assertNothingSent();
        $this->assertDatabaseMissing('activity_logs', ['action' => ActivityType::WHATSAPP_STANDBY_SENT->value]);
    });

    it('exposes is_standby on the check-in response and the manager attendance list', function () {
        $response = $this->actingAs($this->employee)->postJson('/api/v1/attendance/check-in', [
            'tasks' => [['title' => 'Stand-by menunggu arahan'], ['title' => 'Docs']],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.is_standby', true)
            ->assertJsonPath('data.tasks.0.is_standby', true)
            ->assertJsonPath('data.tasks.1.is_standby', false);

        $manager = $this->createManager();
        $this->actingAs($manager)->getJson('/api/v1/manager/attendances')
            ->assertOk()
            ->assertJsonPath('data.0.is_standby', true);

        $this->actingAs($this->employee)->postJson('/api/v1/attendance/check-out', [
            'attendance_id' => $response->json('data.id'),
            'tasks' => [],
        ]);
        rtaSession($this->employee, '2026-03-10', ['Docs' => true]);

        $this->actingAs($manager)->getJson('/api/v1/manager/attendances')
            ->assertOk()
            ->assertJsonPath('data.1.is_standby', false);
    });
});

// ---------------------------------------------------------------------------
// Repeated task alert
// ---------------------------------------------------------------------------

describe('repeated task alert', function () {
    beforeEach(function () {
        rtaConnectWhatsApp($this->team);
        Http::fake([RTA_GATEWAY => Http::response(['status' => 200], 200)]);
    });

    it('asks the manager to review a task carried unfinished into the third check-in in a row', function () {
        rtaSession($this->employee, '2026-03-09', ['Fix login bug' => [false, 'menunggu akses server']]);
        rtaSession($this->employee, '2026-03-10', ['Fix login bug' => [false, 'masih menunggu akses'], 'Docs' => true]);

        rtaCheckIn($this->employee, ['Fix login bug', 'Docs']);

        Http::assertSentCount(1);
        [$body] = rtaSentBodies();
        expect($body)->toContain('🔁 Tugas Perlu Review')
            ->toContain('Alice membawa tugas yang sama tanpa selesai di 3x check-in berturut-turut:')
            ->toContain("• Fix login bug (3x)\n  Blocker terakhir: masih menunggu akses")
            ->toContain('14:00 WIB - 11 Mar 2026')
            ->toContain('Mohon review tugas tersebut bersama Alice.')
            ->not->toContain('Docs');

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->employee->id,
            'action' => ActivityType::WHATSAPP_TASK_REVIEW_SENT->value,
            'description' => 'Task review notification sent to manager via WhatsApp: Fix login bug',
        ]);
    });

    it('stays quiet on the second check-in in a row', function () {
        rtaSession($this->employee, '2026-03-10', ['Fix login bug' => false]);

        rtaCheckIn($this->employee, ['Fix login bug']);

        Http::assertNothingSent();
        $this->assertDatabaseMissing('activity_logs', ['action' => ActivityType::WHATSAPP_TASK_REVIEW_SENT->value]);
    });

    it('does not count a session in which the task was completed', function () {
        rtaSession($this->employee, '2026-03-06', ['Fix login bug' => false]);
        rtaSession($this->employee, '2026-03-09', ['Fix login bug' => false]);
        rtaSession($this->employee, '2026-03-10', ['Fix login bug' => true]);

        rtaCheckIn($this->employee, ['Fix login bug']);

        Http::assertNothingSent();
    });

    it('breaks the streak when a session in between did not carry the task', function () {
        rtaSession($this->employee, '2026-03-06', ['Fix login bug' => false]);
        rtaSession($this->employee, '2026-03-09', ['Fix login bug' => false]);
        rtaSession($this->employee, '2026-03-10', ['Something else' => false]);

        rtaCheckIn($this->employee, ['Fix login bug']);

        Http::assertNothingSent();
    });

    it('counts sessions in check-in order, including several sessions on one day', function () {
        rtaSession($this->employee, '2026-03-10', ['Fix login bug' => false]);
        Attendance::create([
            'user_id' => $this->employee->id,
            'check_in' => Carbon::parse('2026-03-11 08:00:00'),
            'check_out' => Carbon::parse('2026-03-11 12:00:00'),
            'date' => '2026-03-11',
            'total_hours' => 4,
        ])->tasks()->create(['title' => 'Fix login bug', 'is_completed' => false]);

        rtaCheckIn($this->employee, ['Fix login bug']);

        Http::assertSentCount(1);
        expect(rtaSentBodies()[0])->toContain('• Fix login bug (3x)');
    });

    it('matches titles ignoring case and whitespace', function () {
        rtaSession($this->employee, '2026-03-09', ['fix login BUG' => false]);
        rtaSession($this->employee, '2026-03-10', ['  Fix   login bug ' => false]);

        rtaCheckIn($this->employee, ['FIX LOGIN BUG']);

        Http::assertSentCount(1);
        expect(rtaSentBodies()[0])->toContain('• FIX LOGIN BUG (3x)');
    });

    it('never counts standby tasks', function () {
        rtaSession($this->employee, '2026-03-09', ['Standby' => false]);
        rtaSession($this->employee, '2026-03-10', ['stand by' => false]);

        rtaCheckIn($this->employee, ['Standby']);

        // Only the standby notification itself goes out.
        Http::assertSentCount(1);
        expect(rtaSentBodies()[0])->toContain('🔔 Standby')->not->toContain('🔁');
        $this->assertDatabaseMissing('activity_logs', ['action' => ActivityType::WHATSAPP_TASK_REVIEW_SENT->value]);
    });

    it('reminds again at every further multiple of the threshold, not in between', function () {
        foreach (['2026-03-04', '2026-03-05', '2026-03-06'] as $day) {
            rtaSession($this->employee, $day, ['Fix login bug' => false]);
        }

        // Fourth check-in in a row: nothing.
        rtaCheckIn($this->employee, ['Fix login bug']);
        Http::assertNothingSent();
        app(AttendanceService::class)->checkOut($this->employee, Attendance::latest('id')->first()->id, []);

        // Fifth: still nothing.
        $this->travelTo(Carbon::parse('2026-03-12 09:00:00'));
        rtaCheckIn($this->employee, ['Fix login bug']);
        Http::assertNothingSent();
        app(AttendanceService::class)->checkOut($this->employee->fresh(), Attendance::latest('id')->first()->id, []);

        // Sixth: reminder with the running count.
        $this->travelTo(Carbon::parse('2026-03-13 09:00:00'));
        rtaCheckIn($this->employee, ['Fix login bug']);
        Http::assertSentCount(1);
        expect(rtaSentBodies()[0])->toContain('• Fix login bug (6x)');
    });

    it('lists every task that reached the threshold in one message', function () {
        rtaSession($this->employee, '2026-03-09', ['Fix login bug' => false, 'Write docs' => false]);
        rtaSession($this->employee, '2026-03-10', ['Fix login bug' => false, 'Write docs' => false, 'New thing' => false]);

        rtaCheckIn($this->employee, ['Fix login bug', 'New thing', 'Write docs', 'Write docs']);

        Http::assertSentCount(1);
        [$body] = rtaSentBodies();
        expect($body)->toContain("• Fix login bug (3x)\n• Write docs (3x)")
            ->not->toContain('New thing');
        expect(substr_count($body, 'Write docs'))->toBe(1);
        $this->assertDatabaseHas('activity_logs', [
            'action' => ActivityType::WHATSAPP_TASK_REVIEW_SENT->value,
            'description' => 'Task review notification sent to manager via WhatsApp: Fix login bug, Write docs',
        ]);
    });

    it('shows the latest blocker written by the employee, skipping the auto-checkout placeholder', function () {
        rtaSession($this->employee, '2026-03-09', ['Fix login bug' => [false, 'menunggu akses server']]);
        rtaSession($this->employee, '2026-03-10', ['Fix login bug' => [false, Task::AUTO_CHECKOUT_BLOCKER]]);

        rtaCheckIn($this->employee, ['Fix login bug']);

        expect(rtaSentBodies()[0])->toContain("• Fix login bug (3x)\n  Blocker terakhir: menunggu akses server");
    });

    it('omits the blocker line when no reason was ever given', function () {
        rtaSession($this->employee, '2026-03-09', ['Fix login bug' => false]);
        rtaSession($this->employee, '2026-03-10', ['Fix login bug' => [false, Task::AUTO_CHECKOUT_BLOCKER]]);

        rtaCheckIn($this->employee, ['Fix login bug']);

        expect(rtaSentBodies()[0])->toContain("• Fix login bug (3x)\n⏰ ")->not->toContain('Blocker terakhir');
    });

    it('uses the configured threshold', function () {
        config(['attendance.repeated_task_threshold' => 2]);
        rtaSession($this->employee, '2026-03-10', ['Fix login bug' => false]);

        rtaCheckIn($this->employee, ['Fix login bug']);

        Http::assertSentCount(1);
        expect(rtaSentBodies()[0])->toContain('di 2x check-in berturut-turut')
            ->toContain('• Fix login bug (2x)');
    });

    it('never goes below a threshold of two', function () {
        config(['attendance.repeated_task_threshold' => 0]);

        expect(RepeatedTaskAlertService::threshold())->toBe(2);
    });

    it('sends nothing when the team has no WhatsApp recipient', function () {
        $this->team->update(['whatsapp_recipient_phone' => null]);
        rtaSession($this->employee, '2026-03-09', ['Fix login bug' => false]);
        rtaSession($this->employee, '2026-03-10', ['Fix login bug' => false]);

        rtaCheckIn($this->employee, ['Fix login bug']);

        Http::assertNothingSent();
    });

    it('keeps the check-in when the gateway fails', function () {
        // The describe-level fake answers 200 first, so fail at the gateway service instead.
        $this->mock(WhatsAppGatewayService::class)
            ->shouldReceive('sendMessage')
            ->once()
            ->andThrow(new Exception('Gateway error'));
        Log::spy();
        rtaSession($this->employee, '2026-03-09', ['Fix login bug' => false]);
        rtaSession($this->employee, '2026-03-10', ['Fix login bug' => false]);

        $attendance = rtaCheckIn($this->employee, ['Fix login bug']);

        expect($attendance->exists)->toBeTrue();
        $this->assertDatabaseMissing('activity_logs', ['action' => ActivityType::WHATSAPP_TASK_REVIEW_SENT->value]);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_starts_with($message, 'Task review WhatsApp notification failed'))
            ->once();
    });

    it('reports repeated tasks through the API check-in as well', function () {
        rtaSession($this->employee, '2026-03-09', ['Fix login bug' => false]);
        rtaSession($this->employee, '2026-03-10', ['Fix login bug' => false]);

        $this->actingAs($this->employee)->postJson('/api/v1/attendance/check-in', [
            'tasks' => [['title' => 'Fix login bug']],
        ])->assertStatus(201);

        Http::assertSentCount(1);
        expect(rtaSentBodies()[0])->toContain('🔁 Tugas Perlu Review');
    });

    it('ignores sessions of other employees', function () {
        $bob = $this->createEmployee(['name' => 'Bob']);
        rtaSession($bob, '2026-03-09', ['Fix login bug' => false]);
        rtaSession($bob, '2026-03-10', ['Fix login bug' => false]);
        rtaSession($this->employee, '2026-03-10', ['Fix login bug' => false]);

        rtaCheckIn($this->employee, ['Fix login bug']);

        Http::assertNothingSent();
    });
});
