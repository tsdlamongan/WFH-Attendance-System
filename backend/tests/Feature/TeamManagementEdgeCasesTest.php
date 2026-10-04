<?php

use App\Enums\UserRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

function teamAdminHeaders(?User $superAdmin = null): array
{
    $superAdmin ??= User::factory()->create([
        'team_id' => null,
        'role' => UserRole::SUPER_ADMIN,
        'leave_quota_days' => 0,
    ]);

    return ['Authorization' => 'Bearer '.$superAdmin->createToken('auth-token')->plainTextToken];
}

function validNewTeamPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Brand New Team',
        'description' => 'A team created by the super admin',
        'required_work_hours' => 7.5,
        'default_leave_quota_days' => 12,
        'max_leave_days_per_month' => 5,
    ], $overrides);
}

// ---------------------------------------------------------------------------
// Authentication / authorization
// ---------------------------------------------------------------------------

it('rejects unauthenticated requests to every team management endpoint with 401', function () {
    $team = Team::factory()->create();

    $this->getJson('/api/v1/super-admin/teams')->assertUnauthorized();
    $this->postJson('/api/v1/super-admin/teams', validNewTeamPayload())->assertUnauthorized();
    $this->getJson("/api/v1/super-admin/teams/{$team->id}")->assertUnauthorized();
    $this->putJson("/api/v1/super-admin/teams/{$team->id}", ['name' => 'X'])->assertUnauthorized();
    $this->deleteJson("/api/v1/super-admin/teams/{$team->id}")->assertUnauthorized();

    $this->assertDatabaseHas('teams', ['id' => $team->id, 'name' => $team->name]);
});

it('forbids managers and employees from every team management endpoint', function (string $role) {
    $team = $this->createTeam(['name' => 'Protected Team']);
    $this->team = $team;
    $user = $role === 'manager' ? $this->createManager() : $this->createEmployee();
    $headers = ['Authorization' => 'Bearer '.$user->createToken('auth-token')->plainTextToken];

    $expected = ['success' => false, 'message' => 'Unauthorized. Super admin access required.'];

    $this->getJson('/api/v1/super-admin/teams', $headers)->assertForbidden()->assertExactJson($expected);
    $this->postJson('/api/v1/super-admin/teams', validNewTeamPayload(), $headers)->assertForbidden()->assertExactJson($expected);
    $this->getJson("/api/v1/super-admin/teams/{$team->id}", $headers)->assertForbidden()->assertExactJson($expected);
    $this->putJson("/api/v1/super-admin/teams/{$team->id}", ['name' => 'Taken Over'], $headers)->assertForbidden()->assertExactJson($expected);
    $this->deleteJson("/api/v1/super-admin/teams/{$team->id}", [], $headers)->assertForbidden()->assertExactJson($expected);

    $this->assertDatabaseHas('teams', ['id' => $team->id, 'name' => 'Protected Team']);
    $this->assertDatabaseMissing('teams', ['name' => 'Brand New Team']);
    $this->assertDatabaseMissing('teams', ['name' => 'Taken Over']);
})->with(['manager', 'employee']);

// ---------------------------------------------------------------------------
// index
// ---------------------------------------------------------------------------

it('lists teams newest first with user, manager and employee counts', function () {
    $oldest = Team::factory()->create(['name' => 'Oldest', 'created_at' => now()->subDays(3)]);
    $middle = Team::factory()->create(['name' => 'Middle', 'created_at' => now()->subDays(2)]);
    $newest = Team::factory()->create(['name' => 'Newest', 'created_at' => now()->subDay()]);

    User::factory()->create(['team_id' => $middle->id, 'role' => UserRole::MANAGER]);
    User::factory()->count(2)->create(['team_id' => $middle->id, 'role' => UserRole::EMPLOYEE]);
    User::factory()->create(['team_id' => $newest->id, 'role' => UserRole::EMPLOYEE]);

    $response = $this->getJson('/api/v1/super-admin/teams', teamAdminHeaders());

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(3, 'data');

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Newest', 'Middle', 'Oldest']);

    $byName = collect($response->json('data'))->keyBy('name');
    expect($byName['Middle']['users_count'])->toBe(3)
        ->and($byName['Middle']['managers_count'])->toBe(1)
        ->and($byName['Middle']['employees_count'])->toBe(2)
        ->and($byName['Newest']['users_count'])->toBe(1)
        ->and($byName['Newest']['managers_count'])->toBe(0)
        ->and($byName['Newest']['employees_count'])->toBe(1)
        ->and($byName['Oldest']['users_count'])->toBe(0);
});

