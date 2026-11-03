<?php

use App\Enums\EmploymentType;
use App\Http\Resources\UserResource;
use App\Models\User;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

/*
 * Job title ("Jabatan") and employment type (permanent / intern) of a user. Both are maintained
 * in user management and printed on the evaluation report.
 */

function ujtAuth(User $user): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer '.$user->createToken('auth-token')->plainTextToken];
}

function ujtPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Budi Santoso',
        'email' => 'budi@example.com',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
        'role' => 'employee',
    ], $overrides);
}

beforeEach(function () {
    $this->team = $this->createTeam();
    $this->manager = $this->createManager();
});

it('stores job title and employment type of a new user', function () {
    $this->postJson('/api/v1/manager/users', ujtPayload([
        'job_title' => 'Fullstack Developer',
        'employment_type' => 'intern',
    ]), ujtAuth($this->manager))
        ->assertCreated()
        ->assertJsonPath('data.job_title', 'Fullstack Developer')
        ->assertJsonPath('data.employment_type', 'intern');

    $user = User::where('email', 'budi@example.com')->firstOrFail();

    expect($user->job_title)->toBe('Fullstack Developer')
        ->and($user->employment_type)->toBe(EmploymentType::INTERN);
});

it('defaults to a permanent employee without a job title', function () {
    $this->postJson('/api/v1/manager/users', ujtPayload(), ujtAuth($this->manager))
        ->assertCreated()
        ->assertJsonPath('data.job_title', null)
        ->assertJsonPath('data.employment_type', 'permanent');

    $this->assertDatabaseHas('users', ['email' => 'budi@example.com', 'job_title' => null, 'employment_type' => 'permanent']);
});

it('updates and clears the job title and changes the employment type', function () {
    $employee = $this->createEmployee(['name' => 'Budi Santoso', 'email' => 'budi@example.com', 'job_title' => 'Intern QA', 'employment_type' => EmploymentType::INTERN]);
    $payload = ['name' => 'Budi Santoso', 'email' => 'budi@example.com', 'role' => 'employee'];

    $this->putJson("/api/v1/manager/users/{$employee->id}", $payload + ['job_title' => 'Quality Assurance', 'employment_type' => 'permanent'], ujtAuth($this->manager))
        ->assertOk()
        ->assertJsonPath('data.job_title', 'Quality Assurance')
        ->assertJsonPath('data.employment_type', 'permanent');

    // Not sent: the employment type stays; an empty job title clears it.
    $this->putJson("/api/v1/manager/users/{$employee->id}", $payload + ['job_title' => ''], ujtAuth($this->manager))
        ->assertOk()
        ->assertJsonPath('data.job_title', null)
        ->assertJsonPath('data.employment_type', 'permanent');
});

it('rejects an over-long job title and an unknown employment type', function () {
    $this->postJson('/api/v1/manager/users', ujtPayload(['job_title' => str_repeat('a', 101)]), ujtAuth($this->manager))
        ->assertUnprocessable()
        ->assertJsonPath('errors.job_title.0', 'Jabatan maksimal 100 karakter');

    $this->postJson('/api/v1/manager/users', ujtPayload(['employment_type' => 'freelance']), ujtAuth($this->manager))
        ->assertUnprocessable()
        ->assertJsonPath('errors.employment_type.0', 'Status karyawan harus tetap atau intern');

    $this->assertDatabaseMissing('users', ['email' => 'budi@example.com']);
});

it('lists both fields in user management and falls back to permanent for a partially loaded user', function () {
    $this->createEmployee(['name' => 'Citra', 'job_title' => 'UI/UX Designer', 'employment_type' => EmploymentType::INTERN]);

    $users = collect($this->getJson('/api/v1/manager/users', ujtAuth($this->manager))->assertOk()->json('data'))->keyBy('name');

    expect($users['Citra'])->toMatchArray(['job_title' => 'UI/UX Designer', 'employment_type' => 'intern'])
        ->and($users[$this->manager->name])->toMatchArray(['job_title' => null, 'employment_type' => 'permanent']);

    // A model loaded without the column (e.g. select id, name) must not break the resource.
    $partial = User::query()->select(['id', 'name', 'email', 'role', 'team_id', 'leave_quota_days', 'is_disabled'])->where('name', 'Citra')->firstOrFail();

    expect((new UserResource($partial))->toArray(request())['employment_type'])->toBe('permanent');
});
