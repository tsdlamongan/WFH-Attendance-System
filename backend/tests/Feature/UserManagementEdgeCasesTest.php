<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

function umEdgeHeaders(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('auth-token')->plainTextToken];
}

function umEdgeSuperAdmin(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'role' => UserRole::SUPER_ADMIN,
        'team_id' => null,
        'leave_quota_days' => 0,
    ], $attributes));
}

function umEdgeValidPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Brand New User',
        'email' => 'brand.new@example.com',
        'password' => 'Secret#123',
        'password_confirmation' => 'Secret#123',
        'role' => 'employee',
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| index
|--------------------------------------------------------------------------
*/

it('lists only users of the manager team and reports matching pagination totals', function () {
    $manager = $this->createManager();
    $teammate = $this->createEmployee(['name' => 'Same Team Member']);
    $otherTeam = $this->createTeam();
    $outsider = User::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Outsider Person']);

    $response = $this->getJson('/api/v1/manager/users', umEdgeHeaders($manager));

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('pagination.total', 2)
        ->assertJsonPath('pagination.from', 1)
        ->assertJsonPath('pagination.to', 2);

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($manager->id, $teammate->id)
        ->and($ids)->not->toContain($outsider->id);
});

it('lets a super admin list users across every team', function () {
    $superAdmin = umEdgeSuperAdmin();
    $teamA = $this->createTeam();
    $teamB = $this->createTeam();
    $userA = User::factory()->create(['team_id' => $teamA->id]);
    $userB = User::factory()->create(['team_id' => $teamB->id]);

    $response = $this->getJson('/api/v1/manager/users?per_page=100', umEdgeHeaders($superAdmin));

    $response->assertOk()->assertJsonPath('pagination.total', 3);
    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain($userA->id, $userB->id, $superAdmin->id);
});

it('falls back to 10 per page when per_page is not an allowed value', function () {
    $manager = $this->createManager();
    User::factory()->count(12)->create(['team_id' => $manager->team_id]);

    $response = $this->getJson('/api/v1/manager/users?per_page=25', umEdgeHeaders($manager));

    $response->assertOk()
        ->assertJsonPath('pagination.per_page', 10)
        ->assertJsonPath('pagination.last_page', 2)
        ->assertJsonCount(10, 'data');
});

it('returns a 500 json error and logs when listing users fails', function () {
    $manager = $this->createManager();
    $headers = umEdgeHeaders($manager);
    Log::spy();
    $this->mock(UserRepository::class, function (MockInterface $mock) {
        $mock->shouldReceive('getPaginated')->once()->andThrow(new RuntimeException('db down'));
    });

    $this->getJson('/api/v1/manager/users', $headers)
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Failed to get users']);

    Log::shouldHaveReceived('error')->with('Get users failed: db down')->once();
});

/*
|--------------------------------------------------------------------------
| store
|--------------------------------------------------------------------------
*/

it('rejects user creation by a super admin when no team is given', function () {
    $superAdmin = umEdgeSuperAdmin();

    $this->postJson('/api/v1/manager/users', umEdgeValidPayload(), umEdgeHeaders($superAdmin))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['team_id' => 'Team ID is required for super admin'])
        ->assertJsonPath('message', 'Team ID is required for super admin');

    $this->assertDatabaseMissing('users', ['email' => 'brand.new@example.com']);
});

it('lets a super admin create a user inside the team given in the request', function () {
    $superAdmin = umEdgeSuperAdmin();
    $targetTeam = $this->createTeam(['default_leave_quota_days' => 18]);

    $this->postJson(
        '/api/v1/manager/users',
        umEdgeValidPayload(['team_id' => $targetTeam->id, 'role' => 'manager']),
        umEdgeHeaders($superAdmin)
    )
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'User created successfully')
        ->assertJsonPath('data.team_id', $targetTeam->id)
        ->assertJsonPath('data.role', 'manager')
        ->assertJsonPath('data.leave_quota_days', 18);

    $this->assertDatabaseHas('users', ['email' => 'brand.new@example.com', 'team_id' => $targetTeam->id]);
});