it('returns an empty page with consistent pagination metadata when there are no teams', function () {
    $this->getJson('/api/v1/super-admin/teams', teamAdminHeaders())
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('pagination.current_page', 1)
        ->assertJsonPath('pagination.last_page', 1)
        ->assertJsonPath('pagination.per_page', 10)
        ->assertJsonPath('pagination.total', 0)
        ->assertJsonPath('pagination.from', null)
        ->assertJsonPath('pagination.to', null);
});

it('paginates teams ten per page by default', function () {
    Team::factory()->count(12)->create();
    $headers = teamAdminHeaders();

    $this->getJson('/api/v1/super-admin/teams', $headers)
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('pagination.current_page', 1)
        ->assertJsonPath('pagination.last_page', 2)
        ->assertJsonPath('pagination.per_page', 10)
        ->assertJsonPath('pagination.total', 12)
        ->assertJsonPath('pagination.from', 1)
        ->assertJsonPath('pagination.to', 10);

    $this->getJson('/api/v1/super-admin/teams?page=2', $headers)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('pagination.current_page', 2)
        ->assertJsonPath('pagination.from', 11)
        ->assertJsonPath('pagination.to', 12);
});

it('honours the whitelisted per_page values', function (int $perPage, int $expectedCount) {
    Team::factory()->count(12)->create();

    $this->getJson("/api/v1/super-admin/teams?per_page={$perPage}", teamAdminHeaders())
        ->assertOk()
        ->assertJsonCount($expectedCount, 'data')
        ->assertJsonPath('pagination.per_page', $perPage)
        ->assertJsonPath('pagination.total', 12);
})->with([
    'ten' => [10, 10],
    'fifty' => [50, 12],
    'hundred' => [100, 12],
    'thousand' => [1000, 12],
]);

it('falls back to ten per page for non-whitelisted per_page values', function (string $perPage) {
    Team::factory()->count(12)->create();

    $this->getJson("/api/v1/super-admin/teams?per_page={$perPage}", teamAdminHeaders())
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('pagination.per_page', 10)
        ->assertJsonPath('pagination.last_page', 2);
})->with(['5', '11', '0', '-1', 'abc', '99999']);

it('answers 500 and logs when listing teams fails', function () {
    Team::factory()->create();
    $headers = teamAdminHeaders();
    Team::retrieved(function () {
        throw new RuntimeException('read failed');
    });
    Log::spy();

    $this->getJson('/api/v1/super-admin/teams', $headers)
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Failed to get teams']);

    Log::shouldHaveReceived('error')->once()->with('Get teams failed: read failed');
});

// ---------------------------------------------------------------------------
// show
// ---------------------------------------------------------------------------

it('shows a team with a restricted view of its users and the member counts', function () {
    $team = Team::factory()->create(['name' => 'Detail Team']);
    $manager = User::factory()->create(['team_id' => $team->id, 'role' => UserRole::MANAGER, 'email' => 'boss@example.com']);
    $employees = User::factory()->count(2)->create(['team_id' => $team->id, 'role' => UserRole::EMPLOYEE]);
    // A user from another team must never show up
    User::factory()->create(['team_id' => Team::factory()->create()->id, 'role' => UserRole::EMPLOYEE, 'email' => 'outsider@example.com']);

    $response = $this->getJson("/api/v1/super-admin/teams/{$team->id}", teamAdminHeaders());

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $team->id)
        ->assertJsonPath('data.name', 'Detail Team')
        ->assertJsonPath('data.users_count', 3)
        ->assertJsonPath('data.managers_count', 1)
        ->assertJsonPath('data.employees_count', 2)
        ->assertJsonCount(3, 'data.users');

    $users = collect($response->json('data.users'));
    expect($users->pluck('id')->sort()->values()->all())
        ->toBe(collect([$manager->id, ...$employees->pluck('id')])->sort()->values()->all());
    expect($users->pluck('email'))->not->toContain('outsider@example.com');

    // Only id, team_id, name, email and role are selected: no password hash or token leakage
    foreach ($users as $user) {
        expect(array_keys($user))->toEqualCanonicalizing(['id', 'team_id', 'name', 'email', 'role']);
    }
    expect($users->firstWhere('id', $manager->id)['role'])->toBe('manager');
    expect($response->getContent())->not->toContain('password')->not->toContain('remember_token');
});

