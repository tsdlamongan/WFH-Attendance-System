<?php

use App\Enums\LeaveStatus;
use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\Task;
use App\Models\User;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

/**
 * Bearer header for a user. Guards are reset so several actors can be used in one test.
 */
function mrecAuth(User $user): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer '.$user->createToken('auth-token')->plainTextToken];
}

/**
 * Create a completed attendance session for a user on a given day.
 */
function mrecAttendance(User $user, string $date, string $in = '09:00:00', string $out = '17:00:00', float $hours = 8.0): Attendance
{
    return Attendance::factory()->create([
        'user_id' => $user->id,
        'date' => $date,
        'check_in' => Carbon::parse("{$date} {$in}"),
        'check_out' => Carbon::parse("{$date} {$out}"),
        'total_hours' => $hours,
    ]);
}

beforeEach(function () {
    $this->manager = $this->createManager();
    $this->alice = $this->createEmployee(['name' => 'Alice']);
    $this->bob = $this->createEmployee(['name' => 'Bob']);

    $this->otherEmployee = User::factory()->create(['role' => 'employee', 'name' => 'Zed Outsider']);
});

dataset('manager report endpoints', [
    'dashboard' => ['/api/v1/manager/dashboard'],
    'daily attendance' => ['/api/v1/manager/reports/daily-attendance'],
    'monthly attendance' => ['/api/v1/manager/reports/monthly-attendance'],
    'check-in time' => ['/api/v1/manager/reports/check-in-time'],
]);

describe('access control', function () {
    it('requires authentication on every report endpoint', function (string $url) {
        $this->getJson($url)->assertUnauthorized();
    })->with('manager report endpoints');

    it('forbids employees on every report endpoint', function (string $url) {
        $this->getJson($url, mrecAuth($this->alice))->assertForbidden();
    })->with('manager report endpoints');

    it('forbids employees and guests on the employee report', function () {
        $url = "/api/v1/manager/reports/employee/{$this->bob->id}";

        $this->getJson($url)->assertUnauthorized();
        $this->getJson($url, mrecAuth($this->alice))->assertForbidden();
    });
});

describe('validation handling', function () {
    it('answers invalid query parameters with a 422 instead of a 500', function (string $url, string $field) {
        Log::spy();
        $this->mock(ReportService::class, function ($mock) {
            $mock->shouldNotReceive('getManagerDashboard', 'getDailyAttendanceReport', 'getMonthlyAttendanceReport', 'getCheckInTimeReport', 'getEmployeeReportForManager');
        });

        $response = $this->getJson($url, mrecAuth($this->manager));

        $response->assertUnprocessable()->assertJsonValidationErrors([$field]);
        Log::shouldNotHaveReceived('error');
    })->with([
        'dashboard date' => ['/api/v1/manager/dashboard?date=20-03-2030', 'date'],
        'daily date' => ['/api/v1/manager/reports/daily-attendance?date=tomorrow', 'date'],
        'monthly start date' => ['/api/v1/manager/reports/monthly-attendance?start_date=nope', 'start_date'],
        'monthly range' => ['/api/v1/manager/reports/monthly-attendance?start_date=2030-03-10&end_date=2030-03-09', 'end_date'],
        'check-in start date' => ['/api/v1/manager/reports/check-in-time?start_date=nope', 'start_date'],
        'check-in range' => ['/api/v1/manager/reports/check-in-time?start_date=2030-03-10&end_date=2030-03-09', 'end_date'],
        'employee start date' => ['/api/v1/manager/reports/employee/1?start_date=nope', 'start_date'],
        'employee range' => ['/api/v1/manager/reports/employee/1?start_date=2030-03-10&end_date=2030-03-09', 'end_date'],
    ]);

    it('returns 404 instead of a 500 when the employee does not exist', function () {
        Log::spy();

        $response = $this->getJson('/api/v1/manager/reports/employee/999999', mrecAuth($this->manager));

        $response->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Employee not found']);
        Log::shouldNotHaveReceived('error');
    });
});

