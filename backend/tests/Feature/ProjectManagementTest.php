<?php

use App\Enums\ActivityType;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

/*
 * Project list: managed by the manager under /manager/projects, read by everyone of the team
 * through /projects (the choices offered at check-in).
 */

/**
 * Bearer header for a user. Guards are reset so several actors can be used in one test.
 */
function pmAuth(User $user): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer '.$user->createToken('auth-token')->plainTextToken];
}

function pmProject(Team $team, array $attributes = []): Project
{
    return Project::factory()->create(array_merge(['team_id' => $team->id], $attributes));
}

beforeEach(function () {
    $this->team = $this->createTeam();
    $this->manager = $this->createManager();
    $this->employee = $this->createEmployee();

    // Another team, which must stay invisible and untouchable.
    $this->otherTeam = $this->createTeam();
    $this->otherProject = Project::factory()->create(['team_id' => $this->otherTeam->id, 'name' => 'Rahasia Tim Lain']);
});

describe('access control', function () {
    it('requires authentication and the manager role', function (string $method, string $url) {
        $this->json($method, $url)->assertUnauthorized();
        $this->json($method, $url, [], pmAuth($this->employee))->assertForbidden();
    })->with([
        'list' => ['GET', '/api/v1/manager/projects'],
        'create' => ['POST', '/api/v1/manager/projects'],
        'update' => ['PUT', '/api/v1/manager/projects/1'],
        'delete' => ['DELETE', '/api/v1/manager/projects/1'],
    ]);

    it('tells a super admin without a team to work as a team manager', function (string $method, string $url) {
        $superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'team_id' => null]);

        $this->json($method, str_replace('{id}', (string) $this->otherProject->id, $url), ['name' => 'Project Admin'], pmAuth($superAdmin))
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Project dikelola per tim. Masuk sebagai manager tim untuk mengelolanya.');

        $this->assertDatabaseMissing('projects', ['name' => 'Project Admin']);
        $this->assertDatabaseHas('projects', ['id' => $this->otherProject->id, 'name' => 'Rahasia Tim Lain']);
    })->with([
        'list' => ['GET', '/api/v1/manager/projects'],
        'create' => ['POST', '/api/v1/manager/projects'],
        'update' => ['PUT', '/api/v1/manager/projects/{id}'],
        'delete' => ['DELETE', '/api/v1/manager/projects/{id}'],
    ]);
});

describe('GET /manager/projects', function () {
    it('lists the team projects in creation order with their task count', function () {
        $first = pmProject($this->team, ['name' => 'Santri Link', 'pic' => 'Nico', 'progress' => 100, 'status' => ProjectStatus::COMPLETED, 'note' => 'Development Selesai']);
        $second = pmProject($this->team, ['name' => 'Arsip Lama', 'is_active' => false]);
        $attendance = Attendance::factory()->create(['user_id' => $this->employee->id]);
        Task::factory()->count(2)->create(['attendance_id' => $attendance->id, 'project_id' => $first->id]);

        $response = $this->getJson('/api/v1/manager/projects', pmAuth($this->manager));

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0', [
                'id' => $first->id,
                'name' => 'Santri Link',
                'pic' => 'Nico',
                'progress' => 100,
                'status' => 'completed',
                'status_label' => 'Selesai',
                'note' => 'Development Selesai',
                'is_active' => true,
                'tasks_count' => 2,
                'created_at' => $first->created_at->toIso8601String(),
            ])
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonPath('data.1.is_active', false)
            ->assertJsonPath('data.1.status_label', 'On-Progress')
            ->assertJsonPath('data.1.tasks_count', 0);
    });

    it('answers 500 with a generic message when listing fails', function () {
        Log::spy();
        pmProject($this->team);
        Project::retrieved(function () {
            throw new RuntimeException('db down');
        });

        $this->getJson('/api/v1/manager/projects', pmAuth($this->manager))
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Gagal mengambil daftar project.');

        Log::shouldHaveReceived('error')->once();
    });
});

