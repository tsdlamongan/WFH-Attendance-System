<?php

use App\Enums\ActivityType;
use App\Enums\LeaveStatus;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Leave;
use App\Models\LeaveQuota;
use App\Models\Task;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\LeaveService;
use App\Services\TaskService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

/**
 * Bearer header for a user. Guards are reset so several actors can be used in one test.
 */
function tlecAuth(User $user): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer '.$user->createToken('auth-token')->plainTextToken];
}

/**
 * Create an attendance session (open by default) for a user.
 */
function tlecSession(User $user, ?string $checkOut = null, string $date = '2030-01-15', string $checkIn = '10:00:00'): Attendance
{
    return Attendance::factory()->create([
        'user_id' => $user->id,
        'date' => $date,
        'check_in' => Carbon::parse("{$date} {$checkIn}"),
        'check_out' => $checkOut ? Carbon::parse("{$date} {$checkOut}") : null,
        'total_hours' => 0,
    ]);
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2030-01-15 12:00:00'));

    $this->manager = $this->createManager();
    $this->employee = $this->createEmployee(['name' => 'Alice Employee']);
    $this->colleague = $this->createEmployee(['name' => 'Bob Colleague']);

    // A separate team with its own employee.
    $this->otherEmployee = User::factory()->create(['role' => 'employee']);
});

afterEach(function () {
    Task::flushEventListeners();
    Leave::flushEventListeners();
});

/*
|--------------------------------------------------------------------------
| ManagerTaskController
|--------------------------------------------------------------------------
*/
describe('manager task update', function () {
    beforeEach(function () {
        $this->session = tlecSession($this->employee);
        $this->task = Task::factory()->create([
            'attendance_id' => $this->session->id,
            'title' => 'Write report',
            'is_completed' => false,
            'blocker_reason' => 'Waiting for data',
        ]);
    });

    it('returns 403 and leaves a task from another team untouched', function () {
        $otherTask = Task::factory()->create([
            'attendance_id' => tlecSession($this->otherEmployee)->id,
            'is_completed' => false,
            'blocker_reason' => 'original reason',
        ]);

        $response = $this->putJson("/api/v1/manager/tasks/{$otherTask->id}", [
            'is_completed' => true,
        ], tlecAuth($this->manager));

        $response->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Unauthorized to update this task']);
        $fresh = $otherTask->fresh();
        expect($fresh->is_completed)->toBeFalse()
            ->and($fresh->blocker_reason)->toBe('original reason');
    });

    it('returns 404 for a task that does not exist', function () {
        $this->putJson('/api/v1/manager/tasks/999999', ['is_completed' => true], tlecAuth($this->manager))
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Task not found']);
    });

    it('rejects a blocker reason longer than 500 characters', function () {
        $response = $this->putJson("/api/v1/manager/tasks/{$this->task->id}", [
            'is_completed' => false,
            'blocker_reason' => str_repeat('x', 501),
        ], tlecAuth($this->manager));

        $response->assertUnprocessable()->assertJsonValidationErrors(['blocker_reason']);
        expect($response->json('errors.blocker_reason.0'))->toBe('Alasan kendala maksimal 500 karakter.')
            ->and($this->task->fresh()->blocker_reason)->toBe('Waiting for data');
    });

    it('accepts a blocker reason of exactly 500 characters', function () {
        $reason = str_repeat('x', 500);

        $this->putJson("/api/v1/manager/tasks/{$this->task->id}", [
            'is_completed' => false,
            'blocker_reason' => $reason,
        ], tlecAuth($this->manager))->assertOk();

        expect($this->task->fresh()->blocker_reason)->toBe($reason);
    });

    it('accepts boolean-like values for is_completed', function (mixed $value, bool $expectCompleted) {
        $this->putJson("/api/v1/manager/tasks/{$this->task->id}", [
            'is_completed' => $value,
            'blocker_reason' => 'Still blocked',
        ], tlecAuth($this->manager))->assertOk();

        expect($this->task->fresh()->is_completed)->toBe($expectCompleted);
    })->with([
        'integer one' => [1, true],
        'integer zero' => [0, false],
        'string zero' => ['0', false],
    ]);

    it('logs the status transition with the employee name', function () {
        $this->putJson("/api/v1/manager/tasks/{$this->task->id}", [
            'is_completed' => true,
        ], tlecAuth($this->manager))->assertOk();

        $log = ActivityLog::where('action', ActivityType::TASK_UPDATED->value)
            ->where('description', 'like', 'Manager updated task%')
            ->first();

        expect($log)->not->toBeNull()
            ->and($log->user_id)->toBe($this->manager->id)
            ->and($log->description)->toBe(
                "Manager updated task 'Write report' for Alice Employee. Status changed from incomplete to completed"
            );
    });

    it('logs the reverse transition when a completed task is reopened', function () {
        $this->task->update(['is_completed' => true, 'blocker_reason' => null]);

        $this->putJson("/api/v1/manager/tasks/{$this->task->id}", [
            'is_completed' => false,
            'blocker_reason' => 'Regression found',
        ], tlecAuth($this->manager))->assertOk();

        $log = ActivityLog::where('description', 'like', 'Manager updated task%')->first();
        expect($log->description)->toContain('Status changed from completed to incomplete')
            ->and($this->task->fresh()->blocker_reason)->toBe('Regression found');
    });

    it('returns a 500 and keeps the task unchanged when saving fails', function () {
        Log::spy();
        Task::updating(function () {
            throw new RuntimeException('row locked');
        });

        $response = $this->putJson("/api/v1/manager/tasks/{$this->task->id}", [
            'is_completed' => true,
        ], tlecAuth($this->manager));

        $response->assertStatus(500)->assertJson(['success' => false]);
        expect($response->json('message'))->toStartWith('Failed to update task');
        $fresh = $this->task->fresh();
        expect($fresh->is_completed)->toBeFalse()
            ->and($fresh->blocker_reason)->toBe('Waiting for data');
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Update task failed: row locked'))
            ->once();
    });
});