it('shows a team without users', function () {
    $team = Team::factory()->create();

    $this->getJson("/api/v1/super-admin/teams/{$team->id}", teamAdminHeaders())
        ->assertOk()
        ->assertJsonPath('data.users', [])
        ->assertJsonPath('data.users_count', 0)
        ->assertJsonPath('data.managers_count', 0)
        ->assertJsonPath('data.employees_count', 0);
});

it('answers 404 when showing a team that does not exist', function () {
    $this->getJson('/api/v1/super-admin/teams/987654', teamAdminHeaders())
        ->assertNotFound()
        ->assertExactJson(['success' => false, 'message' => 'Team not found']);
});

it('answers 404 instead of crashing when the team id in the url is not a number', function (string $id) {
    $team = Team::factory()->create(['name' => 'Bystander']);
    $headers = teamAdminHeaders();

    $this->getJson("/api/v1/super-admin/teams/{$id}", $headers)->assertNotFound();
    $this->putJson("/api/v1/super-admin/teams/{$id}", ['name' => 'Hijacked'], $headers)->assertNotFound();
    $this->deleteJson("/api/v1/super-admin/teams/{$id}", [], $headers)->assertNotFound();

    $this->assertDatabaseHas('teams', ['id' => $team->id, 'name' => 'Bystander']);
    $this->assertDatabaseMissing('teams', ['name' => 'Hijacked']);
})->with(['abc', '1abc', '1.5', 'null', '-1']);

it('answers 404 instead of crashing when the team id is a number beyond the integer range', function () {
    $headers = teamAdminHeaders();
    $id = '99999999999999999999';

    $this->getJson("/api/v1/super-admin/teams/{$id}", $headers)->assertNotFound();
    $this->putJson("/api/v1/super-admin/teams/{$id}", ['name' => 'Ghost'], $headers)->assertNotFound();
    $this->deleteJson("/api/v1/super-admin/teams/{$id}", [], $headers)->assertNotFound();
});

it('answers 500 and logs when loading team details fails', function () {
    $team = Team::factory()->create();
    $headers = teamAdminHeaders();
    Team::retrieved(function () {
        throw new RuntimeException('detail failed');
    });
    Log::spy();

    $this->getJson("/api/v1/super-admin/teams/{$team->id}", $headers)
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Failed to get team details']);

    Log::shouldHaveReceived('error')->once()->with('Get team details failed: detail failed');
});

// ---------------------------------------------------------------------------
// store
// ---------------------------------------------------------------------------

it('creates an active team with a generated slug and ignores client-supplied slug and status', function () {
    $response = $this->postJson('/api/v1/super-admin/teams', validNewTeamPayload([
        'name' => 'Creative Ops Crew!',
        'slug' => 'client-chosen-slug',
        'is_active' => false,
    ]), teamAdminHeaders());

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Team created successfully')
        ->assertJsonPath('data.name', 'Creative Ops Crew!')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.required_work_hours', '7.50')
        ->assertJsonPath('data.default_leave_quota_days', 12)
        ->assertJsonPath('data.max_leave_days_per_month', 5);

    $slug = $response->json('data.slug');
    expect($slug)->toStartWith(Str::slug('Creative Ops Crew!').'-')
        ->and($slug)->toMatch('/^creative-ops-crew-\d+$/')
        ->and($slug)->not->toBe('client-chosen-slug');

    $this->assertDatabaseHas('teams', [
        'id' => $response->json('data.id'),
        'name' => 'Creative Ops Crew!',
        'slug' => $slug,
        'is_active' => true,
    ]);
});

it('creates a team without a description', function () {
    $payload = validNewTeamPayload();
    unset($payload['description']);

    $this->postJson('/api/v1/super-admin/teams', $payload, teamAdminHeaders())
        ->assertCreated()
        ->assertJsonPath('data.description', null);

    $this->assertDatabaseHas('teams', ['name' => 'Brand New Team', 'description' => null]);
});

