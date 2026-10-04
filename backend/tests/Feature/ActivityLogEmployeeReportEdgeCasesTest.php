<?php

use App\Enums\ActivityType;
use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\User;
use App\Repositories\ActivityLogRepository;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

function alEdgeHeaders(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('auth-token')->plainTextToken];
}

/*
|--------------------------------------------------------------------------
| Activity log index
|--------------------------------------------------------------------------
*/

it('ignores an unknown action filter, logs a warning and still returns every log', function () {
    $manager = $this->createManager();
    $employee = $this->createEmployee();
    $checkIn = $this->createActivityLog(['user_id' => $employee->id, 'action' => ActivityType::CHECK_IN]);
    $checkOut = $this->createActivityLog(['user_id' => $employee->id, 'action' => ActivityType::CHECK_OUT]);
    Log::spy();

    $response = $this->getJson('/api/v1/manager/activity-logs?action=not_a_real_action', alEdgeHeaders($manager));

    $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('pagination.total', 2);
    $ids = collect($response->json('data.logs'))->pluck('id')->all();
    expect($ids)->toContain($checkIn->id, $checkOut->id);
    Log::shouldHaveReceived('warning')->with('Invalid activity type: not_a_real_action')->once();
});

it('filters logs by a valid action and by user', function () {
    $manager = $this->createManager();
    $alice = $this->createEmployee();
    $bob = $this->createEmployee();
    $aliceIn = $this->createActivityLog(['user_id' => $alice->id, 'action' => ActivityType::CHECK_IN]);
    $aliceOut = $this->createActivityLog(['user_id' => $alice->id, 'action' => ActivityType::CHECK_OUT]);
    $bobIn = $this->createActivityLog(['user_id' => $bob->id, 'action' => ActivityType::CHECK_IN]);
    $headers = alEdgeHeaders($manager);

    $byAction = $this->getJson('/api/v1/manager/activity-logs?action=check_in', $headers);
    $byAction->assertOk()->assertJsonPath('pagination.total', 2);
    expect(collect($byAction->json('data.logs'))->pluck('action')->unique()->all())->toBe(['check_in'])
        ->and(collect($byAction->json('data.logs'))->pluck('id')->all())->toContain($aliceIn->id, $bobIn->id)
        ->and(collect($byAction->json('data.logs'))->pluck('id')->all())->not->toContain($aliceOut->id);

    $byUser = $this->getJson("/api/v1/manager/activity-logs?user_id={$alice->id}", $headers);
    $byUser->assertOk()->assertJsonPath('pagination.total', 2);
    expect(collect($byUser->json('data.logs'))->pluck('user.id')->unique()->all())->toBe([$alice->id]);

    $both = $this->getJson("/api/v1/manager/activity-logs?user_id={$alice->id}&action=check_out", $headers);
    $both->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.logs.0.id', $aliceOut->id);
});

it('applies start_date, end_date and the combined range inclusively to whole days', function () {
    $manager = $this->createManager();
    $employee = $this->createEmployee();
    $beforeRange = $this->createActivityLog(['user_id' => $employee->id, 'created_at' => '2025-03-09 23:59:59']);
    $startOfFirstDay = $this->createActivityLog(['user_id' => $employee->id, 'created_at' => '2025-03-10 00:00:00']);
    $endOfLastDay = $this->createActivityLog(['user_id' => $employee->id, 'created_at' => '2025-03-12 23:30:00']);
    $afterRange = $this->createActivityLog(['user_id' => $employee->id, 'created_at' => '2025-03-13 00:00:01']);
    $headers = alEdgeHeaders($manager);

    $idsFor = fn (string $query) => collect($this->getJson("/api/v1/manager/activity-logs?{$query}", $headers)
        ->assertOk()->json('data.logs'))->pluck('id')->sort()->values()->all();

    expect($idsFor('start_date=2025-03-10&end_date=2025-03-12'))
        ->toBe(collect([$startOfFirstDay->id, $endOfLastDay->id])->sort()->values()->all());

    expect($idsFor('start_date=2025-03-10'))
        ->toBe(collect([$startOfFirstDay->id, $endOfLastDay->id, $afterRange->id])->sort()->values()->all());

    expect($idsFor('end_date=2025-03-12'))
        ->toBe(collect([$beforeRange->id, $startOfFirstDay->id, $endOfLastDay->id])->sort()->values()->all());

    expect($idsFor('start_date=2030-01-01'))->toBe([]);
});