/*
|--------------------------------------------------------------------------
| LeaveController
|--------------------------------------------------------------------------
*/
describe('employee leave requests', function () {
    $payload = fn (array $overrides = []) => array_merge([
        'start_date' => '2030-02-04',
        'end_date' => '2030-02-05',
        'reason' => 'Family event out of town',
    ], $overrides);

    it('requires authentication on all leave endpoints', function () use ($payload) {
        $this->postJson('/api/v1/leaves', $payload())->assertUnauthorized();
        $this->getJson('/api/v1/leaves/my-requests')->assertUnauthorized();
        $this->getJson('/api/v1/leaves/summary')->assertUnauthorized();
    });

    it('submits a pending request and stores it for the caller', function () use ($payload) {
        $response = $this->postJson('/api/v1/leaves', $payload(), tlecAuth($this->employee));

        $response->assertCreated()
            ->assertJsonPath('message', 'Leave request submitted successfully')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.days', 2)
            ->assertJsonPath('data.user_id', $this->employee->id);

        $this->assertDatabaseHas('leaves', [
            'user_id' => $this->employee->id,
            'reason' => 'Family event out of town',
            'status' => LeaveStatus::PENDING->value,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->employee->id,
            'action' => ActivityType::LEAVE_REQUESTED->value,
        ]);
    });

    it('accepts a request that starts today and a reason of boundary length', function (string $reason) use ($payload) {
        $response = $this->postJson('/api/v1/leaves', $payload([
            'start_date' => '2030-01-15',
            'end_date' => '2030-01-15',
            'reason' => $reason,
        ]), tlecAuth($this->employee));

        $response->assertCreated()->assertJsonPath('data.days', 1);
    })->with([
        'minimum ten characters' => [str_repeat('r', 10)],
        'maximum 500 characters' => [str_repeat('r', 500)],
    ]);

    it('validates the request body', function (array $overrides, array $errorKeys) use ($payload) {
        $response = $this->postJson('/api/v1/leaves', $payload($overrides), tlecAuth($this->employee));

        $response->assertUnprocessable()->assertJsonValidationErrors($errorKeys);
        expect(Leave::where('user_id', $this->employee->id)->count())->toBe(0);
    })->with([
        'start in the past' => [['start_date' => '2030-01-14', 'end_date' => '2030-01-16'], ['start_date']],
        'end before start' => [['start_date' => '2030-02-05', 'end_date' => '2030-02-04'], ['end_date']],
        'reason one char short' => [['reason' => str_repeat('r', 9)], ['reason']],
        'reason too long' => [['reason' => str_repeat('r', 501)], ['reason']],
        'invalid start date' => [['start_date' => 'next week'], ['start_date']],
        'missing end date' => [['end_date' => null], ['end_date']],
        'missing reason' => [['reason' => null], ['reason']],
    ]);

    it('returns the service error message as a 422 when a business rule fails', function () use ($payload) {
        $this->postJson('/api/v1/leaves', $payload(), tlecAuth($this->employee))->assertCreated();

        $response = $this->postJson('/api/v1/leaves', $payload(['end_date' => '2030-02-04']), tlecAuth($this->employee));

        $response->assertUnprocessable()->assertJson([
            'success' => false,
            'message' => 'Anda sudah memiliki pengajuan cuti untuk rentang tanggal ini.',
        ]);
        expect(Leave::where('user_id', $this->employee->id)->count())->toBe(1);
    });

    it('lists only the callers requests, newest first', function () {
        $older = Leave::factory()->create([
            'user_id' => $this->employee->id,
            'created_at' => Carbon::now()->subDays(3),
        ]);
        $newer = Leave::factory()->create([
            'user_id' => $this->employee->id,
            'created_at' => Carbon::now()->subDay(),
        ]);
        $foreign = Leave::factory()->create(['user_id' => $this->colleague->id]);

        $response = $this->getJson('/api/v1/leaves/my-requests', tlecAuth($this->employee));

        $response->assertOk()->assertJson(['success' => true]);
        $ids = collect($response->json('data'))->pluck('id')->all();
        expect($ids)->toBe([$newer->id, $older->id])
            ->and($ids)->not->toContain($foreign->id)
            ->and($response->json('data.0.user.id'))->toBe($this->employee->id);
    });

    it('returns an empty list when the caller has no requests', function () {
        Leave::factory()->create(['user_id' => $this->colleague->id]);

        $this->getJson('/api/v1/leaves/my-requests', tlecAuth($this->employee))
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => []]);
    });

    it('returns a generic 500 when loading the requests fails', function () {
        Log::spy();
        Leave::factory()->create(['user_id' => $this->employee->id]);
        Leave::retrieved(function () {
            throw new RuntimeException('read failed');
        });

        $response = $this->getJson('/api/v1/leaves/my-requests', tlecAuth($this->employee));

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Failed to get leave requests']);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Get leave requests failed: read failed'))
            ->once();
    });

    it('summarises a requested year with its own quota, approved and pending days', function () {
        LeaveQuota::create(['user_id' => $this->employee->id, 'year' => 2031, 'quota_days' => 15]);
        Leave::factory()->create([
            'user_id' => $this->employee->id,
            'start_date' => '2031-03-03',
            'end_date' => '2031-03-05',
            'status' => LeaveStatus::APPROVED,
        ]);
        Leave::factory()->create([
            'user_id' => $this->employee->id,
            'start_date' => '2031-04-07',
            'end_date' => '2031-04-08',
            'status' => LeaveStatus::PENDING,
        ]);
        // Approved leave in the current year must not count towards 2031.
        Leave::factory()->create([
            'user_id' => $this->employee->id,
            'start_date' => '2030-06-03',
            'end_date' => '2030-06-04',
            'status' => LeaveStatus::APPROVED,
        ]);

        $response = $this->getJson('/api/v1/leaves/summary?year=2031', tlecAuth($this->employee));

        $response->assertOk()->assertExactJson([
            'success' => true,
            'data' => [
                'year' => 2031,
                'total_quota' => 15,
                'used_days' => 3,
                'pending_days' => 2,
                'remaining_days' => 10,
                'max_per_month' => 5,
            ],
        ]);
    });

    it('summarises the current year by default', function () {
        $response = $this->getJson('/api/v1/leaves/summary', tlecAuth($this->employee));

        $response->assertOk()
            ->assertJsonPath('data.year', 2030)
            ->assertJsonPath('data.used_days', 0)
            ->assertJsonPath('data.remaining_days', $this->employee->leave_quota_days);
    });

    it('rejects a non-integer or out of range year with a 422 instead of crashing', function (string $query) {
        Log::spy();
        $this->mock(LeaveService::class, function ($mock) {
            $mock->shouldNotReceive('getLeaveSummary');
        });

        $response = $this->getJson("/api/v1/leaves/summary?{$query}", tlecAuth($this->employee));

        $response->assertUnprocessable()->assertJsonValidationErrors(['year']);
        Log::shouldNotHaveReceived('error');
    })->with([
        'letters' => ['year=abc'],
        'decimal' => ['year=2030.5'],
        'negative' => ['year=-2030'],
        'before the supported range' => ['year=1999'],
        'after the supported range' => ['year=2101'],
        'array' => ['year[]=2030'],
    ]);

    it('accepts the boundaries of the supported year range', function (string $year) {
        $this->getJson("/api/v1/leaves/summary?year={$year}", tlecAuth($this->employee))
            ->assertOk()
            ->assertJsonPath('data.year', (int) $year)
            ->assertJsonPath('data.total_quota', $this->employee->leave_quota_days);
    })->with(['2000', '2100']);

    it('treats a blank year as the current year', function () {
        $this->getJson('/api/v1/leaves/summary?year=', tlecAuth($this->employee))
            ->assertOk()
            ->assertJsonPath('data.year', 2030);
    });

    it('never reports negative remaining days', function () {
        LeaveQuota::create(['user_id' => $this->employee->id, 'year' => 2030, 'quota_days' => 1]);
        Leave::factory()->create([
            'user_id' => $this->employee->id,
            'start_date' => '2030-03-04',
            'end_date' => '2030-03-06',
            'status' => LeaveStatus::APPROVED,
        ]);

        $this->getJson('/api/v1/leaves/summary', tlecAuth($this->employee))
            ->assertOk()
            ->assertJsonPath('data.used_days', 3)
            ->assertJsonPath('data.remaining_days', 0);
    });

    it('returns a generic 500 when the summary cannot be built', function () {
        Log::spy();
        $this->mock(LeaveService::class, function ($mock) {
            $mock->shouldReceive('getLeaveSummary')->once()->andThrow(new RuntimeException('quota table missing'));
        });

        $response = $this->getJson('/api/v1/leaves/summary', tlecAuth($this->employee));

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Failed to get leave summary']);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Get leave summary failed: quota table missing'))
            ->once();
    });
});