it('rejects a super admin creating a user in a team that does not exist or has a malformed id', function (mixed $teamId) {
    $superAdmin = umEdgeSuperAdmin();

    $this->postJson('/api/v1/manager/users', umEdgeValidPayload(['team_id' => $teamId]), umEdgeHeaders($superAdmin))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['team_id']);

    $this->assertDatabaseMissing('users', ['email' => 'brand.new@example.com']);
})->with([
    'unknown team' => [987654],
    'not a number' => ['abc'],
    'array' => [[1]],
]);

it('does not require or validate team_id for a manager and never lets it override their own team', function () {
    $manager = $this->createManager();

    $this->postJson(
        '/api/v1/manager/users',
        umEdgeValidPayload(['team_id' => 987654]),
        umEdgeHeaders($manager)
    )
        ->assertCreated()
        ->assertJsonPath('data.team_id', $manager->team_id);

    $this->assertDatabaseHas('users', ['email' => 'brand.new@example.com', 'team_id' => $manager->team_id]);
});

it('does not let a super admin move a user to another team through the update endpoint', function () {
    $superAdmin = umEdgeSuperAdmin();
    $employee = $this->createEmployee(['name' => 'Stays Put']);
    $otherTeam = $this->createTeam();

    $this->putJson("/api/v1/manager/users/{$employee->id}", [
        'name' => 'Stays Put',
        'email' => $employee->email,
        'role' => 'employee',
        'team_id' => $otherTeam->id,
    ], umEdgeHeaders($superAdmin))->assertOk();

    expect($employee->fresh()->team_id)->toBe($this->team->id);
});

it('always creates the user inside the manager team and ignores a spoofed team_id', function () {
    $manager = $this->createManager();
    $otherTeam = $this->createTeam();

    $response = $this->postJson(
        '/api/v1/manager/users',
        umEdgeValidPayload(['team_id' => $otherTeam->id]),
        umEdgeHeaders($manager)
    );

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'User created successfully')
        ->assertJsonPath('data.team_id', $manager->team_id)
        ->assertJsonPath('data.role', 'employee');

    $this->assertDatabaseHas('users', ['email' => 'brand.new@example.com', 'team_id' => $manager->team_id]);
    $this->assertDatabaseMissing('users', ['team_id' => $otherTeam->id]);
    expect(Hash::check('Secret#123', User::where('email', 'brand.new@example.com')->value('password')))->toBeTrue();
});

it('uses the team default leave quota when none is supplied', function () {
    $this->team = $this->createTeam(['default_leave_quota_days' => 20]);
    $manager = $this->createManager();

    $this->postJson('/api/v1/manager/users', umEdgeValidPayload(), umEdgeHeaders($manager))
        ->assertCreated()
        ->assertJsonPath('data.leave_quota_days', 20);

    $this->assertDatabaseHas('users', ['email' => 'brand.new@example.com', 'leave_quota_days' => 20]);
});

it('keeps an explicit leave quota, including zero', function () {
    $this->team = $this->createTeam(['default_leave_quota_days' => 20]);
    $manager = $this->createManager();

    $this->postJson('/api/v1/manager/users', umEdgeValidPayload(['leave_quota_days' => 0]), umEdgeHeaders($manager))
        ->assertCreated()
        ->assertJsonPath('data.leave_quota_days', 0);

    $this->assertDatabaseHas('users', ['email' => 'brand.new@example.com', 'leave_quota_days' => 0]);
});

it('validates the create user payload', function (array $overrides, string $field) {
    $manager = $this->createManager();

    $this->postJson('/api/v1/manager/users', umEdgeValidPayload($overrides), umEdgeHeaders($manager))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);

    $this->assertDatabaseMissing('users', ['email' => 'brand.new@example.com']);
})->with([
    'name with digits' => [['name' => 'Agent 007'], 'name'],
    'missing name' => [['name' => ''], 'name'],
    'malformed email' => [['email' => 'not-an-email'], 'email'],
    'role super_admin is not assignable' => [['role' => 'super_admin'], 'role'],
    'unknown role' => [['role' => 'ceo'], 'role'],
    'password confirmation mismatch' => [['password_confirmation' => 'Different#123'], 'password'],
    'password without special char' => [['password' => 'Secret1234', 'password_confirmation' => 'Secret1234'], 'password'],
    'password without uppercase' => [['password' => 'secret#123', 'password_confirmation' => 'secret#123'], 'password'],
    'password without digit' => [['password' => 'Secret#abc', 'password_confirmation' => 'Secret#abc'], 'password'],
    'leave quota above a year' => [['leave_quota_days' => 366], 'leave_quota_days'],
    'negative leave quota' => [['leave_quota_days' => -1], 'leave_quota_days'],
]);

