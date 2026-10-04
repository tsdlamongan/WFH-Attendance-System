<?php

use App\Enums\ActivityType;
use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\WhatsAppGatewayService;
use App\Services\WhatsAppRecapService;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const WA_RECAP_SEND_URL = 'https://whatsapp.perekonomian.id/api/send/whatsapp';

/**
 * A team that is fully configured to send WhatsApp recaps.
 */
function waRecapTeam(array $attributes = []): Team
{
    return Team::factory()->create(array_merge([
        'name' => 'Recap Team',
        'whatsapp_api_secret' => 'gateway-secret',
        'whatsapp_account_unique_id' => 'account-unique-id',
        'whatsapp_connected' => true,
        'whatsapp_recipient_phone' => '628123456789',
        'whatsapp_recap_enabled' => true,
        'whatsapp_recap_time' => '22:00',
    ], $attributes));
}

function waRecapUser(Team $team, string $name, UserRole $role = UserRole::EMPLOYEE, array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'team_id' => $team->id,
        'name' => $name,
        'role' => $role,
    ], $attributes));
}

function waRecapAttendance(User $user, string $date, string $checkIn, ?string $checkOut, float $hours = 0): Attendance
{
    return Attendance::factory()->create([
        'user_id' => $user->id,
        'date' => $date,
        'check_in' => Carbon::parse("{$date} {$checkIn}"),
        'check_out' => $checkOut ? Carbon::parse("{$date} {$checkOut}") : null,
        'total_hours' => $hours,
    ]);
}

function waRecapTask(Attendance $attendance, string $title, bool $completed, ?string $blocker = null): Task
{
    return Task::factory()->create([
        'attendance_id' => $attendance->id,
        'title' => $title,
        'is_completed' => $completed,
        'blocker_reason' => $blocker,
    ]);
}

function waRecapService(): WhatsAppRecapService
{
    return app(WhatsAppRecapService::class);
}

/**
 * Empty recap payload that formatRecapMessage() accepts; override pieces per test.
 */
function waRecapPayload(array $overrides = []): array
{
    return array_merge([
        'date' => Carbon::parse('2026-03-12'),
        'team_name' => 'Recap Team',
        'checked_in_out' => [],
        'still_checked_in' => [],
        'not_checked_in' => [],
        'all_tasks_count' => 0,
        'completed_tasks' => [],
        'incomplete_tasks' => [],
    ], $overrides);
}

beforeEach(function () {
    // Thursday 12 March 2026, 15:30 (app timezone)
    $this->travelTo(Carbon::parse('2026-03-12 15:30:00'));
    Http::preventStrayRequests();
});