describe('POST /manager/projects', function () {
    it('creates a project for the manager team with sensible defaults', function () {
        $response = $this->postJson('/api/v1/manager/projects', ['name' => '  TSD Finance  '], pmAuth($this->manager));

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Project berhasil ditambahkan.')
            ->assertJsonPath('data.name', 'TSD Finance')
            ->assertJsonPath('data.pic', null)
            ->assertJsonPath('data.progress', 0)
            ->assertJsonPath('data.status', 'on_progress')
            ->assertJsonPath('data.status_label', 'On-Progress')
            ->assertJsonPath('data.note', null)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('projects', ['team_id' => $this->team->id, 'name' => 'TSD Finance', 'progress' => 0, 'status' => 'on_progress']);
    });

    it('stores every field and ignores a team_id sent by the client', function () {
        $this->postJson('/api/v1/manager/projects', [
            'name' => 'Sukasir',
            'pic' => 'Handoyo, Intern',
            'progress' => 40,
            'status' => 'hold',
            'note' => 'Hold',
            'is_active' => false,
            'team_id' => $this->otherTeam->id,
        ], pmAuth($this->manager))
            ->assertCreated()
            ->assertJsonPath('data.status_label', 'Hold');

        $this->assertDatabaseHas('projects', [
            'team_id' => $this->team->id,
            'name' => 'Sukasir',
            'pic' => 'Handoyo, Intern',
            'progress' => 40,
            'status' => 'hold',
            'note' => 'Hold',
            'is_active' => false,
        ]);
    });

    it('logs the creation in the activity log', function () {
        $this->postJson('/api/v1/manager/projects', ['name' => 'MyStore'], pmAuth($this->manager))->assertCreated();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->manager->id,
            'action' => ActivityType::PROJECT_CREATED->value,
            'description' => 'Project created',
        ]);
    });

    it('rejects invalid input', function (array $payload, string $field, string $message) {
        pmProject($this->team, ['name' => 'Sudah Ada']);

        $this->postJson('/api/v1/manager/projects', $payload, pmAuth($this->manager))
            ->assertUnprocessable()
            ->assertJsonPath("errors.{$field}.0", $message);
    })->with([
        'missing name' => [[], 'name', 'Nama project wajib diisi.'],
        'name too long' => [['name' => str_repeat('a', 151)], 'name', 'Nama project maksimal 150 karakter.'],
        'duplicate name in the team' => [['name' => 'Sudah Ada'], 'name', 'Nama project ini sudah terdaftar untuk tim Anda.'],
        'pic too long' => [['name' => 'Baru', 'pic' => str_repeat('a', 256)], 'pic', 'PIC maksimal 255 karakter.'],
        'progress not a number' => [['name' => 'Baru', 'progress' => 'separuh'], 'progress', 'Progress harus berupa angka.'],
        'progress not a step of ten' => [['name' => 'Baru', 'progress' => 45], 'progress', 'Progress harus kelipatan 10 antara 0 dan 100.'],
        'progress above 100' => [['name' => 'Baru', 'progress' => 110], 'progress', 'Progress harus kelipatan 10 antara 0 dan 100.'],
        'unknown status' => [['name' => 'Baru', 'status' => 'archived'], 'status', 'Status project tidak valid.'],
        'note too long' => [['name' => 'Baru', 'note' => str_repeat('a', 256)], 'note', 'Catatan maksimal 255 karakter.'],
        'is_active not boolean' => [['name' => 'Baru', 'is_active' => 'mungkin'], 'is_active', 'Status aktif harus bernilai benar atau salah.'],
    ]);

    it('allows a name another team already uses', function () {
        $this->postJson('/api/v1/manager/projects', ['name' => 'Rahasia Tim Lain'], pmAuth($this->manager))
            ->assertCreated();

        expect(Project::where('name', 'Rahasia Tim Lain')->count())->toBe(2);
    });

    it('answers 500 with a generic message when saving fails', function () {
        Log::spy();
        Project::creating(function () {
            throw new RuntimeException('db down');
        });

        $this->postJson('/api/v1/manager/projects', ['name' => 'Gagal'], pmAuth($this->manager))
            ->assertStatus(500)
            ->assertJsonPath('message', 'Gagal menambahkan project.');
    });
});

describe('PUT /manager/projects/{id}', function () {
    it('updates a project and keeps its own name valid', function () {
        $project = pmProject($this->team, ['name' => 'SIAKAD', 'pic' => 'Handoyo', 'note' => 'Proses Development']);

        $this->putJson("/api/v1/manager/projects/{$project->id}", [
            'name' => 'SIAKAD',
            'pic' => '',
            'progress' => 100,
            'status' => 'completed',
            'note' => 'Development Selesai',
            'is_active' => false,
        ], pmAuth($this->manager))
            ->assertOk()
            ->assertJsonPath('message', 'Project berhasil diperbarui.')
            ->assertJsonPath('data.pic', null)
            ->assertJsonPath('data.progress', 100)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.note', 'Development Selesai')
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'pic' => null, 'progress' => 100, 'status' => 'completed', 'is_active' => false]);
        $this->assertDatabaseHas('activity_logs', ['user_id' => $this->manager->id, 'action' => ActivityType::PROJECT_UPDATED->value]);
    });

    it('leaves fields that are not sent untouched', function () {
        $project = pmProject($this->team, ['name' => 'BillNetID', 'pic' => 'Rakha', 'progress' => 70, 'status' => ProjectStatus::HOLD, 'note' => 'Hold']);

        $this->putJson("/api/v1/manager/projects/{$project->id}", ['name' => 'BillNetID', 'progress' => 80], pmAuth($this->manager))
            ->assertOk();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'pic' => 'Rakha', 'progress' => 80, 'status' => 'hold', 'note' => 'Hold', 'is_active' => true]);
    });

    it('rejects a name taken by another project of the team', function () {
        pmProject($this->team, ['name' => 'Ustadz Link']);
        $project = pmProject($this->team, ['name' => 'Santri Link']);

        $this->putJson("/api/v1/manager/projects/{$project->id}", ['name' => 'Ustadz Link'], pmAuth($this->manager))
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Nama project ini sudah terdaftar untuk tim Anda.');
    });

    it('answers 404 for an unknown project and 403 for another team project', function () {
        $this->putJson('/api/v1/manager/projects/999999', ['name' => 'Tidak Ada'], pmAuth($this->manager))
            ->assertNotFound()
            ->assertJsonPath('message', 'Project tidak ditemukan.');

        $this->putJson("/api/v1/manager/projects/{$this->otherProject->id}", ['name' => 'Diambil Alih'], pmAuth($this->manager))
            ->assertForbidden()
            ->assertJsonPath('message', 'Tidak berwenang mengelola project ini.');

        $this->assertDatabaseHas('projects', ['id' => $this->otherProject->id, 'name' => 'Rahasia Tim Lain']);
    });
});