it('returns a 500 json error and logs when creating a user fails', function () {
    $manager = $this->createManager();
    $headers = umEdgeHeaders($manager);
    Log::spy();
    $this->mock(UserRepository::class, function (MockInterface $mock) {
        $mock->shouldReceive('create')->once()->andThrow(new RuntimeException('insert failed'));
    });

    $this->postJson('/api/v1/manager/users', umEdgeValidPayload(), $headers)
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Failed to create user']);

    Log::shouldHaveReceived('error')->with('Create user failed: insert failed')->once();
    $this->assertDatabaseMissing('users', ['email' => 'brand.new@example.com']);
});

/*
|--------------------------------------------------------------------------
| update
|--------------------------------------------------------------------------
*/

it('returns 404 when updating a user that does not exist', function () {
    $manager = $this->createManager();

    $this->putJson('/api/v1/manager/users/999999', umEdgeValidPayload(), umEdgeHeaders($manager))
        ->assertStatus(404)
        ->assertExactJson(['success' => false, 'message' => 'User not found']);
});

it('forbids a manager from updating a user of another team', function () {
    $manager = $this->createManager();
    $otherTeam = $this->createTeam();
    $outsider = User::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Original Name']);

    $this->putJson("/api/v1/manager/users/{$outsider->id}", umEdgeValidPayload(['name' => 'Hijacked Name']), umEdgeHeaders($manager))
        ->assertStatus(403)
        ->assertExactJson(['success' => false, 'message' => 'Unauthorized to update this user']);

    $this->assertDatabaseHas('users', ['id' => $outsider->id, 'name' => 'Original Name']);
});

it('lets a super admin update a user of any team', function () {
    $superAdmin = umEdgeSuperAdmin();
    $otherTeam = $this->createTeam();
    $employee = User::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Before Update']);

    $this->putJson("/api/v1/manager/users/{$employee->id}", umEdgeValidPayload([
        'name' => 'After Update',
        'email' => $employee->email,
        'role' => 'manager',
    ]), umEdgeHeaders($superAdmin))
        ->assertOk()
        ->assertJsonPath('message', 'User updated successfully')
        ->assertJsonPath('data.name', 'After Update')
        ->assertJsonPath('data.role', 'manager')
        ->assertJsonPath('data.team_id', $otherTeam->id);

    $this->assertDatabaseHas('users', ['id' => $employee->id, 'name' => 'After Update', 'role' => 'manager']);
});

it('keeps the existing password when none is sent on update', function () {
    $manager = $this->createManager();
    $employee = $this->createEmployee(['password' => Hash::make('Original#123')]);
    $oldHash = $employee->password;

    $this->putJson("/api/v1/manager/users/{$employee->id}", [
        'name' => 'Renamed Employee',
        'email' => $employee->email,
        'role' => 'employee',
    ], umEdgeHeaders($manager))
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed Employee');

    expect($employee->fresh()->password)->toBe($oldHash)
        ->and(Hash::check('Original#123', $employee->fresh()->password))->toBeTrue();
});