it('returns logs newest first with the full resource shape', function () {
    $manager = $this->createManager();
    $employee = $this->createEmployee(['name' => 'Logged Employee']);
    $older = $this->createActivityLog([
        'user_id' => $employee->id,
        'action' => ActivityType::CHECK_IN,
        'description' => 'Older entry',
        'ip_address' => '10.0.0.1',
        'user_agent' => 'PestAgent/1.0',
        'created_at' => '2025-05-01 08:00:00',
    ]);
    $newer = $this->createActivityLog([
        'user_id' => $employee->id,
        'action' => ActivityType::CHECK_OUT,
        'description' => 'Newer entry',
        'created_at' => '2025-05-01 17:00:00',
    ]);

    $response = $this->getJson('/api/v1/manager/activity-logs', alEdgeHeaders($manager));

    $response->assertOk()
        ->assertJsonPath('data.logs.0.id', $newer->id)
        ->assertJsonPath('data.logs.1.id', $older->id)
        ->assertJsonPath('data.logs.1.action', 'check_in')
        ->assertJsonPath('data.logs.1.description', 'Older entry')
        ->assertJsonPath('data.logs.1.ip_address', '10.0.0.1')
        ->assertJsonPath('data.logs.1.user_agent', 'PestAgent/1.0')
        ->assertJsonPath('data.logs.1.user.id', $employee->id)
        ->assertJsonPath('data.logs.1.user.name', 'Logged Employee')
        ->assertJsonPath('data.logs.1.created_at', Carbon::parse('2025-05-01 08:00:00')->toIso8601String());
});

it('only exposes logs of the manager team and lets a super admin see every team', function () {
    $manager = $this->createManager();
    $own = $this->createActivityLog(['user_id' => $manager->id]);

    $otherTeam = $this->createTeam();
    $outsider = User::factory()->create(['team_id' => $otherTeam->id]);
    $foreign = $this->createActivityLog(['team_id' => $otherTeam->id, 'user_id' => $outsider->id]);

    $managerView = $this->getJson("/api/v1/manager/activity-logs?user_id={$outsider->id}", alEdgeHeaders($manager));
    $managerView->assertOk()->assertJsonPath('pagination.total', 0)->assertJsonCount(0, 'data.logs');

    $all = $this->getJson('/api/v1/manager/activity-logs', alEdgeHeaders($manager));
    $all->assertOk()->assertJsonPath('pagination.total', 1);
    expect(collect($all->json('data.logs'))->pluck('id')->all())->toBe([$own->id]);

    $superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'team_id' => null]);
    $this->app['auth']->forgetGuards();
    $superView = $this->getJson('/api/v1/manager/activity-logs', alEdgeHeaders($superAdmin));
    $superView->assertOk()->assertJsonPath('pagination.total', 2);
    expect(collect($superView->json('data.logs'))->pluck('id')->all())->toContain($own->id, $foreign->id);
});

it('paginates with allowed page sizes and falls back to 10 otherwise', function (string $perPage, int $expectedPerPage, int $expectedLastPage, int $expectedCount) {
    $manager = $this->createManager();
    $employee = $this->createEmployee();
    foreach (range(1, 12) as $i) {
        $this->createActivityLog(['user_id' => $employee->id, 'created_at' => Carbon::parse('2025-01-01')->addMinutes($i)]);
    }

    $this->getJson("/api/v1/manager/activity-logs?per_page={$perPage}", alEdgeHeaders($manager))
        ->assertOk()
        ->assertJsonPath('pagination.per_page', $expectedPerPage)
        ->assertJsonPath('pagination.last_page', $expectedLastPage)
        ->assertJsonPath('pagination.total', 12)
        ->assertJsonCount($expectedCount, 'data.logs');
})->with([
    'default size 10' => ['10', 10, 2, 10],
    'size 50' => ['50', 50, 1, 12],
    'size 100' => ['100', 100, 1, 12],
    'unsupported 7 falls back' => ['7', 10, 2, 10],
    'unsupported 25 falls back' => ['25', 10, 2, 10],
    'garbage falls back' => ['lots', 10, 2, 10],
]);

it('returns an empty page with null range markers when there are no logs', function () {
    $manager = $this->createManager();

    $this->getJson('/api/v1/manager/activity-logs', alEdgeHeaders($manager))
        ->assertOk()
        ->assertJsonPath('pagination.total', 0)
        ->assertJsonPath('pagination.from', null)
        ->assertJsonPath('pagination.to', null)
        ->assertJsonCount(0, 'data.logs');
});