describe('generateRecapData', function () {
    it('returns empty groups for a team without employees', function () {
        $team = waRecapTeam(['name' => 'Lonely Team']);
        waRecapUser($team, 'Boss', UserRole::MANAGER);

        $data = waRecapService()->generateRecapData($team, Carbon::today());

        expect($data['team_name'])->toBe('Lonely Team')
            ->and($data['date']->toDateString())->toBe('2026-03-12')
            ->and($data['checked_in_out'])->toBe([])
            ->and($data['still_checked_in'])->toBe([])
            ->and($data['not_checked_in'])->toBe([])
            ->and($data['all_tasks_count'])->toBe(0)
            ->and($data['completed_tasks'])->toBe([])
            ->and($data['incomplete_tasks'])->toBe([]);
    });

    it('classifies employees as checked out, still checked in or not checked in and collects their tasks', function () {
        $team = waRecapTeam();

        $alice = waRecapUser($team, 'Alice');
        $aliceAttendance = waRecapAttendance($alice, '2026-03-12', '09:00', '17:00', 8);
        waRecapTask($aliceAttendance, 'Write report', true);
        waRecapTask($aliceAttendance, 'Fix deploy', false, 'Waiting for credentials');

        $bob = waRecapUser($team, 'Bob');
        $bobAttendance = waRecapAttendance($bob, '2026-03-12', '10:00', null);
        waRecapTask($bobAttendance, 'Review PRs', true);
        waRecapTask($bobAttendance, 'Plan sprint', false);

        waRecapUser($team, 'Carol');

        $data = waRecapService()->generateRecapData($team, Carbon::today());

        expect($data['checked_in_out'])->toBe([
            ['name' => 'Alice', 'total_hours' => '8.0', 'tasks_completed' => 1, 'tasks_total' => 2],
        ]);

        expect($data['still_checked_in'])->toHaveCount(1)
            ->and($data['still_checked_in'][0]['name'])->toBe('Bob')
            ->and($data['still_checked_in'][0]['check_in'])->toBe('10:00')
            ->and($data['still_checked_in'][0]['from_yesterday'])->toBeFalse()
            ->and($data['still_checked_in'][0]['duration'])->toEqual(5.5);

        expect($data['not_checked_in'])->toBe([
            ['name' => 'Carol', 'on_leave' => false],
        ]);

        expect($data['all_tasks_count'])->toBe(4)
            ->and($data['completed_tasks'])->toBe([
                ['employee' => 'Alice', 'title' => 'Write report'],
                ['employee' => 'Bob', 'title' => 'Review PRs'],
            ])
            ->and($data['incomplete_tasks'])->toBe([
                ['employee' => 'Alice', 'title' => 'Fix deploy', 'blocker' => 'Waiting for credentials'],
                ['employee' => 'Bob', 'title' => 'Plan sprint', 'blocker' => null],
            ]);
    });

    it('sums hours across several completed sessions on the same day', function () {
        $team = waRecapTeam();
        $alice = waRecapUser($team, 'Alice');

        $morning = waRecapAttendance($alice, '2026-03-12', '08:00', '11:30', 3.5);
        $afternoon = waRecapAttendance($alice, '2026-03-12', '12:30', '16:45', 4.25);
        waRecapTask($morning, 'Morning task', true);
        waRecapTask($afternoon, 'Afternoon task', false, 'Blocked by QA');
        waRecapTask($afternoon, 'Another task', true);

        $data = waRecapService()->generateRecapData($team, Carbon::today());

        expect($data['still_checked_in'])->toBe([])
            ->and($data['not_checked_in'])->toBe([])
            ->and($data['checked_in_out'])->toBe([
                ['name' => 'Alice', 'total_hours' => '7.8', 'tasks_completed' => 2, 'tasks_total' => 3],
            ])
            ->and($data['all_tasks_count'])->toBe(3);
    });

    it('flags an active session carried over from yesterday and still reports its tasks', function () {
        $team = waRecapTeam();
        $dan = waRecapUser($team, 'Dan');

        $overnight = waRecapAttendance($dan, '2026-03-11', '23:00', null);
        waRecapTask($overnight, 'Overnight migration', false, 'Long running job');

        $data = waRecapService()->generateRecapData($team, Carbon::today());

        expect($data['not_checked_in'])->toBe([])
            ->and($data['checked_in_out'])->toBe([])
            ->and($data['still_checked_in'])->toHaveCount(1)
            ->and($data['still_checked_in'][0]['name'])->toBe('Dan')
            ->and($data['still_checked_in'][0]['check_in'])->toBe('23:00')
            ->and($data['still_checked_in'][0]['from_yesterday'])->toBeTrue()
            ->and($data['still_checked_in'][0]['duration'])->toEqual(16.5)
            ->and($data['incomplete_tasks'])->toBe([
                ['employee' => 'Dan', 'title' => 'Overnight migration', 'blocker' => 'Long running job'],
            ]);
    });

    it('lists an employee with a finished and a running session today as still checked in only', function () {
        $team = waRecapTeam();
        $eve = waRecapUser($team, 'Eve');

        waRecapAttendance($eve, '2026-03-12', '08:00', '12:00', 4);
        waRecapAttendance($eve, '2026-03-12', '13:00', null);

        $data = waRecapService()->generateRecapData($team, Carbon::today());

        expect($data['checked_in_out'])->toBe([])
            ->and($data['not_checked_in'])->toBe([])
            ->and(array_column($data['still_checked_in'], 'name'))->toBe(['Eve'])
            ->and($data['still_checked_in'][0]['check_in'])->toBe('13:00');
    });

    it('marks a missing employee as on leave only for an approved leave covering the date', function () {
        $team = waRecapTeam();

        $approved = waRecapUser($team, 'A Approved');
        $pending = waRecapUser($team, 'B Pending');
        $rejected = waRecapUser($team, 'C Rejected');
        $past = waRecapUser($team, 'D Past');
        $future = waRecapUser($team, 'E Future');
        $startsToday = waRecapUser($team, 'F StartsToday');
        $endsToday = waRecapUser($team, 'G EndsToday');

        $leave = fn (User $user, LeaveStatus $status, string $start, string $end) => Leave::factory()->create([
            'user_id' => $user->id,
            'status' => $status,
            'start_date' => $start,
            'end_date' => $end,
        ]);

        $leave($approved, LeaveStatus::APPROVED, '2026-03-10', '2026-03-14');
        $leave($pending, LeaveStatus::PENDING, '2026-03-10', '2026-03-14');
        $leave($rejected, LeaveStatus::REJECTED, '2026-03-10', '2026-03-14');
        $leave($past, LeaveStatus::APPROVED, '2026-03-01', '2026-03-11');
        $leave($future, LeaveStatus::APPROVED, '2026-03-13', '2026-03-15');
        $leave($startsToday, LeaveStatus::APPROVED, '2026-03-12', '2026-03-13');
        $leave($endsToday, LeaveStatus::APPROVED, '2026-03-11', '2026-03-12');

        $data = waRecapService()->generateRecapData($team, Carbon::today());

        $onLeave = collect($data['not_checked_in'])->pluck('on_leave', 'name')->all();

        expect($onLeave)->toBe([
            'A Approved' => true,
            'B Pending' => false,
            'C Rejected' => false,
            'D Past' => false,
            'E Future' => false,
            'F StartsToday' => true,
            'G EndsToday' => true,
        ]);
    });

    it('only includes active employees from the requested team', function () {
        $team = waRecapTeam();
        $otherTeam = waRecapTeam(['name' => 'Other Team']);

        waRecapUser($team, 'Active Ann');
        waRecapUser($team, 'Disabled Dora', UserRole::EMPLOYEE, ['is_disabled' => true]);
        waRecapUser($team, 'Manager Max', UserRole::MANAGER);
        waRecapUser($otherTeam, 'Outsider Olga');

        $data = waRecapService()->generateRecapData($team, Carbon::today());

        expect(array_column($data['not_checked_in'], 'name'))->toBe(['Active Ann']);
    });

    it('reports a past date from the attendances of that day', function () {
        $team = waRecapTeam();
        $fay = waRecapUser($team, 'Fay');
        $gus = waRecapUser($team, 'Gus');

        $session = waRecapAttendance($fay, '2026-03-10', '09:00', '16:30', 7.5);
        waRecapTask($session, 'Old task', true);
        // Gus only worked today, so he was absent on the 10th.
        waRecapAttendance($gus, '2026-03-12', '09:00', '12:00', 3);

        $data = waRecapService()->generateRecapData($team, Carbon::parse('2026-03-10'));

        expect($data['date']->toDateString())->toBe('2026-03-10')
            ->and($data['checked_in_out'])->toBe([
                ['name' => 'Fay', 'total_hours' => '7.5', 'tasks_completed' => 1, 'tasks_total' => 1],
            ])
            ->and($data['not_checked_in'])->toBe([
                ['name' => 'Gus', 'on_leave' => false],
            ]);
    });
});