/*
|--------------------------------------------------------------------------
| TaskController
|--------------------------------------------------------------------------
*/
describe('employee tasks', function () {
    it('requires authentication and the employee role', function () {
        $this->postJson('/api/v1/tasks/add', [])->assertUnauthorized();
        $this->getJson('/api/v1/tasks/incomplete')->assertUnauthorized();
        $this->getJson('/api/v1/tasks/incomplete-last-session')->assertUnauthorized();

        $this->getJson('/api/v1/tasks/incomplete', tlecAuth($this->manager))->assertForbidden();
        $this->getJson('/api/v1/tasks/incomplete-last-session', tlecAuth($this->manager))->assertForbidden();
        $this->postJson('/api/v1/tasks/add', [], tlecAuth($this->manager))->assertForbidden();
    });

    it('adds tasks to the open session and returns them', function () {
        $session = tlecSession($this->employee);

        $response = $this->postJson('/api/v1/tasks/add', [
            'attendance_id' => $session->id,
            'tasks' => [['title' => 'Draft proposal'], ['title' => 'Review PR']],
        ], tlecAuth($this->employee));

        $response->assertCreated()
            ->assertJsonPath('message', 'Tasks added successfully')
            ->assertJsonCount(2, 'data.tasks');
        expect(collect($response->json('data.tasks'))->pluck('title')->all())->toBe(['Draft proposal', 'Review PR'])
            ->and(collect($response->json('data.tasks'))->every(fn ($t) => $t['is_completed'] === false))->toBeTrue();
        expect(Task::where('attendance_id', $session->id)->count())->toBe(2);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->employee->id,
            'action' => ActivityType::TASK_CREATED->value,
            'description' => 'User added 2 tasks to attendance session',
        ]);
    });

    it('validates the add tasks payload', function (callable $payload, array $errorKeys) {
        $session = tlecSession($this->employee);

        $response = $this->postJson('/api/v1/tasks/add', $payload($session), tlecAuth($this->employee));

        $response->assertUnprocessable()->assertJsonValidationErrors($errorKeys);
        expect(Task::count())->toBe(0);
    })->with([
        'unknown attendance' => [
            fn ($session) => ['attendance_id' => 999999, 'tasks' => [['title' => 'x']]],
            ['attendance_id'],
        ],
        'empty task list' => [
            fn ($session) => ['attendance_id' => $session->id, 'tasks' => []],
            ['tasks'],
        ],
        'tasks not an array' => [
            fn ($session) => ['attendance_id' => $session->id, 'tasks' => 'write code'],
            ['tasks'],
        ],
        'untitled task' => [
            fn ($session) => ['attendance_id' => $session->id, 'tasks' => [['title' => '']]],
            ['tasks.0.title'],
        ],
        'title too long' => [
            fn ($session) => ['attendance_id' => $session->id, 'tasks' => [['title' => str_repeat('t', 256)]]],
            ['tasks.0.title'],
        ],
    ]);

    it('reports a friendly unknown attendance message', function () {
        $response = $this->postJson('/api/v1/tasks/add', [
            'attendance_id' => 999999,
            'tasks' => [['title' => 'x']],
        ], tlecAuth($this->employee));

        expect($response->json('errors.attendance_id.0'))->toBe('Data absensi tidak ditemukan.');
    });

    it('refuses to add tasks to a colleagues session', function () {
        $session = tlecSession($this->colleague);

        $response = $this->postJson('/api/v1/tasks/add', [
            'attendance_id' => $session->id,
            'tasks' => [['title' => 'sneaky']],
        ], tlecAuth($this->employee));

        $response->assertUnprocessable()->assertJson([
            'success' => false,
            'message' => 'Attendance record not found or does not belong to you.',
        ]);
        expect(Task::where('attendance_id', $session->id)->count())->toBe(0);
    });

    it('lists only the callers incomplete tasks across all sessions', function () {
        $firstSession = tlecSession($this->employee, '17:00:00', '2030-01-10');
        $secondSession = tlecSession($this->employee, null, '2030-01-15');
        $mine1 = Task::factory()->create(['attendance_id' => $firstSession->id, 'title' => 'old open']);
        $mine2 = Task::factory()->create(['attendance_id' => $secondSession->id, 'title' => 'new open']);
        Task::factory()->create(['attendance_id' => $secondSession->id, 'is_completed' => true]);
        Task::factory()->create(['attendance_id' => tlecSession($this->colleague)->id]);

        $response = $this->getJson('/api/v1/tasks/incomplete', tlecAuth($this->employee));

        $response->assertOk()->assertJson(['success' => true]);
        expect(collect($response->json('data'))->pluck('id')->sort()->values()->all())
            ->toBe([$mine1->id, $mine2->id])
            ->and($response->json('data.0.attendance.id'))->not->toBeNull();
    });

    it('returns an empty list when nothing is incomplete', function () {
        Task::factory()->create([
            'attendance_id' => tlecSession($this->employee)->id,
            'is_completed' => true,
        ]);

        $this->getJson('/api/v1/tasks/incomplete', tlecAuth($this->employee))
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => []]);
    });

    it('returns a generic 500 when listing incomplete tasks fails', function () {
        Log::spy();
        $this->mock(TaskService::class, function ($mock) {
            $mock->shouldReceive('getIncompleteTasks')->once()->andThrow(new RuntimeException('index corrupt'));
        });

        $response = $this->getJson('/api/v1/tasks/incomplete', tlecAuth($this->employee));

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Failed to get incomplete tasks']);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Get incomplete tasks failed: index corrupt'))
            ->once();
    });

    it('returns a generic 500 when listing last session tasks fails', function () {
        Log::spy();
        $this->mock(TaskService::class, function ($mock) {
            $mock->shouldReceive('getIncompleteTasksFromLastSession')->once()->andThrow(new RuntimeException('cursor lost'));
        });

        $response = $this->getJson('/api/v1/tasks/incomplete-last-session', tlecAuth($this->employee));

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Failed to get incomplete tasks from last session']);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Get incomplete tasks from last session failed: cursor lost'))
            ->once();
    });
});

