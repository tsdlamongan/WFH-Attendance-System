<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\ImpersonateController;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

function impEdgeSuperAdmin(): User
{
    return User::factory()->create([
        'role' => UserRole::SUPER_ADMIN,
        'team_id' => null,
        'leave_quota_days' => 0,
    ]);
}

function impEdgeBearer(string $plainTextToken): array
{
    return ['Authorization' => 'Bearer '.$plainTextToken];
}

/** Every impersonation token in the database (named "impersonation:<super admin id>"). */
function impEdgeImpersonationTokens(): Builder
{
    return PersonalAccessToken::query()->where('name', 'like', 'impersonation%');
}

/*
|--------------------------------------------------------------------------
| impersonate
|--------------------------------------------------------------------------
*/

it('issues a token that really authenticates as the impersonated user and logs it', function () {
    $superAdmin = impEdgeSuperAdmin();
    $manager = $this->createManager(['email' => 'target.manager@example.com']);
    $teammate = $this->createEmployee();
    $outsider = User::factory()->create(['team_id' => $this->createTeam()->id]);
    Log::spy();

    $response = $this->postJson(
        "/api/v1/super-admin/impersonate/{$manager->id}",
        [],
        impEdgeBearer($superAdmin->createToken('auth-token')->plainTextToken)
    );

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.is_impersonating', true)
        ->assertJsonPath('data.original_user_id', $superAdmin->id)
        ->assertJsonPath('data.user.id', $manager->id)
        ->assertJsonPath('data.user.role', 'manager');

    $this->assertDatabaseHas('personal_access_tokens', [
        'tokenable_id' => $manager->id,
        'tokenable_type' => User::class,
        'name' => 'impersonation:'.$superAdmin->id,
    ]);
    Log::shouldHaveReceived('info')->with('User impersonation', [
        'super_admin_id' => $superAdmin->id,
        'super_admin_email' => $superAdmin->email,
        'target_user_id' => $manager->id,
        'target_user_email' => 'target.manager@example.com',
    ])->once();

    // The returned token must behave as the manager: scoped to the manager's own team.
    $this->app['auth']->forgetGuards();
    $users = $this->getJson('/api/v1/manager/users?per_page=100', impEdgeBearer($response->json('data.token')));

    $users->assertOk()->assertJsonPath('pagination.total', 2);
    $ids = collect($users->json('data'))->pluck('id')->all();
    expect($ids)->toContain($manager->id, $teammate->id)
        ->and($ids)->not->toContain($outsider->id, $superAdmin->id);
});

it('refuses impersonation from the controller itself when the caller is not a super admin', function () {
    $manager = $this->createManager();
    $target = $this->createEmployee();
    $this->actingAs($manager);

    $response = app(ImpersonateController::class)
        ->impersonate(Request::create('/impersonate', 'POST'), $target->id);

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true))->toBe([
            'success' => false,
            'message' => 'Unauthorized. Only super admin can impersonate users.',
        ]);
    expect(impEdgeImpersonationTokens()->count())->toBe(0);
});

it('blocks the super admin routes for non super admins at the middleware level', function () {
    $employee = $this->createEmployee();
    $target = $this->createEmployee();

    $this->postJson("/api/v1/super-admin/impersonate/{$target->id}", [], impEdgeBearer($employee->createToken('auth-token')->plainTextToken))
        ->assertForbidden()
        ->assertJsonPath('message', 'Unauthorized. Super admin access required.');

    expect(impEdgeImpersonationTokens()->count())->toBe(0);
});

it('requires authentication to impersonate or stop impersonating', function () {
    $target = $this->createEmployee();

    $this->postJson("/api/v1/super-admin/impersonate/{$target->id}")->assertUnauthorized();
    $this->postJson('/api/v1/super-admin/stop-impersonate', ['original_user_id' => 1])->assertUnauthorized();
});

it('returns a 500 json error without leaking details when impersonation blows up', function () {
    $superAdmin = impEdgeSuperAdmin();
    $target = $this->createEmployee();
    $headers = impEdgeBearer($superAdmin->createToken('auth-token')->plainTextToken);
    Log::spy();
    Log::shouldReceive('info')->once()->andThrow(new RuntimeException('log sink down'));

    $this->postJson("/api/v1/super-admin/impersonate/{$target->id}", [], $headers)
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Failed to impersonate user']);

    Log::shouldHaveReceived('error')->with('Impersonate user failed: log sink down')->once();
});