describe('formatRecapMessage', function () {
    it('renders "(None)" placeholders and zero counters for an empty recap', function () {
        $message = waRecapService()->formatRecapMessage(waRecapPayload());

        expect($message)->toStartWith("📊 *Daily Attendance Recap*\n🗓️ Recap Team - 12 Mar 2026\n")
            ->and($message)->toContain("✅ *Checked In & Out: 0*\n  (None)\n")
            ->and($message)->toContain("⏰ *Still Checked In: 0*\n  (None)\n")
            ->and($message)->toContain("❌ *Not Checked In: 0*\n  (None)\n")
            ->and($message)->toContain("📋 *All Tasks Today: 0*\n✓ Completed: 0\n✗ Incomplete: 0\n")
            ->and($message)->not->toContain('*Completed Tasks:*')
            ->and($message)->not->toContain('*Incomplete Tasks:*')
            ->and($message)->toEndWith('Generated at 15:30');
    });

    it('renders every section with employees, tasks and blockers', function () {
        $message = waRecapService()->formatRecapMessage(waRecapPayload([
            'checked_in_out' => [
                ['name' => 'Alice', 'total_hours' => '8.0', 'tasks_completed' => 1, 'tasks_total' => 2],
            ],
            'still_checked_in' => [
                ['name' => 'Bob', 'check_in' => '10:00', 'duration' => 5.5, 'from_yesterday' => false],
                ['name' => 'Dan', 'check_in' => '23:00', 'duration' => 16.5, 'from_yesterday' => true],
            ],
            'not_checked_in' => [
                ['name' => 'Carol', 'on_leave' => false],
                ['name' => 'Dave', 'on_leave' => true],
            ],
            'all_tasks_count' => 4,
            'completed_tasks' => [
                ['employee' => 'Alice', 'title' => 'Write report'],
            ],
            'incomplete_tasks' => [
                ['employee' => 'Alice', 'title' => 'Fix deploy', 'blocker' => 'Waiting for credentials'],
                ['employee' => 'Bob', 'title' => 'Plan sprint', 'blocker' => null],
                ['employee' => 'Dan', 'title' => 'Migrate data', 'blocker' => ''],
            ],
        ]));

        expect($message)
            ->toContain("✅ *Checked In & Out: 1*\n• Alice - 8.0h\n  Tasks: 1/2 completed\n")
            ->toContain("⏰ *Still Checked In: 2*\n• Bob (since 10:00)\n• Dan (since 23:00 - from yesterday)\n")
            ->toContain("❌ *Not Checked In: 2*\n• Carol\n• Dave (On Leave)\n")
            ->toContain("📋 *All Tasks Today: 4*\n✓ Completed: 1\n✗ Incomplete: 3\n")
            ->toContain("*Completed Tasks:*\n• Alice - Write report\n")
            ->toContain("*Incomplete Tasks:*\n• Alice - Fix deploy\n  Blocker: Waiting for credentials\n• Bob - Plan sprint\n• Dan - Migrate data\n")
            ->not->toContain('(None)');

        // Only the task with a real blocker gets a blocker line.
        expect(substr_count($message, 'Blocker:'))->toBe(1);
    });

    it('strips HTML tags from team, employee, task and blocker text', function () {
        $message = waRecapService()->formatRecapMessage(waRecapPayload([
            'team_name' => '<script>alert(1)</script>Team',
            'checked_in_out' => [
                ['name' => '<b>Mallory</b>', 'total_hours' => '1.0', 'tasks_completed' => 0, 'tasks_total' => 1],
            ],
            'still_checked_in' => [
                ['name' => '<i>Trent</i>', 'check_in' => '09:00', 'duration' => 1, 'from_yesterday' => false],
            ],
            'not_checked_in' => [
                ['name' => '<u>Victor</u>', 'on_leave' => false],
            ],
            'all_tasks_count' => 2,
            'completed_tasks' => [
                ['employee' => '<b>Mallory</b>', 'title' => '<img src=x onerror=alert(1)>Done'],
            ],
            'incomplete_tasks' => [
                ['employee' => '<b>Mallory</b>', 'title' => '<a href="http://evil.test">Open</a>', 'blocker' => '<em>Stuck</em>'],
            ],
        ]));

        expect($message)
            ->toContain('Team - 12 Mar 2026')
            ->toContain('• Mallory - 1.0h')
            ->toContain('• Trent (since 09:00)')
            ->toContain('• Victor')
            ->toContain('• Mallory - Done')
            ->toContain('• Mallory - Open')
            ->toContain('Blocker: Stuck')
            ->not->toContain('<')
            ->not->toContain('>')
            ->not->toContain('evil.test');
    });

    it('keeps special characters as plain text because WhatsApp does not render HTML', function () {
        $message = waRecapService()->formatRecapMessage(waRecapPayload([
            'team_name' => 'R&D "Core" Team',
            'checked_in_out' => [
                ['name' => "Ben & Jerry's", 'total_hours' => '1.0', 'tasks_completed' => 0, 'tasks_total' => 1],
            ],
            'all_tasks_count' => 1,
            'completed_tasks' => [
                ['employee' => "Ben & Jerry's", 'title' => 'Fix A&B > C "quoted" & done'],
            ],
            'incomplete_tasks' => [
                ['employee' => 'Zoe', 'title' => 'Q&A', 'blocker' => "Waiting on R&D's reply"],
            ],
        ]));

        expect($message)
            ->toContain('R&D "Core" Team - 12 Mar 2026')
            ->toContain("• Ben & Jerry's - 1.0h")
            ->toContain("• Ben & Jerry's - Fix A&B > C \"quoted\" & done")
            ->toContain('• Zoe - Q&A')
            ->toContain("Blocker: Waiting on R&D's reply")
            ->not->toContain('&amp;')
            ->not->toContain('&quot;')
            ->not->toContain('&#039;')
            ->not->toContain('&gt;');
    });

    it('delivers special characters untouched to the gateway', function () {
        Http::fake([WA_RECAP_SEND_URL => Http::response(['status' => 200], 200)]);

        $team = waRecapTeam(['name' => 'R&D Team']);
        waRecapUser($team, "Ben & Jerry's");

        expect(waRecapService()->sendRecap($team))->toBeTrue();

        Http::assertSent(function (Request $request) {
            $message = collect($request->data())->firstWhere('name', 'message')['contents'];

            return str_contains($message, 'R&D Team - 12 Mar 2026')
                && str_contains($message, "• Ben & Jerry's")
                && ! str_contains($message, '&amp;');
        });
    });

    it('truncates the raw text, so a cut can never split an HTML entity', function () {
        $message = waRecapService()->formatRecapMessage(waRecapPayload([
            'all_tasks_count' => 1,
            'completed_tasks' => [
                ['employee' => 'Alice', 'title' => str_repeat('&', 600)],
            ],
        ]));

        expect($message)->toContain('• Alice - '.str_repeat('&', 500)."\n")
            ->not->toContain(str_repeat('&', 501))
            ->not->toContain('&amp;');
    });

    it('truncates multibyte text on character boundaries', function () {
        $message = waRecapService()->formatRecapMessage(waRecapPayload([
            'all_tasks_count' => 1,
            'completed_tasks' => [
                ['employee' => 'Alice', 'title' => str_repeat('é', 600)],
            ],
        ]));

        expect($message)->toContain('• Alice - '.str_repeat('é', 500)."\n")
            ->not->toContain(str_repeat('é', 501));
        expect(mb_check_encoding($message, 'UTF-8'))->toBeTrue();
    });

    it('truncates over-long text to 500 characters', function () {
        $message = waRecapService()->formatRecapMessage(waRecapPayload([
            'all_tasks_count' => 1,
            'completed_tasks' => [
                ['employee' => 'Alice', 'title' => str_repeat('A', 600)],
            ],
        ]));

        expect($message)->toContain('• Alice - '.str_repeat('A', 500)."\n")
            ->not->toContain(str_repeat('A', 501));
    });
});