it('accepts boundary values when creating a team', function (array $overrides) {
    $this->postJson('/api/v1/super-admin/teams', validNewTeamPayload($overrides), teamAdminHeaders())
        ->assertCreated();
})->with([
    'min work hours' => [['required_work_hours' => 1]],
    'max work hours' => [['required_work_hours' => 24]],
    'zero quotas' => [['default_leave_quota_days' => 0, 'max_leave_days_per_month' => 0]],
    'max quotas' => [['default_leave_quota_days' => 365, 'max_leave_days_per_month' => 31]],
    'max name length' => [['name' => str_repeat('n', 255)]],
    'max description length' => [['description' => str_repeat('d', 1000)]],
]);

it('requires name, work hours and both leave quotas when creating a team', function () {
    $response = $this->postJson('/api/v1/super-admin/teams', [], teamAdminHeaders());

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Periksa kembali data yang Anda masukkan.')
        ->assertJsonPath('errors.name.0', 'Nama tim wajib diisi.')
        ->assertJsonPath('errors.required_work_hours.0', 'Jam kerja wajib diisi.')
        ->assertJsonPath('errors.default_leave_quota_days.0', 'Kuota cuti wajib diisi.')
        ->assertJsonPath('errors.max_leave_days_per_month.0', 'Kuota cuti per bulan wajib diisi.');

    expect(Team::count())->toBe(0);
});

it('rejects invalid values when creating a team with localized messages', function (array $overrides, string $field, string $message) {
    $this->postJson('/api/v1/super-admin/teams', validNewTeamPayload($overrides), teamAdminHeaders())
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Periksa kembali data yang Anda masukkan.')
        ->assertJsonPath("errors.{$field}.0", $message);

    $this->assertDatabaseMissing('teams', ['name' => 'Brand New Team']);
})->with([
    'name too long' => [['name' => str_repeat('n', 256)], 'name', 'Nama tim maksimal 255 karakter.'],
    'description too long' => [['description' => str_repeat('d', 1001)], 'description', 'Deskripsi maksimal 1000 karakter.'],
    'work hours not numeric' => [['required_work_hours' => 'lots'], 'required_work_hours', 'Jam kerja harus berupa angka.'],
    'work hours below min' => [['required_work_hours' => 0], 'required_work_hours', 'Jam kerja minimal 1 jam.'],
    'work hours above max' => [['required_work_hours' => 25], 'required_work_hours', 'Jam kerja maksimal 24 jam.'],
    'leave quota not integer' => [['default_leave_quota_days' => 1.5], 'default_leave_quota_days', 'Kuota cuti harus berupa angka bulat.'],
    'leave quota below min' => [['default_leave_quota_days' => -1], 'default_leave_quota_days', 'Kuota cuti minimal 0 hari.'],
    'leave quota above max' => [['default_leave_quota_days' => 366], 'default_leave_quota_days', 'Kuota cuti maksimal 365 hari.'],
    'monthly quota not integer' => [['max_leave_days_per_month' => 'x'], 'max_leave_days_per_month', 'Kuota cuti per bulan harus berupa angka bulat.'],
    'monthly quota below min' => [['max_leave_days_per_month' => -1], 'max_leave_days_per_month', 'Kuota cuti per bulan minimal 0 hari.'],
    'monthly quota above max' => [['max_leave_days_per_month' => 32], 'max_leave_days_per_month', 'Kuota cuti per bulan maksimal 31 hari.'],
]);

it('answers 500 and logs when creating a team fails', function () {
    $headers = teamAdminHeaders();
    Team::creating(function () {
        throw new RuntimeException('insert failed');
    });
    Log::spy();

    $this->postJson('/api/v1/super-admin/teams', validNewTeamPayload(), $headers)
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Failed to create team']);

    $this->assertDatabaseMissing('teams', ['name' => 'Brand New Team']);
    Log::shouldHaveReceived('error')->once()->with('Create team failed: insert failed');
});

// ---------------------------------------------------------------------------
// update
// ---------------------------------------------------------------------------