/*
|--------------------------------------------------------------------------
| stopImpersonate
|--------------------------------------------------------------------------
*/

/**
 * Start an impersonation through the API, like the SPA does, and return the response.
 */
function impEdgeStartImpersonation(User $superAdmin, User $target): TestResponse
{
    return test()->postJson(
        "/api/v1/super-admin/impersonate/{$target->id}",
        [],
        impEdgeBearer($superAdmin->createToken('auth-token')->plainTextToken)
    )->assertOk();
}

it('does not let a regular user swap their token for a super admin token', function () {
    // Regression: stop-impersonate used to trust the client-supplied original_user_id, so ANY
    // authenticated user could post a super admin's id and receive a super admin token.
    $superAdmin = impEdgeSuperAdmin();
    $attacker = $this->createEmployee();
    $headers = impEdgeBearer($attacker->createToken('auth-token')->plainTextToken);
    $tokensBefore = $superAdmin->tokens()->count();

    $this->postJson('/api/v1/super-admin/stop-impersonate', ['original_user_id' => $superAdmin->id], $headers)
        ->assertForbidden()
        ->assertExactJson(['success' => false, 'message' => 'Not currently impersonating a user']);

    expect($superAdmin->tokens()->count())->toBe($tokensBefore);
    // The attacker keeps their own session untouched.
    expect($attacker->tokens()->count())->toBe(1);
});

it('does not let a manager or an impersonated session escalate to a different super admin', function () {
    $realSuperAdmin = impEdgeSuperAdmin();
    $otherSuperAdmin = impEdgeSuperAdmin();
    $manager = $this->createManager();
    $impersonation = impEdgeStartImpersonation($realSuperAdmin, $manager);
    $this->app['auth']->forgetGuards();

    // The session really is an impersonation, but it was started by $realSuperAdmin.
    $this->postJson(
        '/api/v1/super-admin/stop-impersonate',
        ['original_user_id' => $otherSuperAdmin->id],
        impEdgeBearer($impersonation->json('data.token'))
    )->assertForbidden()->assertExactJson(['success' => false, 'message' => 'Invalid original user']);

    expect($otherSuperAdmin->tokens()->count())->toBe(0);
    // The impersonation token survives a rejected attempt.
    expect($manager->tokens()->where('name', 'impersonation:'.$realSuperAdmin->id)->count())->toBe(1);
});

it('rejects a legacy or hand-made impersonation token that does not name its super admin', function (string $name) {
    $manager = $this->createManager();
    $superAdmin = impEdgeSuperAdmin();
    $headers = impEdgeBearer($manager->createToken($name)->plainTextToken);

    $this->postJson('/api/v1/super-admin/stop-impersonate', ['original_user_id' => $superAdmin->id], $headers)
        ->assertForbidden()
        ->assertJsonPath('message', 'Not currently impersonating a user');

    expect($superAdmin->tokens()->count())->toBe(0);
})->with([
    'legacy name without id' => 'impersonation',
    'non numeric id' => 'impersonation:abc',
    'trailing garbage' => 'impersonation:1;drop',
    'wrong prefix' => 'ximpersonation:1',
]);

it('rejects a session that has no personal access token at all', function () {
    $manager = $this->createManager();
    $superAdmin = impEdgeSuperAdmin();

    // actingAs() authenticates without a token, so there is no impersonation to stop.
    $this->actingAs($manager)
        ->postJson('/api/v1/super-admin/stop-impersonate', ['original_user_id' => $superAdmin->id])
        ->assertForbidden()
        ->assertJsonPath('message', 'Not currently impersonating a user');
});