describe('failure handling', function () {
    it('returns a generic 500 and logs when the report service fails', function (string $url, string $method, string $message, string $logPrefix) {
        Log::spy();
        $this->mock(ReportService::class, function ($mock) use ($method) {
            $mock->shouldReceive($method)->once()->andThrow(new RuntimeException('report exploded'));
        });

        $response = $this->getJson($url, mrecAuth($this->manager));

        $response->assertStatus(500)->assertExactJson(['success' => false, 'message' => $message]);
        expect($response->getContent())->not->toContain('report exploded');
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($logged) => str_contains($logged, $logPrefix.' failed: report exploded'))
            ->once();
    })->with([
        'dashboard' => ['/api/v1/manager/dashboard', 'getManagerDashboard', 'Failed to get dashboard', 'Get manager dashboard'],
        'daily attendance' => ['/api/v1/manager/reports/daily-attendance', 'getDailyAttendanceReport', 'Failed to get daily attendance report', 'Get daily attendance report'],
        'monthly attendance' => ['/api/v1/manager/reports/monthly-attendance', 'getMonthlyAttendanceReport', 'Failed to get monthly attendance report', 'Get monthly attendance report'],
        'check-in time' => ['/api/v1/manager/reports/check-in-time', 'getCheckInTimeReport', 'Failed to get check-in time report', 'Get check-in time report'],
    ]);

    it('returns a generic 500 when the employee report service fails', function () {
        Log::spy();
        $this->mock(ReportService::class, function ($mock) {
            $mock->shouldReceive('getEmployeeReportForManager')->once()->andThrow(new RuntimeException('report exploded'));
        });

        $response = $this->getJson("/api/v1/manager/reports/employee/{$this->alice->id}", mrecAuth($this->manager));

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Failed to get employee report']);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($logged) => str_contains($logged, 'Get employee report failed: report exploded'))
            ->once();
    });
});

describe('dashboard', function () {
    it('only counts employees of the managers own team', function () {
        mrecAttendance($this->alice, '2030-03-20');
        mrecAttendance($this->otherEmployee, '2030-03-20');

        $response = $this->getJson('/api/v1/manager/dashboard?date=2030-03-20', mrecAuth($this->manager));

        $response->assertOk()->assertJsonPath('data.summary.total_employees', 2);
        $names = collect($response->json('data.employees'))->pluck('name')->sort()->values()->all();
        expect($names)->toBe(['Alice', 'Bob'])
            ->and($names)->not->toContain('Zed Outsider');
    });

    it('reports hours for the requested date and defaults to today', function () {
        $this->travelTo(Carbon::parse('2030-03-20 12:00:00'));
        mrecAttendance($this->alice, '2030-03-20', hours: 5.5);
        mrecAttendance($this->alice, '2030-03-19', hours: 8.0);

        $today = $this->getJson('/api/v1/manager/dashboard', mrecAuth($this->manager))->assertOk();
        $yesterday = $this->getJson('/api/v1/manager/dashboard?date=2030-03-19', mrecAuth($this->manager))->assertOk();

        $find = fn ($response) => collect($response->json('data.employees'))->firstWhere('name', 'Alice');
        expect($find($today)['today_total_hours'])->toBe(5.5)
            ->and($find($yesterday)['today_total_hours'])->toEqual(8)
            ->and($find($today)['week_total_hours'])->toBe(13.5);
    });
});