it('regenerates the slug when the name changes but keeps it when the name is unchanged', function () {
    $team = Team::factory()->create(['name' => 'Original Name', 'slug' => 'original-name']);
    $headers = teamAdminHeaders();

    $this->putJson("/api/v1/super-admin/teams/{$team->id}", ['name' => 'Original Name', 'required_work_hours' => 6], $headers)
        ->assertOk()
        ->assertJsonPath('data.slug', 'original-name')
        ->assertJsonPath('data.required_work_hours', '6.00');

    $response = $this->putJson("/api/v1/super-admin/teams/{$team->id}", ['name' => 'Shiny New Name'], $headers)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Team updated successfully')
        ->assertJsonPath('data.name', 'Shiny New Name');

    expect($response->json('data.slug'))->toMatch('/^shiny-new-name-\d+$/');
    $this->assertDatabaseHas('teams', ['id' => $team->id, 'slug' => $response->json('data.slug')]);
});

it('updates only the supplied fields of a team', function () {
    $team = Team::factory()->create([
        'name' => 'Keep Me',
        'description' => 'Keep this too',
        'required_work_hours' => 8,
        'default_leave_quota_days' => 10,
        'max_leave_days_per_month' => 3,
    ]);
    $slug = $team->slug;

    $this->putJson("/api/v1/super-admin/teams/{$team->id}", [
        'default_leave_quota_days' => 20,
        'max_leave_days_per_month' => 6,
    ], teamAdminHeaders())
        ->assertOk()
        ->assertJsonPath('data.default_leave_quota_days', 20)
        ->assertJsonPath('data.max_leave_days_per_month', 6);

    $fresh = $team->fresh();
    expect($fresh->name)->toBe('Keep Me')
        ->and($fresh->slug)->toBe($slug)
        ->and($fresh->description)->toBe('Keep this too')
        ->and($fresh->required_work_hours)->toBe('8.00')
        ->and($fresh->default_leave_quota_days)->toBe(20)
        ->and($fresh->max_leave_days_per_month)->toBe(6);
});

it('allows clearing a team description with null', function () {
    $team = Team::factory()->create(['description' => 'To be cleared']);

    $this->putJson("/api/v1/super-admin/teams/{$team->id}", ['description' => null], teamAdminHeaders())
        ->assertOk()
        ->assertJsonPath('data.description', null);

    expect($team->fresh()->description)->toBeNull();
});

it('lets a super admin deactivate and reactivate a team, which gates its members', function () {
    $team = $this->createTeam();
    $this->team = $team;
    $manager = $this->createManager();
    $managerHeaders = ['Authorization' => 'Bearer '.$manager->createToken('auth-token')->plainTextToken];
    $adminHeaders = teamAdminHeaders();

    $this->putJson("/api/v1/super-admin/teams/{$team->id}", ['is_active' => false], $adminHeaders)
        ->assertOk()
        ->assertJsonPath('data.is_active', false);
    expect($team->fresh()->is_active)->toBeFalse();

    // The Sanctum guard caches the first resolved user for the lifetime of the app instance
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/team/settings', $managerHeaders)
        ->assertForbidden()
        ->assertJsonPath('message', 'Tim Anda tidak aktif. Silakan hubungi administrator.');

    $this->app['auth']->forgetGuards();

    $this->putJson("/api/v1/super-admin/teams/{$team->id}", ['is_active' => true], $adminHeaders)
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
    expect($team->fresh()->is_active)->toBeTrue();
});

it('answers 404 when updating or deleting a team that does not exist', function () {
    $headers = teamAdminHeaders();
    $notFound = ['success' => false, 'message' => 'Team not found'];

    $this->putJson('/api/v1/super-admin/teams/987654', ['name' => 'Ghost'], $headers)
        ->assertNotFound()
        ->assertExactJson($notFound);

    $this->deleteJson('/api/v1/super-admin/teams/987654', [], $headers)
        ->assertNotFound()
        ->assertExactJson($notFound);

    $this->assertDatabaseMissing('teams', ['name' => 'Ghost']);
});

it('checks existence before validating when updating a missing team', function () {
    $this->putJson('/api/v1/super-admin/teams/987654', ['required_work_hours' => 'not-a-number'], teamAdminHeaders())
        ->assertNotFound()
        ->assertJsonPath('message', 'Team not found');
});

