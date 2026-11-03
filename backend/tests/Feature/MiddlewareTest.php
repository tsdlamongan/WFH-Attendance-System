<?php

use App\Enums\UserRole;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTeamAccess;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

/**
 * Run a middleware's handle() directly and report whether the pipeline continued.
 *
 * @return array{0: Response, 1: bool, 2: Request}
 */
function runMiddleware(object $middleware, ?Request $request = null, array $parameters = []): array
{
    $request ??= Request::create('/probe', 'GET');
    $called = false;

    $response = $middleware->handle($request, function (Request $passed) use (&$called) {
        $called = true;

        return response()->json(['passed' => true]);
    }, ...$parameters);

    return [$response, $called, $request];
}

function middlewareBearer(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('auth-token')->plainTextToken];
}

function createMiddlewareSuperAdmin(): User
{
    return User::factory()->create([
        'team_id' => null,
        'role' => UserRole::SUPER_ADMIN,
        'leave_quota_days' => 0,
    ]);
}

// ---------------------------------------------------------------------------
// EnsureTeamAccess
// ---------------------------------------------------------------------------

it('EnsureTeamAccess answers 401 when nobody is authenticated', function () {
    [$response, $called] = runMiddleware(new EnsureTeamAccess);

    expect($called)->toBeFalse()
        ->and($response->getStatusCode())->toBe(401)
        ->and($response->getData(true))->toBe([
            'success' => false,
            'message' => 'Unauthenticated',
        ]);
});

it('EnsureTeamAccess lets a super admin through with a null current team', function () {
    $this->actingAs(createMiddlewareSuperAdmin());
    $request = Request::create('/probe', 'GET', ['current_team_id' => 123]);

    [$response, $called, $request] = runMiddleware(new EnsureTeamAccess, $request);

    expect($called)->toBeTrue()
        ->and($response->getStatusCode())->toBe(200)
        ->and($request->has('current_team_id'))->toBeTrue()
        ->and($request->input('current_team_id'))->toBeNull();
});

it('EnsureTeamAccess blocks authenticated users without a team', function (UserRole $role) {
    $user = User::factory()->create(['team_id' => null, 'role' => $role]);
    $this->actingAs($user);

    [$response, $called] = runMiddleware(new EnsureTeamAccess);

    expect($called)->toBeFalse()
        ->and($response->getStatusCode())->toBe(403)
        ->and($response->getData(true))->toBe([
            'success' => false,
            'message' => 'User tidak tergabung dalam tim',
        ]);
})->with([
    'manager' => [UserRole::MANAGER],
    'employee' => [UserRole::EMPLOYEE],
]);

it('EnsureTeamAccess blocks members of an inactive team', function () {
    $this->team = $this->createTeam(['is_active' => false]);
    $this->actingAs($this->createEmployee());

    [$response, $called] = runMiddleware(new EnsureTeamAccess);

    expect($called)->toBeFalse()
        ->and($response->getStatusCode())->toBe(403)
        ->and($response->getData(true))->toBe([
            'success' => false,
            'message' => 'Tim Anda tidak aktif. Silakan hubungi administrator.',
        ]);
});

it('EnsureTeamAccess exposes the user team id on the request for active teams', function () {
    $this->team = $this->createTeam(['is_active' => true]);
    $employee = $this->createEmployee();
    $this->actingAs($employee);

    [$response, $called, $request] = runMiddleware(new EnsureTeamAccess);

    expect($called)->toBeTrue()
        ->and($response->getStatusCode())->toBe(200)
        ->and($request->input('current_team_id'))->toBe($employee->team_id)
        ->and($request->input('current_team_id'))->toBe($this->team->id);
});

it('EnsureTeamAccess overrides a client-supplied current_team_id (no team spoofing)', function () {
    $own = $this->createTeam();
    $this->team = $own;
    $this->actingAs($this->createManager());
    $foreign = $this->createTeam();
    $request = Request::create('/probe', 'GET', ['current_team_id' => $foreign->id]);

    [, $called, $request] = runMiddleware(new EnsureTeamAccess, $request);

    expect($called)->toBeTrue()
        ->and($request->input('current_team_id'))->toBe($own->id)
        ->and($request->input('current_team_id'))->not->toBe($foreign->id);
});

it('EnsureTeamAccess is enforced on the HTTP stack and rejects spoofed team ids', function () {
    Route::middleware(['auth:sanctum', EnsureTeamAccess::class])
        ->get('/api/v1/__team-probe', fn (Request $request) => response()->json([
            'current_team_id' => $request->input('current_team_id'),
        ]));

    $own = $this->createTeam();
    $this->team = $own;
    $manager = $this->createManager();
    $foreign = $this->createTeam();

    $this->getJson("/api/v1/__team-probe?current_team_id={$foreign->id}", middlewareBearer($manager))
        ->assertOk()
        ->assertExactJson(['current_team_id' => $own->id]);
});