describe('previewRecap', function () {
    it('previews today by default without sending anything or touching the team', function () {
        Http::fake();
        $team = waRecapTeam(['whatsapp_last_error' => 'previous failure']);
        $alice = waRecapUser($team, 'Alice');
        $attendance = waRecapAttendance($alice, '2026-03-12', '09:00', '17:00', 8);
        waRecapTask($attendance, 'Ship feature', true);

        $message = waRecapService()->previewRecap($team);

        expect($message)->toContain('Recap Team - 12 Mar 2026')
            ->toContain('• Alice - 8.0h')
            ->toContain('• Alice - Ship feature');

        Http::assertNothingSent();
        expect($team->fresh()->whatsapp_last_error)->toBe('previous failure')
            ->and($team->fresh()->whatsapp_last_sent_at)->toBeNull();
    });

    it('previews an explicit past date', function () {
        $team = waRecapTeam();
        $alice = waRecapUser($team, 'Alice');
        waRecapAttendance($alice, '2026-03-09', '09:00', '13:00', 4);

        $message = waRecapService()->previewRecap($team, Carbon::parse('2026-03-09'));

        expect($message)->toContain('Recap Team - 09 Mar 2026')
            ->toContain('• Alice - 4.0h')
            ->toContain('❌ *Not Checked In: 0*');
    });
});