it('rejects updating a user with the email of someone else but allows keeping its own', function () {
    $manager = $this->createManager();
    $employee = $this->createEmployee(['email' => 'mine@example.com']);
    $other = $this->createEmployee(['email' => 'taken@example.com']);
    $headers = umEdgeHeaders($manager);

    $this->putJson("/api/v1/manager/users/{$employee->id}", [
        'name' => 'Some Name',
        'email' => $other->email,
        'role' => 'employee',
    ], $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    $this->putJson("/api/v1/manager/users/{$employee->id}", [
        'name' => 'Some Name',
        'email' => 'mine@example.com',
        'role' => 'employee',
    ], $headers)->assertOk();

    $this->assertDatabaseHas('users', ['id' => $employee->id, 'email' => 'mine@example.com', 'name' => 'Some Name']);
});

it('returns a 500 json error and logs when updating a user fails', function () {
    $manager = $this->createManager();
    $employee = $this->createEmployee(['name' => 'Untouched Name']);
    $headers = umEdgeHeaders($manager);
    Log::spy();
    $this->partialMock(UserRepository::class, function (MockInterface $mock) {
        $mock->shouldReceive('update')->once()->andThrow(new RuntimeException('update failed'));
    });

    $this->putJson("/api/v1/manager/users/{$employee->id}", [
        'name' => 'Changed Name',
        'email' => $employee->email,
        'role' => 'employee',
    ], $headers)
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Failed to update user']);

    Log::shouldHaveReceived('error')->with('Update user failed: update failed')->once();
    $this->assertDatabaseHas('users', ['id' => $employee->id, 'name' => 'Untouched Name']);
});

/*
|--------------------------------------------------------------------------
| destroy
|--------------------------------------------------------------------------
*/

it('returns 404 when deleting a user that does not exist', function () {
    $manager = $this->createManager();

    $this->deleteJson('/api/v1/manager/users/999999', [], umEdgeHeaders($manager))
        ->assertStatus(404)
        ->assertExactJson(['success' => false, 'message' => 'User not found']);
});

it('forbids a manager from deleting a user of another team', function () {
    $manager = $this->createManager();
    $otherTeam = $this->createTeam();
    $outsider = User::factory()->create(['team_id' => $otherTeam->id]);

    $this->deleteJson("/api/v1/manager/users/{$outsider->id}", [], umEdgeHeaders($manager))
        ->assertStatus(403)
        ->assertExactJson(['success' => false, 'message' => 'Unauthorized to delete this user']);

    $this->assertDatabaseHas('users', ['id' => $outsider->id]);
});

it('lets a super admin delete a user of any team', function () {
    $superAdmin = umEdgeSuperAdmin();
    $otherTeam = $this->createTeam();
    $employee = User::factory()->create(['team_id' => $otherTeam->id]);

    $this->deleteJson("/api/v1/manager/users/{$employee->id}", [], umEdgeHeaders($superAdmin))
        ->assertOk()
        ->assertExactJson(['success' => true, 'message' => 'User deleted successfully']);

    $this->assertDatabaseMissing('users', ['id' => $employee->id]);
});

it('returns a 500 json error and logs when deleting a user fails', function () {
    $manager = $this->createManager();
    $employee = $this->createEmployee();
    $headers = umEdgeHeaders($manager);
    Log::spy();
    $this->partialMock(UserRepository::class, function (MockInterface $mock) {
        $mock->shouldReceive('delete')->once()->andThrow(new RuntimeException('delete failed'));
    });

    $this->deleteJson("/api/v1/manager/users/{$employee->id}", [], $headers)
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Failed to delete user']);

    Log::shouldHaveReceived('error')->with('Delete user failed: delete failed')->once();
    $this->assertDatabaseHas('users', ['id' => $employee->id]);
});

/*
|--------------------------------------------------------------------------
| toggleDisabled
|--------------------------------------------------------------------------
*/

it('returns 404 when toggling a user that does not exist', function () {
    $manager = $this->createManager();

    $this->patchJson('/api/v1/manager/users/999999/toggle-disabled', [], umEdgeHeaders($manager))
        ->assertStatus(404)
        ->assertExactJson(['success' => false, 'message' => 'User not found']);
});

it('lets a super admin disable a user of another team and revokes that user tokens', function () {
    $superAdmin = umEdgeSuperAdmin();
    $otherTeam = $this->createTeam();
    $employee = User::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Remote Employee']);
    $employee->createToken('auth-token');
    $employee->createToken('mobile');

    $this->patchJson("/api/v1/manager/users/{$employee->id}/toggle-disabled", [], umEdgeHeaders($superAdmin))
        ->assertOk()
        ->assertJsonPath('message', 'User dinonaktifkan')
        ->assertJsonPath('data.is_disabled', true);

    expect($employee->tokens()->count())->toBe(0);
    $this->assertDatabaseHas('users', ['id' => $employee->id, 'is_disabled' => true]);
    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $superAdmin->id,
        'action' => 'user_disabled',
        'description' => 'Disabled user Remote Employee',
    ]);
});

