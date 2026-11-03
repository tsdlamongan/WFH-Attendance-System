<?php

use App\Models\Attendance;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

/*
 * Tasks can be logged for a project of the team: at check-in, when adding tasks to a session,
 * and by the manager (manual attendance, task correction). The project then shows up wherever
 * tasks are returned. "Now" is frozen at Wednesday 20 March 2030 09:00.
 */

/**
 * Bearer header for a user. Guards are reset so several actors can be used in one test.
 */
function tpAuth(User $user): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer '.$user->createToken('auth-token')->plainTextToken];
}

function tpProject(Team $team, array $attributes = []): Project
{
    return Project::factory()->create(array_merge(['team_id' => $team->id], $attributes));
}

/**
 * A finished 8-hour session on the frozen day with one task per given [title, project, done?].
 */
function tpFinishedSession(User $user, array $tasks): Attendance
{
    $attendance = Attendance::create([
        'user_id' => $user->id,
        'check_in' => '2030-03-20 00:30:00',
        'check_out' => '2030-03-20 08:30:00',
        'date' => '2030-03-20',
        'total_hours' => 8.0,
    ]);

    foreach ($tasks as [$title, $project, $isCompleted]) {
        Task::create([
            'attendance_id' => $attendance->id,
            'project_id' => $project?->id,
            'title' => $title,
            'is_completed' => $isCompleted,
            'blocker_reason' => $isCompleted ? null : 'Menunggu review',
        ]);
    }

    return $attendance;
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(Carbon::parse('2030-03-20 09:00:00'));
    $this->team = $this->createTeam(['required_work_hours' => 7.0]);
    $this->manager = $this->createManager();
    $this->employee = $this->createEmployee(['name' => 'Alice']);

    $this->project = tpProject($this->team, ['name' => 'Santri Link']);
    $this->inactiveProject = tpProject($this->team, ['name' => 'Arsip Lama', 'is_active' => false]);
    $this->foreignProject = Project::factory()->create(['name' => 'Project Tim Lain']);
});

describe('check-in', function () {
    it('stores the chosen project per task and returns its name', function () {
        $response = $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [
                ['title' => 'Fixing WA Notif', 'project_id' => $this->project->id],
                ['title' => 'Rapat mingguan', 'project_id' => null],
                ['title' => 'Balas email'],
            ],
        ], tpAuth($this->employee));

        $response->assertCreated()
            ->assertJsonPath('data.tasks.0.project_id', $this->project->id)
            ->assertJsonPath('data.tasks.0.project', 'Santri Link')
            ->assertJsonPath('data.tasks.1.project_id', null)
            ->assertJsonPath('data.tasks.1.project', null)
            ->assertJsonPath('data.tasks.2.project_id', null);

        $this->assertDatabaseHas('tasks', ['title' => 'Fixing WA Notif', 'project_id' => $this->project->id]);
        $this->assertDatabaseHas('tasks', ['title' => 'Rapat mingguan', 'project_id' => null]);
        $this->assertDatabaseHas('tasks', ['title' => 'Balas email', 'project_id' => null]);
    });

    it('rejects a project the employee may not pick', function (string $which, string $message) {
        $projectId = match ($which) {
            'inactive' => $this->inactiveProject->id,
            'foreign' => $this->foreignProject->id,
            'unknown' => 999999,
            default => 'abc',
        };

        $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [['title' => 'Kerjakan laporan', 'project_id' => $projectId]],
        ], tpAuth($this->employee))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tasks.0.project_id' => $message]);

        $this->assertDatabaseCount('attendances', 0);
    })->with([
        'inactive project' => ['inactive', 'Project yang dipilih tidak tersedia.'],
        'project of another team' => ['foreign', 'Project yang dipilih tidak tersedia.'],
        'unknown project' => ['unknown', 'Project yang dipilih tidak tersedia.'],
        'not a number' => ['text', 'Project yang dipilih tidak valid.'],
    ]);

    it('shows the project of each task in the current session', function () {
        $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [
                ['title' => 'Fixing WA Notif', 'project_id' => $this->project->id],
                ['title' => 'Rapat mingguan'],
            ],
        ], tpAuth($this->employee))->assertCreated();

        $this->getJson('/api/v1/attendance/today', tpAuth($this->employee))
            ->assertOk()
            ->assertJsonPath('data.current_session.tasks.0.project_id', $this->project->id)
            ->assertJsonPath('data.current_session.tasks.0.project', 'Santri Link')
            ->assertJsonPath('data.current_session.tasks.1.project_id', null)
            ->assertJsonPath('data.current_session.tasks.1.project', null);
    });

    it('keeps the project in the check-out response', function () {
        $this->postJson('/api/v1/attendance/check-in', [
            'tasks' => [['title' => 'Fixing WA Notif', 'project_id' => $this->project->id]],
        ], tpAuth($this->employee))->assertCreated();
        $task = Task::first();

        $this->travelTo(Carbon::parse('2030-03-20 12:00:00'));

        $this->postJson('/api/v1/attendance/check-out', [
            'attendance_id' => $task->attendance_id,
            'tasks' => [['id' => $task->id, 'is_completed' => true]],
        ], tpAuth($this->employee))
            ->assertOk()
            ->assertJsonPath('data.tasks.0.project', 'Santri Link')
            ->assertJsonPath('data.tasks.0.is_completed', true);
    });
});