describe('DELETE /manager/projects/{id}', function () {
    it('deletes a project that was never used', function () {
        $project = pmProject($this->team);

        $this->deleteJson("/api/v1/manager/projects/{$project->id}", [], pmAuth($this->manager))
            ->assertOk()
            ->assertJsonPath('message', 'Project berhasil dihapus.');

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('activity_logs', ['user_id' => $this->manager->id, 'action' => ActivityType::PROJECT_DELETED->value]);
    });

    it('refuses to delete a project that tasks refer to', function () {
        $project = pmProject($this->team);
        $attendance = Attendance::factory()->create(['user_id' => $this->employee->id]);
        Task::factory()->create(['attendance_id' => $attendance->id, 'project_id' => $project->id]);

        $this->deleteJson("/api/v1/manager/projects/{$project->id}", [], pmAuth($this->manager))
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Project sudah dipakai pada data check-in sehingga tidak dapat dihapus. Nonaktifkan project agar tidak bisa dipilih lagi.');

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('activity_logs', ['action' => ActivityType::PROJECT_DELETED->value]);
    });

    it('answers 404 for an unknown project and 403 for another team project', function () {
        $this->deleteJson('/api/v1/manager/projects/999999', [], pmAuth($this->manager))->assertNotFound();

        $this->deleteJson("/api/v1/manager/projects/{$this->otherProject->id}", [], pmAuth($this->manager))->assertForbidden();

        $this->assertDatabaseHas('projects', ['id' => $this->otherProject->id]);
    });
});

describe('GET /projects', function () {
    it('requires authentication', function () {
        $this->getJson('/api/v1/projects')->assertUnauthorized();
    });

    it('offers the active projects of the own team, alphabetically, to every role', function (string $actor) {
        $zebra = pmProject($this->team, ['name' => 'Zebra App']);
        $alpha = pmProject($this->team, ['name' => 'Alpha App']);
        pmProject($this->team, ['name' => 'Arsip Lama', 'is_active' => false]);

        $this->getJson('/api/v1/projects', pmAuth($this->{$actor}))
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => [
                    ['id' => $alpha->id, 'name' => 'Alpha App'],
                    ['id' => $zebra->id, 'name' => 'Zebra App'],
                ],
            ]);
    })->with(['employee', 'manager']);

    it('answers 500 with a generic message when the query fails', function () {
        Log::spy();
        pmProject($this->team);
        Project::retrieved(function () {
            throw new RuntimeException('db down');
        });

        $this->getJson('/api/v1/projects', pmAuth($this->employee))
            ->assertStatus(500)
            ->assertJsonPath('message', 'Gagal mengambil daftar project.');
    });
});

describe('model', function () {
    it('belongs to a team and has tasks', function () {
        $project = pmProject($this->team);
        $attendance = Attendance::factory()->create(['user_id' => $this->employee->id]);
        $task = Task::factory()->create(['attendance_id' => $attendance->id, 'project_id' => $project->id]);

        expect($project->team->is($this->team))->toBeTrue()
            ->and($project->tasks->modelKeys())->toBe([$task->id])
            ->and($task->project->is($project))->toBeTrue()
            ->and($this->team->projects->modelKeys())->toBe([$project->id]);
    });

    it('keeps the tasks but clears their project when a project row is removed', function () {
        $project = pmProject($this->team);
        $attendance = Attendance::factory()->create(['user_id' => $this->employee->id]);
        $task = Task::factory()->create(['attendance_id' => $attendance->id, 'project_id' => $project->id]);

        $project->delete();

        expect($task->fresh()->project_id)->toBeNull();
    });

    it('labels every status and offers factory states', function () {
        expect(ProjectStatus::ON_PROGRESS->label())->toBe('On-Progress')
            ->and(ProjectStatus::COMPLETED->label())->toBe('Selesai')
            ->and(ProjectStatus::HOLD->label())->toBe('Hold');

        $completed = Project::factory()->completed()->create();
        $hold = Project::factory()->hold()->inactive()->create();

        expect($completed->status)->toBe(ProjectStatus::COMPLETED)
            ->and($completed->progress)->toBe(100)
            ->and($hold->status)->toBe(ProjectStatus::HOLD)
            ->and($hold->is_active)->toBeFalse();
    });
});