it('EnsureTeamAccess lets a super admin through the HTTP stack with a null current team', function () {
    Route::middleware(['auth:sanctum', EnsureTeamAccess::class])
        ->get('/api/v1/__team-probe', fn (Request $request) => response()->json([
            'current_team_id' => $request->input('current_team_id'),
        ]));

    $foreign = $this->createTeam();

    $this->getJson("/api/v1/__team-probe?current_team_id={$foreign->id}", middlewareBearer(createMiddlewareSuperAdmin()))
        ->assertOk()
        ->assertExactJson(['current_team_id' => null]);
});

it('EnsureTeamAccess protects the real API routes for deactivated teams and teamless users', function () {
    $inactive = $this->createTeam(['is_active' => false]);
    $this->team = $inactive;
    $employee = $this->createEmployee();

    $this->getJson('/api/v1/holidays', middlewareBearer($employee))
        ->assertForbidden()
        ->assertJsonPath('message', 'Tim Anda tidak aktif. Silakan hubungi administrator.');

    $teamless = User::factory()->create(['team_id' => null, 'role' => UserRole::EMPLOYEE]);
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/holidays', middlewareBearer($teamless))
        ->assertForbidden()
        ->assertJsonPath('message', 'User tidak tergabung dalam tim');
});

// ---------------------------------------------------------------------------
// EnsureSuperAdmin
// ---------------------------------------------------------------------------

it('EnsureSuperAdmin answers 401 when nobody is authenticated', function () {
    [$response, $called] = runMiddleware(new EnsureSuperAdmin);

    expect($called)->toBeFalse()
        ->and($response->getStatusCode())->toBe(401)
        ->and($response->getData(true))->toBe([
            'success' => false,
            'message' => 'Unauthenticated',
        ]);
});

it('EnsureSuperAdmin forbids managers and employees', function (string $role) {
    $this->actingAs($role === 'manager' ? $this->createManager() : $this->createEmployee());

    [$response, $called] = runMiddleware(new EnsureSuperAdmin);

    expect($called)->toBeFalse()
        ->and($response->getStatusCode())->toBe(403)
        ->and($response->getData(true))->toBe([
            'success' => false,
            'message' => 'Unauthorized. Super admin access required.',
        ]);
})->with(['manager', 'employee']);

it('EnsureSuperAdmin lets a super admin through untouched', function () {
    $this->actingAs(createMiddlewareSuperAdmin());

    [$response, $called] = runMiddleware(new EnsureSuperAdmin);

    expect($called)->toBeTrue()
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['passed' => true]);
});

it('the super.admin alias guards routes on the HTTP stack', function () {
    Route::middleware(['auth:sanctum', 'super.admin'])
        ->get('/api/v1/__admin-probe', fn () => response()->json(['secret' => 'ok']));

    $this->getJson('/api/v1/__admin-probe')->assertUnauthorized();

    $this->getJson('/api/v1/__admin-probe', middlewareBearer(createMiddlewareSuperAdmin()))
        ->assertOk()
        ->assertExactJson(['secret' => 'ok']);
});

it('the super.admin alias rejects regular users on the HTTP stack', function () {
    Route::middleware(['auth:sanctum', 'super.admin'])
        ->get('/api/v1/__admin-probe', fn () => response()->json(['secret' => 'ok']));

    $this->getJson('/api/v1/__admin-probe', middlewareBearer($this->createManager()))
        ->assertForbidden()
        ->assertJsonPath('message', 'Unauthorized. Super admin access required.')
        ->assertJsonMissing(['secret' => 'ok']);
});

// ---------------------------------------------------------------------------
// CheckRole
// ---------------------------------------------------------------------------

it('CheckRole aborts with 401 when nobody is authenticated', function (string $role) {
    $called = false;

    try {
        (new CheckRole)->handle(Request::create('/probe', 'GET'), function () use (&$called) {
            $called = true;

            return response()->json([]);
        }, $role);
        $this->fail('Expected an HttpException');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(401)
            ->and($e->getMessage())->toBe('Unauthenticated.');
    }

    expect($called)->toBeFalse();
})->with(['manager', 'employee']);

it('CheckRole aborts with 403 when an employee requests the manager role', function () {
    $this->actingAs($this->createEmployee());

    try {
        runMiddleware(new CheckRole, null, ['manager']);
        $this->fail('Expected an HttpException');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403)
            ->and($e->getMessage())->toBe('Unauthorized. Manager role required.');
    }
});

it('CheckRole aborts with 403 when a manager requests the employee role', function () {
    $this->actingAs($this->createManager());

    try {
        runMiddleware(new CheckRole, null, ['employee']);
        $this->fail('Expected an HttpException');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403)
            ->and($e->getMessage())->toBe('Unauthorized. Employee role required.');
    }
});