describe('adding tasks to a running session', function () {
    beforeEach(function () {
        $this->attendance = Attendance::create([
            'user_id' => $this->employee->id,
            'check_in' => '2030-03-20 08:00:00',
            'check_out' => null,
            'date' => '2030-03-20',
            'total_hours' => 0,
        ]);
    });

    it('stores the chosen project', function () {
        $this->postJson('/api/v1/tasks/add', [
            'attendance_id' => $this->attendance->id,
            'tasks' => [
                ['title' => 'Integrasi API', 'project_id' => $this->project->id],
                ['title' => 'Tanpa project'],
            ],
        ], tpAuth($this->employee))
            ->assertCreated()
            ->assertJsonPath('data.tasks.0.project_id', $this->project->id)
            ->assertJsonPath('data.tasks.0.project', 'Santri Link')
            ->assertJsonPath('data.tasks.1.project', null);

        $this->assertDatabaseHas('tasks', ['title' => 'Integrasi API', 'project_id' => $this->project->id]);
    });

    it('rejects an inactive or foreign project', function () {
        foreach ([$this->inactiveProject, $this->foreignProject] as $project) {
            $this->postJson('/api/v1/tasks/add', [
                'attendance_id' => $this->attendance->id,
                'tasks' => [['title' => 'Integrasi API', 'project_id' => $project->id]],
            ], tpAuth($this->employee))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['tasks.0.project_id' => 'Project yang dipilih tidak tersedia.']);
        }

        $this->postJson('/api/v1/tasks/add', [
            'attendance_id' => $this->attendance->id,
            'tasks' => [['title' => 'Integrasi API', 'project_id' => 'abc']],
        ], tpAuth($this->employee))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tasks.0.project_id' => 'Project yang dipilih tidak valid.']);

        $this->assertDatabaseCount('tasks', 0);
    });
});

describe('carrying tasks over', function () {
    it('returns the project of unfinished tasks from the last session', function () {
        tpFinishedSession($this->employee, [
            ['Slicing dashboard', $this->project, false],
            ['Riset library', null, false],
            ['Sudah beres', $this->project, true],
        ]);

        $this->getJson('/api/v1/tasks/incomplete-last-session', tpAuth($this->employee))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Slicing dashboard')
            ->assertJsonPath('data.0.project_id', $this->project->id)
            ->assertJsonPath('data.0.project', 'Santri Link')
            ->assertJsonPath('data.1.project_id', null)
            ->assertJsonPath('data.1.project', null);

        $this->getJson('/api/v1/tasks/incomplete', tpAuth($this->employee))
            ->assertOk()
            ->assertJsonPath('data.0.project', 'Santri Link');
    });
});