describe('employee report', function () {
    it('returns 403 for an employee that belongs to another team', function () {
        $response = $this->getJson(
            "/api/v1/manager/reports/employee/{$this->otherEmployee->id}",
            mrecAuth($this->manager)
        );

        $response->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Unauthorized to view this employee report']);
    });

    it('restricts the report to the requested date range', function () {
        mrecAttendance($this->alice, '2030-03-05');
        mrecAttendance($this->alice, '2030-03-06', hours: 6.0);
        mrecAttendance($this->alice, '2030-04-02');

        $response = $this->getJson(
            "/api/v1/manager/reports/employee/{$this->alice->id}?start_date=2030-03-01&end_date=2030-03-31",
            mrecAuth($this->manager)
        );

        $response->assertOk()
            ->assertJsonPath('data.summary.total_days_worked', 2)
            ->assertJsonPath('data.summary.total_hours', 14)
            ->assertJsonPath('data.summary.working_days', 26);
        expect(collect($response->json('data.attendances'))->pluck('date')->all())
            ->toBe(['2030-03-06', '2030-03-05']);
    });

    it('includes an attendance on the last day of the range', function () {
        mrecAttendance($this->alice, '2030-03-31');

        $response = $this->getJson(
            "/api/v1/manager/reports/employee/{$this->alice->id}?start_date=2030-03-31&end_date=2030-03-31",
            mrecAuth($this->manager)
        );

        $response->assertOk()->assertJsonPath('data.summary.total_days_worked', 1);
    });

    it('defaults to the start of the current month up to now', function () {
        $this->travelTo(Carbon::parse('2030-03-20 12:00:00'));
        mrecAttendance($this->alice, '2030-03-05');
        mrecAttendance($this->alice, '2030-03-25');
        mrecAttendance($this->alice, '2030-02-27');

        $response = $this->getJson("/api/v1/manager/reports/employee/{$this->alice->id}", mrecAuth($this->manager));

        $response->assertOk()->assertJsonPath('data.summary.total_days_worked', 1);
        expect(collect($response->json('data.attendances'))->pluck('date')->all())->toBe(['2030-03-05']);
    });

    it('counts approved leave and ignores weekends and holidays', function () {
        $team = $this->manager->team_id;
        // 2030-03-04 is a Monday; the leave covers Mon-Sun, one of those days is a holiday.
        Holiday::factory()->create(['team_id' => $team, 'date' => '2030-03-06', 'name' => 'Midweek']);
        Leave::factory()->create([
            'user_id' => $this->alice->id,
            'start_date' => '2030-03-04',
            'end_date' => '2030-03-10',
            'status' => LeaveStatus::APPROVED,
        ]);

        $response = $this->getJson(
            "/api/v1/manager/reports/employee/{$this->alice->id}?start_date=2030-03-04&end_date=2030-03-10",
            mrecAuth($this->manager)
        );

        // Mon, Tue, Thu, Fri, Sat count; Wed is a holiday and Sun is skipped.
        $response->assertOk()
            ->assertJsonPath('data.summary.total_leave_days', 5)
            ->assertJsonPath('data.summary.working_days', 5)
            ->assertJsonPath('data.summary.total_hours', 40);
    });
});

describe('daily attendance report', function () {
    it('reports the requested date for the own team only', function () {
        mrecAttendance($this->alice, '2030-03-20', hours: 8.0);
        mrecAttendance($this->otherEmployee, '2030-03-20', hours: 8.0);

        $response = $this->getJson(
            '/api/v1/manager/reports/daily-attendance?date=2030-03-20',
            mrecAuth($this->manager)
        );

        $response->assertOk()->assertJsonPath('data.date', '2030-03-20');
        $names = collect($response->json('data.employees'))->pluck('employee.name')->all();
        expect($names)->toBe(['Alice']);
    });

    it('defaults to today when no date is supplied', function () {
        $this->travelTo(Carbon::parse('2030-07-09 11:00:00'));
        mrecAttendance($this->bob, '2030-07-09', hours: 7.5);

        $response = $this->getJson('/api/v1/manager/reports/daily-attendance', mrecAuth($this->manager));

        $response->assertOk()
            ->assertJsonPath('data.date', '2030-07-09')
            ->assertJsonPath('data.employees.0.employee.name', 'Bob')
            ->assertJsonPath('data.employees.0.status', 'overtime');
    });

    it('classifies a short day as incomplete and lists its tasks', function () {
        $attendance = mrecAttendance($this->alice, '2030-03-20', hours: 3.0);
        Task::factory()->create(['attendance_id' => $attendance->id, 'title' => 'Half done', 'is_completed' => true]);
        Task::factory()->create(['attendance_id' => $attendance->id, 'title' => 'Blocked', 'blocker_reason' => 'waiting']);

        $response = $this->getJson(
            '/api/v1/manager/reports/daily-attendance?date=2030-03-20',
            mrecAuth($this->manager)
        );

        $response->assertOk()
            ->assertJsonPath('data.employees.0.status', 'incomplete')
            ->assertJsonPath('data.employees.0.sessions.0.tasks_completed', 1)
            ->assertJsonPath('data.employees.0.sessions.0.tasks_incomplete', 1);
    });
});