/*
|--------------------------------------------------------------------------
| AttendanceController
|--------------------------------------------------------------------------
*/
describe('employee attendance', function () {
    it('requires authentication and the employee role', function () {
        $this->postJson('/api/v1/attendance/check-in', [])->assertUnauthorized();
        $this->postJson('/api/v1/attendance/check-out', [])->assertUnauthorized();
        $this->getJson('/api/v1/attendance/today')->assertUnauthorized();

        $this->postJson('/api/v1/attendance/check-in', ['tasks' => [['title' => 'x']]], tlecAuth($this->manager))
            ->assertForbidden();
        $this->getJson('/api/v1/attendance/today', tlecAuth($this->manager))->assertForbidden();
        expect(Attendance::count())->toBe(0);
    });

    it('validates the check-in payload', function (array $payload, array $errorKeys) {
        $response = $this->postJson('/api/v1/attendance/check-in', $payload, tlecAuth($this->employee));

        $response->assertUnprocessable()->assertJsonValidationErrors($errorKeys);
        expect(Attendance::count())->toBe(0);
    })->with([
        'no tasks key' => [[], ['tasks']],
        'empty task list' => [['tasks' => []], ['tasks']],
        'too many tasks' => [['tasks' => array_fill(0, 21, ['title' => 'x'])], ['tasks']],
        'untitled task' => [['tasks' => [['title' => '']]], ['tasks.0.title']],
        'title too long' => [['tasks' => [['title' => str_repeat('t', 256)]]], ['tasks.0.title']],
    ]);

    it('accepts exactly twenty tasks at check-in', function () {
        $tasks = array_map(fn ($i) => ['title' => "Task {$i}"], range(1, 20));

        $response = $this->postJson('/api/v1/attendance/check-in', ['tasks' => $tasks], tlecAuth($this->employee));

        $response->assertCreated()->assertJsonCount(20, 'data.tasks');
    });

    it('validates the check-out payload', function (callable $payload, array $errorKeys) {
        $session = tlecSession($this->employee);
        $task = Task::factory()->create(['attendance_id' => $session->id]);

        $response = $this->postJson('/api/v1/attendance/check-out', $payload($session, $task), tlecAuth($this->employee));

        $response->assertUnprocessable()->assertJsonValidationErrors($errorKeys);
        expect($session->fresh()->check_out)->toBeNull();
    })->with([
        'missing attendance id' => [
            fn ($session, $task) => ['tasks' => []],
            ['attendance_id'],
        ],
        'unknown attendance id' => [
            fn ($session, $task) => ['attendance_id' => 999999, 'tasks' => []],
            ['attendance_id'],
        ],
        'unknown task id' => [
            fn ($session, $task) => ['attendance_id' => $session->id, 'tasks' => [['id' => 999999, 'is_completed' => true]]],
            ['tasks.0.id'],
        ],
        'missing completion flag' => [
            fn ($session, $task) => ['attendance_id' => $session->id, 'tasks' => [['id' => $task->id]]],
            ['tasks.0.is_completed'],
        ],
        'incomplete task without blocker' => [
            fn ($session, $task) => ['attendance_id' => $session->id, 'tasks' => [['id' => $task->id, 'is_completed' => false]]],
            ['tasks.0.blocker_reason'],
        ],
        'blocker reason too long' => [
            fn ($session, $task) => ['attendance_id' => $session->id, 'tasks' => [['id' => $task->id, 'is_completed' => false, 'blocker_reason' => str_repeat('b', 501)]]],
            ['tasks.0.blocker_reason'],
        ],
    ]);

    it('asks for the tasks array at check-out with a proper validation error', function (callable $payload) {
        Log::spy();
        $session = tlecSession($this->employee);
        $task = Task::factory()->create(['attendance_id' => $session->id]);

        $response = $this->postJson('/api/v1/attendance/check-out', $payload($session, $task), tlecAuth($this->employee));

        $response->assertUnprocessable()->assertJsonValidationErrors(['tasks']);
        expect($response->getContent())->not->toContain('Undefined array key')
            ->and($response->json('success'))->toBeNull()
            ->and($session->fresh()->check_out)->toBeNull()
            ->and($task->fresh()->is_completed)->toBeFalse();
        Log::shouldNotHaveReceived('error');
    })->with([
        'tasks key missing' => [fn ($session, $task) => ['attendance_id' => $session->id]],
        'tasks is null' => [fn ($session, $task) => ['attendance_id' => $session->id, 'tasks' => null]],
        'tasks is not a list' => [fn ($session, $task) => ['attendance_id' => $session->id, 'tasks' => 'done']],
    ]);

    it('explains the missing check-out tasks in Indonesian', function () {
        $session = tlecSession($this->employee);

        $this->postJson('/api/v1/attendance/check-out', ['attendance_id' => $session->id], tlecAuth($this->employee))
            ->assertUnprocessable()
            ->assertJsonPath('errors.tasks.0', 'Tugas wajib diisi.');

        $this->postJson('/api/v1/attendance/check-out', ['attendance_id' => $session->id, 'tasks' => 'done'], tlecAuth($this->employee))
            ->assertUnprocessable()
            ->assertJsonPath('errors.tasks.0', 'Tugas harus berupa daftar.');
    });

    it('checks out with an empty task list and leaves the tasks untouched', function () {
        $session = tlecSession($this->employee);
        $task = Task::factory()->create(['attendance_id' => $session->id]);

        $response = $this->postJson('/api/v1/attendance/check-out', [
            'attendance_id' => $session->id,
            'tasks' => [],
        ], tlecAuth($this->employee));

        $response->assertOk()
            ->assertJsonPath('data.total_hours', 2)
            ->assertJsonPath('message', 'Checked out successfully. Total hours: 2.00');
        $fresh = $session->fresh();
        expect($fresh->check_out->format('H:i'))->toBe('12:00')
            ->and((float) $fresh->total_hours)->toBe(2.0)
            ->and($task->fresh()->is_completed)->toBeFalse();
    });

    it('refuses to check out a colleagues session', function () {
        $session = tlecSession($this->colleague);

        $response = $this->postJson('/api/v1/attendance/check-out', [
            'attendance_id' => $session->id,
            'tasks' => [],
        ], tlecAuth($this->employee));

        $response->assertUnprocessable()->assertJson([
            'success' => false,
            'message' => 'Attendance record not found or does not belong to you.',
        ]);
        expect($session->fresh()->check_out)->toBeNull();
    });

    it('returns a generic 500 when the daily status cannot be built', function () {
        Log::spy();
        $this->mock(AttendanceService::class, function ($mock) {
            $mock->shouldReceive('getTodayStatus')->once()->andThrow(new RuntimeException('clock skew'));
        });

        $response = $this->getJson('/api/v1/attendance/today', tlecAuth($this->employee));

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Failed to get today status']);
        expect($response->getContent())->not->toContain('clock skew');
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Get today status failed: clock skew'))
            ->once();
    });

    it('reports hours worked so far and the remaining hours for today', function () {
        tlecSession($this->employee, '13:00:00', '2030-01-15', '10:00:00')->update(['total_hours' => 3.0]);

        $response = $this->getJson('/api/v1/attendance/today', tlecAuth($this->employee));

        $response->assertOk()
            ->assertJsonPath('data.date', '2030-01-15')
            ->assertJsonPath('data.is_checked_in', false)
            ->assertJsonPath('data.current_session', null)
            ->assertJsonPath('data.today_total_hours', 3)
            ->assertJsonCount(1, 'data.previous_sessions');
        expect($response->json('data.remaining_hours'))->toEqual($response->json('data.required_hours') - 3);
    });
});