describe('sendRecap', function () {
    it('sends the recap through the gateway and records the success', function () {
        Http::fake([WA_RECAP_SEND_URL => Http::response(['status' => 200], 200)]);

        $team = waRecapTeam(['whatsapp_last_error' => 'old error']);
        $manager = waRecapUser($team, 'Manager Max', UserRole::MANAGER);
        $alice = waRecapUser($team, 'Alice');
        $attendance = waRecapAttendance($alice, '2026-03-12', '09:00', '17:00', 8);
        waRecapTask($attendance, 'Ship feature', true);

        expect(waRecapService()->sendRecap($team))->toBeTrue();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $fields = collect($request->data())->pluck('contents', 'name');

            return $request->url() === WA_RECAP_SEND_URL
                && $fields['secret'] === 'gateway-secret'
                && $fields['account'] === 'account-unique-id'
                && $fields['recipient'] === '628123456789'
                && $fields['type'] === 'text'
                && str_contains($fields['message'], 'Recap Team - 12 Mar 2026')
                && str_contains($fields['message'], '• Alice - 8.0h')
                && ! str_contains($fields['message'], '(part ');
        });

        $team->refresh();
        expect($team->whatsapp_last_error)->toBeNull()
            ->and($team->whatsapp_last_sent_at->toDateTimeString())->toBe('2026-03-12 15:30:00');

        $log = ActivityLog::where('action', ActivityType::WHATSAPP_RECAP_SENT)->sole();
        expect($log->user_id)->toBe($manager->id)
            ->and($log->team_id)->toBe($team->id)
            ->and($log->description)->toBe('Daily attendance recap sent via WhatsApp for 12 Mar 2026');
    });

    it('sends the recap for an explicit date', function () {
        Http::fake([WA_RECAP_SEND_URL => Http::response(['status' => 200], 200)]);

        $team = waRecapTeam();
        waRecapUser($team, 'Manager Max', UserRole::MANAGER);
        $alice = waRecapUser($team, 'Alice');
        waRecapAttendance($alice, '2026-03-09', '09:00', '13:00', 4);

        expect(waRecapService()->sendRecap($team, Carbon::parse('2026-03-09')))->toBeTrue();

        Http::assertSent(function (Request $request) {
            $message = collect($request->data())->firstWhere('name', 'message')['contents'];

            return str_contains($message, 'Recap Team - 09 Mar 2026') && str_contains($message, '• Alice - 4.0h');
        });
        $this->assertDatabaseHas('activity_logs', [
            'action' => ActivityType::WHATSAPP_RECAP_SENT->value,
            'description' => 'Daily attendance recap sent via WhatsApp for 09 Mar 2026',
        ]);
    });

    it('does not log an activity when the team has no enabled manager', function () {
        Http::fake([WA_RECAP_SEND_URL => Http::response(['status' => 200], 200)]);

        $team = waRecapTeam();
        waRecapUser($team, 'Disabled Manager', UserRole::MANAGER, ['is_disabled' => true]);
        waRecapUser($team, 'Alice');

        expect(waRecapService()->sendRecap($team))->toBeTrue();

        Http::assertSentCount(1);
        expect(ActivityLog::count())->toBe(0)
            ->and($team->fresh()->whatsapp_last_sent_at)->not->toBeNull();
    });

    it('attributes the activity to the first enabled manager', function () {
        Http::fake([WA_RECAP_SEND_URL => Http::response(['status' => 200], 200)]);

        $team = waRecapTeam();
        waRecapUser($team, 'Disabled Manager', UserRole::MANAGER, ['is_disabled' => true]);
        $active = waRecapUser($team, 'Active Manager', UserRole::MANAGER);

        expect(waRecapService()->sendRecap($team))->toBeTrue();

        expect(ActivityLog::where('action', ActivityType::WHATSAPP_RECAP_SENT)->sole()->user_id)->toBe($active->id);
    });

    it('refuses to send when the team is not configured and records why', function (array $override) {
        Http::fake();
        Log::spy();

        $team = waRecapTeam($override);
        waRecapUser($team, 'Manager Max', UserRole::MANAGER);

        expect(waRecapService()->sendRecap($team))->toBeFalse();

        Http::assertNothingSent();
        $team->refresh();
        expect($team->whatsapp_last_error)->toBe('Team is not properly configured for WhatsApp recap')
            ->and($team->whatsapp_last_sent_at)->toBeNull();
        expect(ActivityLog::count())->toBe(0);

        Log::shouldHaveReceived('warning')
            ->with("Cannot send WhatsApp recap for team {$team->id}: Team is not properly configured for WhatsApp recap")
            ->once();
    })->with([
        'recap disabled' => [['whatsapp_recap_enabled' => false]],
        'not connected' => [['whatsapp_connected' => false]],
        'no recipient phone' => [['whatsapp_recipient_phone' => null]],
        'no api secret' => [['whatsapp_api_secret' => null]],
        'no account id' => [['whatsapp_account_unique_id' => null]],
    ]);

    it('returns false and stores the gateway message when the gateway rejects the send', function () {
        Http::fake([WA_RECAP_SEND_URL => Http::response(['status' => 403, 'message' => 'Invalid API secret'], 200)]);
        Log::spy();

        $team = waRecapTeam();
        waRecapUser($team, 'Manager Max', UserRole::MANAGER);

        expect(waRecapService()->sendRecap($team))->toBeFalse();

        $team->refresh();
        expect($team->whatsapp_last_error)->toBe('Invalid API secret')
            ->and($team->whatsapp_last_sent_at)->toBeNull()
            ->and(ActivityLog::count())->toBe(0);

        Log::shouldHaveReceived('error')
            ->with("WhatsApp recap send failed for team {$team->id}: Invalid API secret")
            ->once();
    });

    it('returns false and stores the failure when the gateway is down', function () {
        Http::fake([WA_RECAP_SEND_URL => Http::response('Bad Gateway', 502)]);

        $team = waRecapTeam();

        expect(waRecapService()->sendRecap($team))->toBeFalse();

        expect($team->fresh()->whatsapp_last_error)->toBe('Failed to send message: Bad Gateway')
            ->and($team->fresh()->whatsapp_last_sent_at)->toBeNull();
    });

    it('splits an over-long recap into labelled parts that fit the message limit', function () {
        $team = waRecapTeam();
        $manager = waRecapUser($team, 'Manager Max', UserRole::MANAGER);
        $alice = waRecapUser($team, 'Alice');
        $attendance = waRecapAttendance($alice, '2026-03-12', '09:00', '17:00', 8);

        $markers = [];
        foreach (range(1, 20) as $i) {
            $marker = sprintf('TASK%02d', $i);
            $markers[] = $marker;
            waRecapTask($attendance, $marker.str_repeat('x', 240), true);
        }

        $sent = [];
        $this->mock(WhatsAppGatewayService::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('sendMessage')->twice()->andReturnUsing(
                function (string $secret, string $account, string $recipient, string $message) use (&$sent) {
                    $sent[] = compact('secret', 'account', 'recipient', 'message');

                    return true;
                }
            );
        });

        expect(waRecapService()->sendRecap($team))->toBeTrue();

        expect($sent)->toHaveCount(2);
        foreach ($sent as $call) {
            expect($call['secret'])->toBe('gateway-secret')
                ->and($call['account'])->toBe('account-unique-id')
                ->and($call['recipient'])->toBe('628123456789')
                ->and(strlen($call['message']))->toBeLessThanOrEqual(3800 + strlen(' (part 1/2)'));
        }

        expect($sent[0]['message'])->toStartWith('📊 *Daily Attendance Recap*')
            ->toEndWith(' (part 1/2)')
            ->and($sent[1]['message'])->toEndWith('Generated at 15:30 (part 2/2)');

        // Every task line is delivered exactly once across the parts.
        $combined = $sent[0]['message']."\n".$sent[1]['message'];
        foreach ($markers as $marker) {
            expect(substr_count($combined, $marker))->toBe(1);
        }

        expect(ActivityLog::where('action', ActivityType::WHATSAPP_RECAP_SENT)->sole()->description)
            ->toBe('Daily attendance recap sent via WhatsApp for 12 Mar 2026 (2 messages)')
            ->and(ActivityLog::first()->user_id)->toBe($manager->id)
            ->and($team->fresh()->whatsapp_last_error)->toBeNull()
            ->and($team->fresh()->whatsapp_last_sent_at)->not->toBeNull();
    });
});