it('rejects invalid values when updating a team with localized messages', function (array $payload, string $field, string $message) {
    $team = Team::factory()->create(['name' => 'Pristine']);

    $this->putJson("/api/v1/super-admin/teams/{$team->id}", $payload, teamAdminHeaders())
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Periksa kembali data yang Anda masukkan.')
        ->assertJsonPath("errors.{$field}.0", $message);

    expect($team->fresh()->name)->toBe('Pristine');
})->with([
    'name too long' => [['name' => str_repeat('n', 256)], 'name', 'Nama tim maksimal 255 karakter.'],
    'description too long' => [['description' => str_repeat('d', 1001)], 'description', 'Deskripsi maksimal 1000 karakter.'],
    'work hours not numeric' => [['required_work_hours' => 'abc'], 'required_work_hours', 'Jam kerja harus berupa angka.'],
    'work hours below min' => [['required_work_hours' => 0], 'required_work_hours', 'Jam kerja minimal 1 jam.'],
    'work hours above max' => [['required_work_hours' => 24.5], 'required_work_hours', 'Jam kerja maksimal 24 jam.'],
    'leave quota not integer' => [['default_leave_quota_days' => 'abc'], 'default_leave_quota_days', 'Kuota cuti harus berupa angka bulat.'],
    'leave quota below min' => [['default_leave_quota_days' => -1], 'default_leave_quota_days', 'Kuota cuti minimal 0 hari.'],
    'leave quota above max' => [['default_leave_quota_days' => 400], 'default_leave_quota_days', 'Kuota cuti maksimal 365 hari.'],
    'monthly quota not integer' => [['max_leave_days_per_month' => 2.5], 'max_leave_days_per_month', 'Kuota cuti per bulan harus berupa angka bulat.'],
    'monthly quota below min' => [['max_leave_days_per_month' => -2], 'max_leave_days_per_month', 'Kuota cuti per bulan minimal 0 hari.'],
    'monthly quota above max' => [['max_leave_days_per_month' => 45], 'max_leave_days_per_month', 'Kuota cuti per bulan maksimal 31 hari.'],
    'is_active not boolean' => [['is_active' => 'maybe'], 'is_active', 'Status aktif harus bernilai benar atau salah.'],
]);

it('answers 500 and logs when updating a team fails', function () {
    $team = Team::factory()->create(['name' => 'Before Failure']);
    $headers = teamAdminHeaders();
    Team::updating(function () {
        throw new RuntimeException('update failed');
    });
    Log::spy();

    $this->putJson("/api/v1/super-admin/teams/{$team->id}", ['name' => 'After Failure'], $headers)
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Failed to update team']);

    expect($team->fresh()->name)->toBe('Before Failure');
    Log::shouldHaveReceived('error')->once()->with('Update team failed: update failed');
});

// ---------------------------------------------------------------------------
// destroy
// ---------------------------------------------------------------------------

it('refuses to delete a team that still has any user, including disabled ones', function () {
    $team = $this->createTeam();
    $this->team = $team;
    $this->createDisabledEmployee();

    $this->deleteJson("/api/v1/super-admin/teams/{$team->id}", [], teamAdminHeaders())
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Cannot delete team with existing users. Please remove or reassign users first.');

    $this->assertDatabaseHas('teams', ['id' => $team->id]);
});

it('deletes only the requested empty team', function () {
    $target = Team::factory()->create(['name' => 'Disposable']);
    $bystander = Team::factory()->create(['name' => 'Bystander']);

    $this->deleteJson("/api/v1/super-admin/teams/{$target->id}", [], teamAdminHeaders())
        ->assertOk()
        ->assertExactJson(['success' => true, 'message' => 'Team deleted successfully']);

    $this->assertDatabaseMissing('teams', ['id' => $target->id]);
    $this->assertDatabaseHas('teams', ['id' => $bystander->id, 'name' => 'Bystander']);
});

it('answers 500 and logs when deleting a team fails', function () {
    $team = Team::factory()->create();
    $headers = teamAdminHeaders();
    Team::deleting(function () {
        throw new RuntimeException('delete failed');
    });
    Log::spy();

    $this->deleteJson("/api/v1/super-admin/teams/{$team->id}", [], $headers)
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Failed to delete team']);

    $this->assertDatabaseHas('teams', ['id' => $team->id]);
    Log::shouldHaveReceived('error')->once()->with('Delete team failed: delete failed');
});