describe('monthly attendance report', function () {
    it('defaults to the current calendar month', function () {
        $this->travelTo(Carbon::parse('2030-03-20 12:00:00'));
        mrecAttendance($this->alice, '2030-03-01');
        mrecAttendance($this->alice, '2030-03-31');
        mrecAttendance($this->alice, '2030-02-28');
        mrecAttendance($this->alice, '2030-04-01');

        $response = $this->getJson('/api/v1/manager/reports/monthly-attendance', mrecAuth($this->manager));

        $response->assertOk()
            ->assertJsonPath('data.start_date', '2030-03-01')
            ->assertJsonPath('data.end_date', '2030-03-31')
            ->assertJsonPath('data.working_days', 26);
        $alice = collect($response->json('data.employees'))->firstWhere('employee.name', 'Alice');
        expect($alice['total_days_worked'])->toBe(2)
            ->and($alice['total_hours'])->toBe(16);
    });

    it('honours an explicit range and excludes other teams', function () {
        mrecAttendance($this->alice, '2030-05-10');
        mrecAttendance($this->alice, '2030-05-20');
        mrecAttendance($this->otherEmployee, '2030-05-10');

        $response = $this->getJson(
            '/api/v1/manager/reports/monthly-attendance?start_date=2030-05-01&end_date=2030-05-15',
            mrecAuth($this->manager)
        );

        $response->assertOk()
            ->assertJsonPath('data.start_date', '2030-05-01')
            ->assertJsonPath('data.end_date', '2030-05-15');
        $employees = collect($response->json('data.employees'));
        expect($employees->pluck('employee.name')->sort()->values()->all())->toBe(['Alice', 'Bob'])
            ->and($employees->firstWhere('employee.name', 'Alice')['total_days_worked'])->toBe(1);
    });
});

describe('check-in time report', function () {
    it('defaults to the current month and uses the default window', function () {
        $this->travelTo(Carbon::parse('2030-03-20 12:00:00'));
        mrecAttendance($this->alice, '2030-03-04', in: '09:30:00', out: '17:30:00');
        mrecAttendance($this->alice, '2030-03-05', in: '10:45:00', out: '18:45:00');
        mrecAttendance($this->alice, '2030-02-27', in: '09:30:00', out: '17:30:00');

        $response = $this->getJson('/api/v1/manager/reports/check-in-time', mrecAuth($this->manager));

        $response->assertOk()
            ->assertJsonPath('data.start_date', '2030-03-01')
            ->assertJsonPath('data.end_date', '2030-03-31')
            ->assertJsonPath('data.window_start', '09:00')
            ->assertJsonPath('data.window_end', '10:00')
            ->assertJsonCount(1, 'data.employees')
            ->assertJsonPath('data.employees.0.employee.name', 'Alice')
            ->assertJsonPath('data.employees.0.total_days', 2)
            ->assertJsonPath('data.employees.0.on_time_days', 1)
            ->assertJsonPath('data.employees.0.late_days', 1)
            ->assertJsonPath('data.employees.0.consistency_rate', 50);
    });

    it('uses the teams own check-in window and skips other teams', function () {
        $this->manager->team->update([
            'check_in_window_start' => '07:00:00',
            'check_in_window_end' => '08:00:00',
        ]);
        mrecAttendance($this->alice, '2030-05-06', in: '07:30:00', out: '15:30:00');
        mrecAttendance($this->otherEmployee, '2030-05-06', in: '07:30:00', out: '15:30:00');

        $response = $this->getJson(
            '/api/v1/manager/reports/check-in-time?start_date=2030-05-01&end_date=2030-05-31',
            mrecAuth($this->manager)
        );

        $response->assertOk()
            ->assertJsonPath('data.window_start', '07:00')
            ->assertJsonPath('data.window_end', '08:00')
            ->assertJsonCount(1, 'data.employees')
            ->assertJsonPath('data.employees.0.employee.name', 'Alice')
            ->assertJsonPath('data.employees.0.consistency_rate', 100);
    });
});