it('rejects a malformed or impossible date filter with a 422 instead of crashing', function (string $query, string $field) {
    $manager = $this->createManager();
    $headers = alEdgeHeaders($manager);
    Log::spy();
    $this->mock(ActivityLogRepository::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('getPaginatedWithFilters');
    });

    $response = $this->getJson("/api/v1/manager/activity-logs?{$query}", $headers);

    $response->assertUnprocessable()->assertJsonValidationErrors([$field]);
    Log::shouldNotHaveReceived('error');
})->with([
    'start date is not a date' => ['start_date=not-a-date', 'start_date'],
    'end date is not a date' => ['end_date=tomorrow', 'end_date'],
    'start date has the wrong format' => ['start_date=10-03-2025', 'start_date'],
    'end date has a time part' => ['end_date=2025-03-10T10:00:00', 'end_date'],
    'start date does not exist on the calendar' => ['start_date=2025-02-30', 'start_date'],
    'start date is an array' => ['start_date[]=2025-03-10', 'start_date'],
    'end date is before the start date' => ['start_date=2025-03-12&end_date=2025-03-10', 'end_date'],
]);

it('explains an invalid activity log date filter in Indonesian', function () {
    $manager = $this->createManager();
    $headers = alEdgeHeaders($manager);

    $this->getJson('/api/v1/manager/activity-logs?start_date=garbage', $headers)
        ->assertUnprocessable()
        ->assertJsonPath('errors.start_date.0', 'Tanggal mulai harus berformat YYYY-MM-DD.');

    $this->getJson('/api/v1/manager/activity-logs?end_date=garbage', $headers)
        ->assertUnprocessable()
        ->assertJsonPath('errors.end_date.0', 'Tanggal selesai harus berformat YYYY-MM-DD.');

    $this->getJson('/api/v1/manager/activity-logs?start_date=2025-03-12&end_date=2025-03-10', $headers)
        ->assertUnprocessable()
        ->assertJsonPath('errors.end_date.0', 'Tanggal selesai harus sama dengan atau setelah tanggal mulai.');
});

it('treats blank activity log date filters as not provided', function () {
    $manager = $this->createManager();
    $log = $this->createActivityLog(['user_id' => $manager->id, 'created_at' => '2025-03-10 12:00:00']);

    $this->getJson('/api/v1/manager/activity-logs?start_date=&end_date=', alEdgeHeaders($manager))
        ->assertOk()
        ->assertJsonPath('pagination.total', 1)
        ->assertJsonPath('data.logs.0.id', $log->id);
});

it('returns a 500 json error and logs when fetching activity logs fails', function () {
    $manager = $this->createManager();
    $headers = alEdgeHeaders($manager);
    Log::spy();
    $this->mock(ActivityLogRepository::class, function (MockInterface $mock) {
        $mock->shouldReceive('getPaginatedWithFilters')->once()->andThrow(new RuntimeException('query exploded'));
    });

    $this->getJson('/api/v1/manager/activity-logs', $headers)
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Gagal mengambil log aktivitas']);

    Log::shouldHaveReceived('error')->with('Get activity logs failed: query exploded')->once();
});