it('CheckRole fails closed for a role it does not know', function (string $role, string $userRole) {
    // Regression: an unknown role (a typo like "role:admin") used to let every authenticated user in.
    $this->actingAs($userRole === 'manager' ? $this->createManager() : $this->createEmployee());
    $called = false;

    try {
        (new CheckRole)->handle(Request::create('/probe', 'GET'), function () use (&$called) {
            $called = true;

            return response()->json([]);
        }, $role);
        $this->fail('Expected an HttpException');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403)
            ->and($e->getMessage())->toBe('Unauthorized. Role not permitted.');
    }

    expect($called)->toBeFalse();
})->with([
    'manager, unknown role' => ['admin', 'manager'],
    'employee, unknown role' => ['admin', 'employee'],
    'manager, empty role' => ['', 'manager'],
    'employee, wrong case' => ['Manager', 'employee'],
]);

it('CheckRole lets users with a matching role through', function (string $role, string $userRole) {
    $this->actingAs($userRole === 'manager' ? $this->createManager() : $this->createEmployee());

    [$response, $called] = runMiddleware(new CheckRole, null, [$role]);

    expect($called)->toBeTrue()
        ->and($response->getStatusCode())->toBe(200);
})->with([
    'manager on manager route' => ['manager', 'manager'],
    'employee on employee route' => ['employee', 'employee'],
]);

it('CheckRole lets a super admin through every role gate', function (string $role) {
    $this->actingAs(createMiddlewareSuperAdmin());

    [$response, $called] = runMiddleware(new CheckRole, null, [$role]);

    expect($called)->toBeTrue()
        ->and($response->getStatusCode())->toBe(200);
})->with(['manager', 'employee']);

it('the role alias answers real routes with JSON 403 for the wrong role', function () {
    $employee = $this->createEmployee();
    $manager = $this->createManager();

    $this->getJson('/api/v1/manager/users', middlewareBearer($employee))
        ->assertForbidden()
        ->assertJsonPath('message', 'Unauthorized. Manager role required.');

    $this->app['auth']->forgetGuards();

    $this->getJson('/api/v1/attendance/today', middlewareBearer($manager))
        ->assertForbidden()
        ->assertJsonPath('message', 'Unauthorized. Employee role required.');
});

it('the role alias lets a super admin past the manager gate on real routes', function () {
    // No 403: the request reaches the controller, which has no team to show for a super admin.
    $this->getJson('/api/v1/team/settings', middlewareBearer(createMiddlewareSuperAdmin()))
        ->assertNotFound()
        ->assertJsonPath('message', 'Tim tidak ditemukan');
});

// ---------------------------------------------------------------------------
// SecurityHeadersMiddleware
// ---------------------------------------------------------------------------

it('adds the security headers to successful API responses', function () {
    $response = $this->getJson('/api/v1/auth/registration-status');

    $response->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-XSS-Protection', '1; mode=block')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=(), usb=(), magnetometer=(), gyroscope=(), speaker=()');
});

it('sends a restrictive Content-Security-Policy', function () {
    $csp = $this->getJson('/api/v1/auth/registration-status')->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("default-src 'self';")
        ->toContain("script-src 'self' 'unsafe-inline' https://www.google.com https://www.gstatic.com;")
        ->toContain("style-src 'self' 'unsafe-inline';")
        ->toContain("img-src 'self' data: https:;")
        ->toContain("font-src 'self';")
        ->toContain("connect-src 'self' ".env('FRONTEND_URL', 'http://localhost:10002').';')
        ->toContain('frame-src https://www.google.com https://recaptcha.google.com;')
        ->toContain("object-src 'none';")
        ->toContain("base-uri 'self';")
        ->toContain("form-action 'self';");
});

it('adds the security headers to error responses too', function () {
    $this->getJson('/api/v1/holidays')
        ->assertUnauthorized()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    $this->getJson('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('adds the security headers to authenticated and forbidden responses', function () {
    $this->getJson('/api/v1/super-admin/teams', middlewareBearer($this->createEmployee()))
        ->assertForbidden()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy');
});

it('does not send HSTS outside of production', function () {
    expect(app()->environment('production'))->toBeFalse();

    $this->getJson('/api/v1/auth/registration-status')
        ->assertOk()
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('sends HSTS in production', function () {
    $this->app->detectEnvironment(fn () => 'production');
    expect(app()->environment('production'))->toBeTrue();

    $this->getJson('/api/v1/auth/registration-status')
        ->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload')
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('overrides security headers set further down the stack and keeps the response intact', function () {
    $request = Request::create('/probe', 'GET');

    $response = (new SecurityHeadersMiddleware)->handle($request, fn () => response('hello', 202, [
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Custom' => 'kept',
    ]));

    expect($response->getStatusCode())->toBe(202)
        ->and($response->getContent())->toBe('hello')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('X-Custom'))->toBe('kept')
        ->and($response->headers->has('Content-Security-Policy'))->toBeTrue()
        ->and($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});