it('re-enables a disabled user without touching tokens and logs the activity once', function () {
    $manager = $this->createManager();
    $disabled = $this->createDisabledEmployee(['name' => 'Back Again']);

    $this->patchJson("/api/v1/manager/users/{$disabled->id}/toggle-disabled", [], umEdgeHeaders($manager))
        ->assertOk()
        ->assertJsonPath('message', 'User diaktifkan')
        ->assertJsonPath('data.is_disabled', false);

    $this->assertDatabaseHas('users', ['id' => $disabled->id, 'is_disabled' => false]);
    // LogUserActivity skips toggle-disabled, so only the controller writes a log entry.
    $this->assertDatabaseCount('activity_logs', 1);
    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $manager->id,
        'action' => 'user_enabled',
        'description' => 'Enabled user Back Again',
    ]);
});

it('stops a disabled user from using a previously issued token', function () {
    $manager = $this->createManager();
    $employee = $this->createEmployee();
    $employeeHeaders = umEdgeHeaders($employee);

    $this->patchJson("/api/v1/manager/users/{$employee->id}/toggle-disabled", [], umEdgeHeaders($manager))
        ->assertOk();

    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/holidays', $employeeHeaders)->assertUnauthorized();
});

it('returns a 500 json error and logs when toggling a user fails', function () {
    $manager = $this->createManager();
    $employee = $this->createEmployee();
    $headers = umEdgeHeaders($manager);
    Log::spy();
    $this->partialMock(UserRepository::class, function (MockInterface $mock) {
        $mock->shouldReceive('findById')->once()->andThrow(new RuntimeException('lookup failed'));
    });

    $this->patchJson("/api/v1/manager/users/{$employee->id}/toggle-disabled", [], $headers)
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Failed to toggle user status']);

    Log::shouldHaveReceived('error')->with('Toggle disabled failed: lookup failed')->once();
    $this->assertDatabaseHas('users', ['id' => $employee->id, 'is_disabled' => false]);
});

/*
|--------------------------------------------------------------------------
| search
|--------------------------------------------------------------------------
*/

it('searches by email and only inside the manager team', function () {
    $manager = $this->createManager();
    $teammate = $this->createEmployee(['name' => 'Alice Teammate', 'email' => 'alice.team@example.com']);
    $otherTeam = $this->createTeam();
    User::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Alice Outsider', 'email' => 'alice.out@example.com']);

    $response = $this->getJson('/api/v1/manager/users/search?q=alice', umEdgeHeaders($manager));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $teammate->id);

    $this->getJson('/api/v1/manager/users/search?q=alice.team@', umEdgeHeaders($manager))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'alice.team@example.com');
});

it('clamps the search limit between 1 and 50', function () {
    $manager = $this->createManager(['name' => 'Zed Manager']);
    User::factory()->count(55)->create(['team_id' => $manager->team_id, 'name' => 'Bulk Person']);
    $headers = umEdgeHeaders($manager);

    $this->getJson('/api/v1/manager/users/search?q=bulk&limit=0', $headers)
        ->assertOk()->assertJsonCount(1, 'data');

    $this->getJson('/api/v1/manager/users/search?q=bulk&limit=500', $headers)
        ->assertOk()->assertJsonCount(50, 'data');

    $this->getJson('/api/v1/manager/users/search?q=bulk&limit=3', $headers)
        ->assertOk()->assertJsonCount(3, 'data');
});