describe('manager attendance tools', function () {
    it('accepts any project of the team when adding an attendance manually', function () {
        $response = $this->postJson('/api/v1/manager/attendances', [
            'user_id' => $this->employee->id,
            'date' => '2030-03-19',
            'check_in' => '2030-03-19 08:00:00',
            'check_out' => '2030-03-19 16:00:00',
            'tasks' => [
                ['title' => 'Migrasi data', 'project_id' => $this->inactiveProject->id],
                ['title' => 'Fixing WA Notif', 'project_id' => $this->project->id],
                ['title' => 'Lain-lain'],
            ],
            'reason' => 'Karyawan lupa check-in kemarin',
        ], tpAuth($this->manager));

        $response->assertCreated()
            ->assertJsonPath('data.tasks.0.project', 'Arsip Lama')
            ->assertJsonPath('data.tasks.1.project', 'Santri Link')
            ->assertJsonPath('data.tasks.2.project', null);

        $this->assertDatabaseHas('tasks', ['title' => 'Migrasi data', 'project_id' => $this->inactiveProject->id]);
    });

    it('rejects a project of another team when adding an attendance manually', function () {
        $payload = [
            'user_id' => $this->employee->id,
            'date' => '2030-03-19',
            'check_in' => '2030-03-19 08:00:00',
            'check_out' => '2030-03-19 16:00:00',
            'tasks' => [['title' => 'Migrasi data', 'project_id' => $this->foreignProject->id]],
            'reason' => 'Karyawan lupa check-in kemarin',
        ];

        $this->postJson('/api/v1/manager/attendances', $payload, tpAuth($this->manager))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tasks.0.project_id' => 'Project yang dipilih tidak tersedia.']);

        $payload['tasks'][0]['project_id'] = 'abc';

        $this->postJson('/api/v1/manager/attendances', $payload, tpAuth($this->manager))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tasks.0.project_id' => 'Project yang dipilih tidak valid.']);

        $this->assertDatabaseCount('attendances', 0);
    });

    it('lists attendances with the project of every task', function () {
        tpFinishedSession($this->employee, [['Slicing dashboard', $this->project, true]]);

        $this->getJson('/api/v1/manager/attendances?start_date=2030-03-20&end_date=2030-03-20', tpAuth($this->manager))
            ->assertOk()
            ->assertJsonPath('data.0.tasks.0.project_id', $this->project->id)
            ->assertJsonPath('data.0.tasks.0.project', 'Santri Link');
    });

    it('lets the manager set, change and clear the project of a task', function () {
        $attendance = tpFinishedSession($this->employee, [['Slicing dashboard', null, true]]);
        $task = $attendance->tasks()->first();
        $url = "/api/v1/manager/tasks/{$task->id}";

        $this->putJson($url, ['is_completed' => true, 'project_id' => $this->project->id], tpAuth($this->manager))->assertOk();
        expect($task->fresh()->project_id)->toBe($this->project->id);

        // Not sent: the project stays as it is.
        $this->putJson($url, ['is_completed' => false, 'blocker_reason' => 'Menunggu aset'], tpAuth($this->manager))->assertOk();
        expect($task->fresh()->project_id)->toBe($this->project->id);

        $this->putJson($url, ['is_completed' => true, 'project_id' => $this->inactiveProject->id], tpAuth($this->manager))->assertOk();
        expect($task->fresh()->project_id)->toBe($this->inactiveProject->id);

        $this->putJson($url, ['is_completed' => true, 'project_id' => null], tpAuth($this->manager))->assertOk();
        expect($task->fresh()->project_id)->toBeNull();
    });

    it('rejects a foreign or malformed project when correcting a task', function () {
        $attendance = tpFinishedSession($this->employee, [['Slicing dashboard', $this->project, true]]);
        $task = $attendance->tasks()->first();

        $this->putJson("/api/v1/manager/tasks/{$task->id}", ['is_completed' => true, 'project_id' => $this->foreignProject->id], tpAuth($this->manager))
            ->assertUnprocessable()
            ->assertJsonPath('errors.project_id.0', 'Project yang dipilih tidak tersedia.');

        $this->putJson("/api/v1/manager/tasks/{$task->id}", ['is_completed' => true, 'project_id' => 'abc'], tpAuth($this->manager))
            ->assertUnprocessable()
            ->assertJsonPath('errors.project_id.0', 'Project yang dipilih tidak valid.');

        expect($task->fresh()->project_id)->toBe($this->project->id);
    });
});

describe('reports', function () {
    beforeEach(function () {
        tpFinishedSession($this->employee, [
            ['Slicing dashboard', $this->project, true],
            ['Riset library', null, false],
        ]);
    });

    it('shows the project in the employee report', function () {
        $this->getJson('/api/v1/reports/my-report?start_date=2030-03-20&end_date=2030-03-20', tpAuth($this->employee))
            ->assertOk()
            ->assertJsonPath('data.attendances.0.sessions.0.tasks.0.project', 'Santri Link')
            ->assertJsonPath('data.attendances.0.sessions.0.tasks.1.project', null);
    });

    it('shows the project in the daily attendance report', function () {
        $this->getJson('/api/v1/manager/reports/daily-attendance?date=2030-03-20', tpAuth($this->manager))
            ->assertOk()
            ->assertJsonPath('data.employees.0.sessions.0.tasks.0.project', 'Santri Link')
            ->assertJsonPath('data.employees.0.sessions.0.tasks.1.project', null);
    });

    it('shows the project in the monthly attendance report', function () {
        $this->getJson('/api/v1/manager/reports/monthly-attendance?start_date=2030-03-20&end_date=2030-03-20', tpAuth($this->manager))
            ->assertOk()
            ->assertJsonPath('data.employees.0.daily_details.0.sessions.0.tasks.0.project', 'Santri Link')
            ->assertJsonPath('data.employees.0.daily_details.0.sessions.0.tasks.1.project', null);
    });
});