it('rejects stop-impersonate when the recorded super admin no longer qualifies', function (string $kind) {
    $manager = $this->createManager();
    $original = match ($kind) {
        'deleted' => null,
        'demoted' => $this->createEmployee(),
        'disabled' => tap(impEdgeSuperAdmin(), fn (User $user) => $user->forceFill(['is_disabled' => true])->save()),
    };
    $originalId = $original?->id ?? 999999;
    $headers = impEdgeBearer($manager->createToken('impersonation:'.$originalId)->plainTextToken);

    $this->postJson('/api/v1/super-admin/stop-impersonate', [], $headers)
        ->assertForbidden()
        ->assertExactJson(['success' => false, 'message' => 'Invalid original user']);

    // The impersonation token must survive a rejected attempt.
    expect($manager->tokens()->where('name', 'impersonation:'.$originalId)->count())->toBe(1);
})->with(['deleted', 'demoted', 'disabled']);

it('rejects a claimed original_user_id that disagrees with the token, however it is shaped', function (mixed $claimed) {
    $superAdmin = impEdgeSuperAdmin();
    $manager = $this->createManager();
    $token = impEdgeStartImpersonation($superAdmin, $manager)->json('data.token');
    $this->app['auth']->forgetGuards();

    $this->postJson('/api/v1/super-admin/stop-impersonate', ['original_user_id' => $claimed], impEdgeBearer($token))
        ->assertForbidden()
        ->assertExactJson(['success' => false, 'message' => 'Invalid original user']);

    expect($manager->tokens()->where('name', 'impersonation:'.$superAdmin->id)->count())->toBe(1);
})->with([
    'another id' => 999999,
    'text' => 'abc',
    'array' => [[1, 2]],
]);

it('does not need the client to send original_user_id: the token already says who it is', function () {
    $superAdmin = impEdgeSuperAdmin();
    $manager = $this->createManager();
    $token = impEdgeStartImpersonation($superAdmin, $manager)->json('data.token');
    $this->app['auth']->forgetGuards();

    foreach ([[], ['original_user_id' => '']] as $index => $body) {
        // Only the first request may succeed (it revokes the token), so mint a token per round.
        if ($index > 0) {
            $token = impEdgeStartImpersonation($superAdmin, $manager)->json('data.token');
            $this->app['auth']->forgetGuards();
        }

        $this->postJson('/api/v1/super-admin/stop-impersonate', $body, impEdgeBearer($token))
            ->assertOk()
            ->assertJsonPath('data.user.id', $superAdmin->id)
            ->assertJsonPath('data.is_impersonating', false);
        $this->app['auth']->forgetGuards();
    }
});

it('revokes the impersonation token and issues a fresh super admin token when stopping', function () {
    $superAdmin = impEdgeSuperAdmin();
    $manager = $this->createManager();

    $impersonation = impEdgeStartImpersonation($superAdmin, $manager);
    $impersonationToken = $impersonation->json('data.token');
    $impersonationTokenId = (int) explode('|', $impersonationToken)[0];

    $this->app['auth']->forgetGuards();
    $stop = $this->postJson(
        '/api/v1/super-admin/stop-impersonate',
        ['original_user_id' => $superAdmin->id],
        impEdgeBearer($impersonationToken)
    );

    $stop->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Stopped impersonating user')
        ->assertJsonPath('data.is_impersonating', false)
        ->assertJsonPath('data.user.id', $superAdmin->id)
        ->assertJsonPath('data.user.role', 'super_admin');

    expect(PersonalAccessToken::find($impersonationTokenId))->toBeNull();
    expect(impEdgeImpersonationTokens()->count())->toBe(0);

    // The old impersonation token is dead, the new one works for the super admin.
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/holidays', impEdgeBearer($impersonationToken))->assertUnauthorized();

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/super-admin/teams', impEdgeBearer($stop->json('data.token')))->assertOk();
});

it('returns a 500 json error when stopping impersonation blows up', function () {
    $superAdmin = impEdgeSuperAdmin();
    $manager = $this->createManager();
    $headers = impEdgeBearer($manager->createToken('impersonation:'.$superAdmin->id)->plainTextToken);
    Log::spy();
    Log::shouldReceive('info')->once()->andThrow(new RuntimeException('log sink down'));

    $this->postJson('/api/v1/super-admin/stop-impersonate', ['original_user_id' => $superAdmin->id], $headers)
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Failed to stop impersonation']);

    Log::shouldHaveReceived('error')->with('Stop impersonate failed: log sink down')->once();
});