it('rejects unauthenticated and employee access to activity logs', function () {
    $employee = $this->createEmployee();
    $this->createActivityLog(['user_id' => $employee->id]);

    $this->getJson('/api/v1/manager/activity-logs')->assertUnauthorized();
    $this->getJson('/api/v1/manager/activity-logs', alEdgeHeaders($employee))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Employee report
|--------------------------------------------------------------------------
*/

it('defaults the personal report to the start of the month through today', function () {
    $this->travelTo(Carbon::parse('2026-03-18 12:00:00'));
    $employee = $this->createEmployee();
    $canned = ['summary' => ['working_days' => 15], 'attendances' => []];
    $this->mock(ReportService::class, function (MockInterface $mock) use ($employee, $canned) {
        $mock->shouldReceive('getEmployeeReport')
            ->once()
            ->withArgs(fn (User $user, Carbon $start, Carbon $end) => $user->is($employee)
                && $start->toDateString() === '2026-03-01'
                && $end->toDateString() === '2026-03-18')
            ->andReturn($canned);
    });

    $this->getJson('/api/v1/reports/my-report', alEdgeHeaders($employee))
        ->assertOk()
        ->assertExactJson(['success' => true, 'data' => $canned]);
});

it('passes explicit and partial date filters through to the report service', function () {
    $this->travelTo(Carbon::parse('2026-03-18 12:00:00'));
    $employee = $this->createEmployee();
    $seen = [];
    $this->mock(ReportService::class, function (MockInterface $mock) use (&$seen) {
        $mock->shouldReceive('getEmployeeReport')
            ->twice()
            ->andReturnUsing(function (User $user, Carbon $start, Carbon $end) use (&$seen) {
                $seen[] = [$start->toDateString(), $end->toDateString()];

                return ['summary' => [], 'attendances' => []];
            });
    });
    $headers = alEdgeHeaders($employee);

    $this->getJson('/api/v1/reports/my-report?start_date=2025-12-01&end_date=2025-12-31', $headers)->assertOk();
    $this->getJson('/api/v1/reports/my-report?start_date=2026-02-10', $headers)->assertOk();

    expect($seen)->toBe([
        ['2025-12-01', '2025-12-31'],
        ['2026-02-10', '2026-03-18'],
    ]);
});

it('builds the personal report from the caller attendance within the default range only', function () {
    $this->travelTo(Carbon::parse('2026-03-18 12:00:00'));
    $employee = $this->createEmployee();
    $colleague = $this->createEmployee();

    // In range: an incomplete day (4h < 7h required) and an overtime day (8h).
    Attendance::factory()->create([
        'user_id' => $employee->id,
        'check_in' => '2026-03-02 09:00:00',
        'check_out' => '2026-03-02 13:00:00',
        'date' => '2026-03-02',
        'total_hours' => 4.0,
    ]);
    Attendance::factory()->create([
        'user_id' => $employee->id,
        'check_in' => '2026-03-17 09:00:00',
        'check_out' => '2026-03-17 17:00:00',
        'date' => '2026-03-17',
        'total_hours' => 8.0,
    ]);
    // Out of range (previous month) and somebody else's attendance.
    Attendance::factory()->create(['user_id' => $employee->id, 'date' => '2026-02-27', 'total_hours' => 8.0]);
    Attendance::factory()->create(['user_id' => $colleague->id, 'date' => '2026-03-17', 'total_hours' => 8.0]);

    $response = $this->getJson('/api/v1/reports/my-report', alEdgeHeaders($employee));

    // 1-18 March 2026 has 18 days of which three are Sundays (1st, 8th, 15th).
    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.summary.working_days', 15)
        ->assertJsonPath('data.summary.expected_hours', 120)
        ->assertJsonPath('data.summary.total_days_worked', 2)
        ->assertJsonPath('data.summary.total_hours', 12)
        ->assertJsonPath('data.summary.incomplete_days', 1)
        ->assertJsonPath('data.summary.overtime_hours', 0)
        ->assertJsonPath('data.summary.deficit_hours', 108)
        ->assertJsonCount(2, 'data.attendances')
        ->assertJsonPath('data.attendances.0.date', '2026-03-17')
        ->assertJsonPath('data.attendances.0.status', 'overtime')
        ->assertJsonPath('data.attendances.1.date', '2026-03-02')
        ->assertJsonPath('data.attendances.1.status', 'incomplete');
});

it('rejects a malformed or impossible date filter on the personal report with a 422', function (string $query, string $field) {
    $employee = $this->createEmployee();
    $headers = alEdgeHeaders($employee);
    Log::spy();
    $this->mock(ReportService::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('getEmployeeReport');
    });

    $response = $this->getJson("/api/v1/reports/my-report?{$query}", $headers);

    $response->assertUnprocessable()->assertJsonValidationErrors([$field]);
    Log::shouldNotHaveReceived('error');
})->with([
    'start date is not a date' => ['start_date=not-a-date', 'start_date'],
    'end date is not a date' => ['end_date=next+friday', 'end_date'],
    'start date has the wrong format' => ['start_date=01%2F03%2F2026', 'start_date'],
    'end date has a time part' => ['end_date=2026-03-10+10:00:00', 'end_date'],
    'end date does not exist on the calendar' => ['end_date=2026-02-31', 'end_date'],
    'end date is an array' => ['end_date[]=2026-03-10', 'end_date'],
    'end date is before the start date' => ['start_date=2026-03-12&end_date=2026-03-10', 'end_date'],
]);

it('falls back to the default range when the personal report filters are blank', function () {
    $this->travelTo(Carbon::parse('2026-03-18 12:00:00'));
    $employee = $this->createEmployee();
    $this->mock(ReportService::class, function (MockInterface $mock) {
        $mock->shouldReceive('getEmployeeReport')
            ->once()
            ->withArgs(fn (User $user, Carbon $start, Carbon $end) => $start->toDateString() === '2026-03-01'
                && $end->toDateString() === '2026-03-18')
            ->andReturn(['summary' => [], 'attendances' => []]);
    });

    $this->getJson('/api/v1/reports/my-report?start_date=&end_date=', alEdgeHeaders($employee))->assertOk();
});

it('returns a 500 json error and logs when the personal report fails', function () {
    $employee = $this->createEmployee();
    $headers = alEdgeHeaders($employee);
    Log::spy();
    $this->mock(ReportService::class, function (MockInterface $mock) {
        $mock->shouldReceive('getEmployeeReport')->once()->andThrow(new RuntimeException('report broke'));
    });

    $this->getJson('/api/v1/reports/my-report', $headers)
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Failed to get report']);

    Log::shouldHaveReceived('error')->with('Get employee report failed: report broke')->once();
});