it('answers 422 instead of crashing when the search limit is not an integer', function (string $query) {
    $manager = $this->createManager();
    $this->createEmployee(['name' => 'Findable Person']);

    $this->getJson("/api/v1/manager/users/search?q=findable&{$query}", umEdgeHeaders($manager))
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonValidationErrors(['limit'])
        ->assertJsonMissingPath('exception');
})->with([
    'letters' => ['limit=abc'],
    'decimal' => ['limit=2.5'],
    'array' => ['limit[]=1'],
    'overflow' => ['limit=99999999999999999999'],
]);

it('falls back to the default limit when the search limit is left empty', function () {
    $manager = $this->createManager();
    $this->createEmployee(['name' => 'Findable Person']);

    $this->getJson('/api/v1/manager/users/search?q=findable&limit=', umEdgeHeaders($manager))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('answers 422 instead of crashing when the search term is not a string', function () {
    $manager = $this->createManager();

    $this->getJson('/api/v1/manager/users/search?q[]=alice', umEdgeHeaders($manager))
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonValidationErrors(['q']);
});

it('answers an empty result for search terms that are empty once sanitised', function (string $term) {
    $manager = $this->createManager();
    $this->createEmployee(['name' => 'Wildcard Target']);

    $this->getJson('/api/v1/manager/users/search?q='.urlencode($term), umEdgeHeaders($manager))
        ->assertOk()
        ->assertExactJson(['success' => true, 'data' => []]);
})->with([
    'only a wildcard' => ['%'],
    'only symbols' => ['!!!'],
    'wildcard and quote' => ["%'"],
]);

it('strips sql wildcards and symbols so they cannot match every user', function () {
    $manager = $this->createManager();
    $this->createEmployee(['name' => 'Wildcard Target']);

    // "%" is stripped, leaving "wd" which is not a substring of "wildcard target".
    $this->getJson('/api/v1/manager/users/search?q='.urlencode('w%d'), umEdgeHeaders($manager))
        ->assertOk()
        ->assertExactJson(['success' => true, 'data' => []]);

    $this->getJson('/api/v1/manager/users/search?q='.urlencode("'; DROP TABLE users; --"), umEdgeHeaders($manager))
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('users', ['name' => 'Wildcard Target']);
});

it('returns a 500 json error and logs when searching users fails', function () {
    $manager = $this->createManager();
    $headers = umEdgeHeaders($manager);
    Log::spy();
    $this->mock(UserRepository::class, function (MockInterface $mock) {
        $mock->shouldReceive('searchByName')->once()->andThrow(new RuntimeException('search failed'));
    });

    $this->getJson('/api/v1/manager/users/search?q=anything', $headers)
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Failed to search users']);

    Log::shouldHaveReceived('error')->with('Search users failed: search failed')->once();
});

/*
|--------------------------------------------------------------------------
| authorization
|--------------------------------------------------------------------------
*/

it('rejects unauthenticated access to every user management route', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    'index' => ['GET', '/api/v1/manager/users'],
    'search' => ['GET', '/api/v1/manager/users/search?q=a'],
    'store' => ['POST', '/api/v1/manager/users'],
    'update' => ['PUT', '/api/v1/manager/users/1'],
    'toggle' => ['PATCH', '/api/v1/manager/users/1/toggle-disabled'],
    'destroy' => ['DELETE', '/api/v1/manager/users/1'],
]);

it('forbids employees from every user management route and leaves data untouched', function (string $method, string $uri) {
    $employee = $this->createEmployee(['name' => 'Plain Employee']);
    $victim = $this->createEmployee(['name' => 'Victim']);
    $uri = str_replace('{id}', (string) $victim->id, $uri);

    $this->json($method, $uri, [], umEdgeHeaders($employee))->assertForbidden();

    $this->assertDatabaseHas('users', ['id' => $victim->id, 'name' => 'Victim', 'is_disabled' => false]);
})->with([
    'index' => ['GET', '/api/v1/manager/users'],
    'store' => ['POST', '/api/v1/manager/users'],
    'update' => ['PUT', '/api/v1/manager/users/{id}'],
    'toggle' => ['PATCH', '/api/v1/manager/users/{id}/toggle-disabled'],
    'destroy' => ['DELETE', '/api/v1/manager/users/{id}'],
]);
