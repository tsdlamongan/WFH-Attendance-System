<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Requests\RegisterRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\RecaptchaService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

const REGISTER_URL = '/api/v1/auth/register';

/**
 * A fully valid registration payload; $overrides replace or (with null) blank fields.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Budi Santoso',
        'email' => 'budi@example.com',
        'password' => 'Secret@123',
        'password_confirmation' => 'Secret@123',
        'team_name' => 'Tim Alpha',
        'captcha_token' => 'test-captcha-token',
    ], $overrides);
}

/**
 * Errors reported for one field of a validation response.
 *
 * @return array<int, string>
 */
function registerErrorsFor(TestResponse $response, string $field): array
{
    return $response->json("errors.{$field}") ?? [];
}

// ---------------------------------------------------------------------------
// Successful registration
// ---------------------------------------------------------------------------

it('registers a manager together with a new team using the default team settings', function () {
    $response = $this->postJson(REGISTER_URL, registerPayload());

    $response->assertCreated()
        ->assertJson([
            'success' => true,
            'message' => 'Registrasi berhasil! Tim Anda telah dibuat.',
        ])
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'user' => ['id', 'name', 'email', 'role', 'team_id', 'team' => ['id', 'name', 'slug'], 'leave_quota_days'],
                'token',
                'team' => ['id', 'name', 'slug', 'required_work_hours', 'default_leave_quota_days', 'max_leave_days_per_month'],
            ],
        ])
        ->assertJsonPath('data.user.name', 'Budi Santoso')
        ->assertJsonPath('data.user.email', 'budi@example.com')
        ->assertJsonPath('data.user.role', 'manager')
        ->assertJsonPath('data.user.is_disabled', false)
        ->assertJsonPath('data.team.name', 'Tim Alpha')
        ->assertJsonPath('data.team.slug', 'tim-alpha')
        ->assertJsonPath('data.team.default_leave_quota_days', Team::DEFAULT_LEAVE_QUOTA_DAYS)
        ->assertJsonPath('data.team.max_leave_days_per_month', Team::DEFAULT_MAX_LEAVE_DAYS_PER_MONTH);

    expect((float) $response->json('data.team.required_work_hours'))->toBe(Team::DEFAULT_REQUIRED_WORK_HOURS);

    $team = Team::where('name', 'Tim Alpha')->firstOrFail();
    expect($team->is_active)->toBeTrue()
        ->and($team->slug)->toBe('tim-alpha')
        ->and($team->default_leave_quota_days)->toBe(Team::DEFAULT_LEAVE_QUOTA_DAYS)
        ->and($team->max_leave_days_per_month)->toBe(Team::DEFAULT_MAX_LEAVE_DAYS_PER_MONTH)
        ->and((float) $team->required_work_hours)->toBe(Team::DEFAULT_REQUIRED_WORK_HOURS);

    $user = User::where('email', 'budi@example.com')->firstOrFail();
    expect($user->team_id)->toBe($team->id)
        ->and($user->role)->toBe(UserRole::MANAGER)
        ->and($user->leave_quota_days)->toBe(Team::DEFAULT_LEAVE_QUOTA_DAYS)
        ->and($user->is_disabled)->toBeFalse();

    expect($response->json('data.user.id'))->toBe($user->id)
        ->and($response->json('data.user.team_id'))->toBe($team->id);
});

it('stores the supplied optional team settings and derives the manager leave quota from them', function () {
    $response = $this->postJson(REGISTER_URL, registerPayload([
        'team_description' => 'Tim remote untuk produk A',
        'required_work_hours' => 8.5,
        'default_leave_quota_days' => 20,
        'max_leave_days_per_month' => 3,
    ]));

    $response->assertCreated()
        ->assertJsonPath('data.team.default_leave_quota_days', 20)
        ->assertJsonPath('data.team.max_leave_days_per_month', 3)
        ->assertJsonPath('data.user.leave_quota_days', 20);

    expect((float) $response->json('data.team.required_work_hours'))->toBe(8.5);

    $team = Team::where('name', 'Tim Alpha')->firstOrFail();
    expect($team->description)->toBe('Tim remote untuk produk A')
        ->and($team->default_leave_quota_days)->toBe(20)
        ->and($team->max_leave_days_per_month)->toBe(3)
        ->and((float) $team->required_work_hours)->toBe(8.5);

    expect(User::where('email', 'budi@example.com')->value('leave_quota_days'))->toBe(20);
});

it('accepts zero as a valid leave quota instead of falling back to the default', function () {
    $this->postJson(REGISTER_URL, registerPayload([
        'default_leave_quota_days' => 0,
        'max_leave_days_per_month' => 0,
    ]))->assertCreated()
        ->assertJsonPath('data.team.default_leave_quota_days', 0)
        ->assertJsonPath('data.team.max_leave_days_per_month', 0)
        ->assertJsonPath('data.user.leave_quota_days', 0);
});

it('falls back to the defaults when optional settings are sent as null', function () {
    $this->postJson(REGISTER_URL, registerPayload([
        'team_description' => null,
        'required_work_hours' => null,
        'default_leave_quota_days' => null,
        'max_leave_days_per_month' => null,
    ]))->assertCreated()
        ->assertJsonPath('data.team.default_leave_quota_days', Team::DEFAULT_LEAVE_QUOTA_DAYS)
        ->assertJsonPath('data.team.max_leave_days_per_month', Team::DEFAULT_MAX_LEAVE_DAYS_PER_MONTH);

    expect(Team::where('name', 'Tim Alpha')->value('description'))->toBeNull();
});

it('hashes the password and never exposes it in the response', function () {
    $response = $this->postJson(REGISTER_URL, registerPayload());

    $response->assertCreated();

    $stored = User::where('email', 'budi@example.com')->value('password');
    expect($stored)->not->toBe('Secret@123')
        ->and(Hash::check('Secret@123', $stored))->toBeTrue();

    expect($response->getContent())->not->toContain('Secret@123')
        ->and($response->getContent())->not->toContain($stored);
});

it('issues a working bearer token for the new manager and persists it', function () {
    $response = $this->postJson(REGISTER_URL, registerPayload());
    $response->assertCreated();

    $user = User::where('email', 'budi@example.com')->firstOrFail();
    $this->assertDatabaseHas('personal_access_tokens', [
        'tokenable_id' => $user->id,
        'tokenable_type' => User::class,
        'name' => 'auth_token',
    ]);
    expect($user->tokens()->count())->toBe(1);

    $token = $response->json('data.token');
    expect($token)->toBeString()->not->toBeEmpty();

    // The manager-only team settings endpoint accepts the freshly issued token.
    $this->getJson('/api/v1/team/settings', ['Authorization' => "Bearer {$token}"])
        ->assertOk();
});

it('lets the newly registered manager log in with the chosen credentials', function () {
    $this->postJson(REGISTER_URL, registerPayload())->assertCreated();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'budi@example.com',
        'password' => 'Secret@123',
        'captcha_token' => 'test-captcha-token',
    ])->assertOk()
        ->assertJsonPath('data.user.role', 'manager')
        ->assertJsonPath('data.user.team.slug', 'tim-alpha');
});

it('gives teams that share a name unique slugs', function () {
    $this->postJson(REGISTER_URL, registerPayload())->assertCreated()
        ->assertJsonPath('data.team.slug', 'tim-alpha');

    $this->postJson(REGISTER_URL, registerPayload(['email' => 'siti@example.com']))->assertCreated()
        ->assertJsonPath('data.team.slug', 'tim-alpha-1');

    $this->postJson(REGISTER_URL, registerPayload(['email' => 'andi@example.com']))->assertCreated()
        ->assertJsonPath('data.team.slug', 'tim-alpha-2');

    expect(Team::count())->toBe(3)
        ->and(User::count())->toBe(3);
});

it('ignores privileged fields such as role, team_id and is_disabled sent by the client', function () {
    $existingTeam = Team::factory()->create();

    $response = $this->postJson(REGISTER_URL, registerPayload([
        'role' => 'super_admin',
        'team_id' => $existingTeam->id,
        'is_disabled' => true,
        'leave_quota_days' => 999,
    ]));

    $response->assertCreated()
        ->assertJsonPath('data.user.role', 'manager')
        ->assertJsonPath('data.user.is_disabled', false);

    $user = User::where('email', 'budi@example.com')->firstOrFail();
    expect($user->role)->toBe(UserRole::MANAGER)
        ->and($user->team_id)->not->toBe($existingTeam->id)
        ->and($user->is_disabled)->toBeFalse()
        ->and($user->leave_quota_days)->toBe(Team::DEFAULT_LEAVE_QUOTA_DAYS);
});

it('logs that a new manager registered', function () {
    Log::spy();

    $this->postJson(REGISTER_URL, registerPayload())->assertCreated();

    $user = User::where('email', 'budi@example.com')->firstOrFail();

    Log::shouldHaveReceived('info')
        ->with('New manager registered', [
            'user_id' => $user->id,
            'team_id' => $user->team_id,
            'email' => 'budi@example.com',
        ])
        ->once();
});

it('accepts names with hyphens, apostrophes, dots and spaces', function (string $name) {
    $this->postJson(REGISTER_URL, registerPayload(['name' => $name]))
        ->assertCreated()
        ->assertJsonPath('data.user.name', $name);
})->with([
    'hyphen' => 'Anne-Marie',
    'apostrophe' => "Patrick O'Brien",
    'dot' => 'Dr. Jane Doe',
    'plain' => 'Siti Aminah',
]);

it('accepts a password that has exactly the minimum length and every required character class', function () {
    $this->postJson(REGISTER_URL, registerPayload([
        'password' => 'Abcdef1@',
        'password_confirmation' => 'Abcdef1@',
    ]))->assertCreated();
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

it('requires every mandatory field', function () {
    $response = $this->postJson(REGISTER_URL, []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'password', 'team_name', 'captcha_token']);

    expect(registerErrorsFor($response, 'name'))->toContain('Nama wajib diisi')
        ->and(registerErrorsFor($response, 'email'))->toContain('Email wajib diisi')
        ->and(registerErrorsFor($response, 'password'))->toContain('Password wajib diisi')
        ->and(registerErrorsFor($response, 'team_name'))->toContain('Nama tim wajib diisi');

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('teams', 0);
});

it('does not run the captcha check while the captcha token itself is missing', function () {
    $this->mock(RecaptchaService::class)->shouldNotReceive('verify');

    $this->postJson(REGISTER_URL, registerPayload(['captcha_token' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['captcha_token']);
});

it('rejects invalid input with the localized message', function (array $overrides, string $field, string $message) {
    $response = $this->postJson(REGISTER_URL, registerPayload($overrides));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(registerErrorsFor($response, $field))->toContain($message);

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('teams', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([
    'name with digits' => [['name' => 'John123'], 'name', 'Nama hanya boleh mengandung huruf, spasi, tanda hubung, dan apostrof'],
    'name with symbols' => [['name' => 'Bob <script>'], 'name', 'Nama hanya boleh mengandung huruf, spasi, tanda hubung, dan apostrof'],
    'email without domain' => [['email' => 'not-an-email'], 'email', 'Format email tidak valid'],
    'email missing at sign' => [['email' => 'budi.example.com'], 'email', 'Format email tidak valid'],
    'password too short' => [['password' => 'Ab1@xyz', 'password_confirmation' => 'Ab1@xyz'], 'password', 'Password minimal 8 karakter'],
    'password too long' => [['password' => 'Aa1@'.str_repeat('x', 252), 'password_confirmation' => 'Aa1@'.str_repeat('x', 252)], 'password', 'Password maksimal 255 karakter'],
    'password not confirmed' => [['password_confirmation' => 'Different@123'], 'password', 'Konfirmasi password tidak cocok'],
    'password confirmation missing' => [['password_confirmation' => null], 'password', 'Konfirmasi password tidak cocok'],
    'password without uppercase' => [['password' => 'secret@123', 'password_confirmation' => 'secret@123'], 'password', 'Password harus mengandung minimal 1 huruf kecil, 1 huruf besar, 1 angka, dan 1 karakter spesial (@$!%*#?&)'],
    'password without lowercase' => [['password' => 'SECRET@123', 'password_confirmation' => 'SECRET@123'], 'password', 'Password harus mengandung minimal 1 huruf kecil, 1 huruf besar, 1 angka, dan 1 karakter spesial (@$!%*#?&)'],
    'password without digit' => [['password' => 'Secret@abc', 'password_confirmation' => 'Secret@abc'], 'password', 'Password harus mengandung minimal 1 huruf kecil, 1 huruf besar, 1 angka, dan 1 karakter spesial (@$!%*#?&)'],
    'password without special char' => [['password' => 'Secret1234', 'password_confirmation' => 'Secret1234'], 'password', 'Password harus mengandung minimal 1 huruf kecil, 1 huruf besar, 1 angka, dan 1 karakter spesial (@$!%*#?&)'],
    'work hours not numeric' => [['required_work_hours' => 'banyak'], 'required_work_hours', 'Jam kerja harus berupa angka'],
    'work hours below one' => [['required_work_hours' => 0.5], 'required_work_hours', 'Jam kerja minimal 1 jam'],
    'work hours above 24' => [['required_work_hours' => 25], 'required_work_hours', 'Jam kerja maksimal 24 jam'],
    'leave quota not integer' => [['default_leave_quota_days' => 'dua belas'], 'default_leave_quota_days', 'Jatah cuti harus berupa angka'],
    'leave quota fractional' => [['default_leave_quota_days' => 12.5], 'default_leave_quota_days', 'Jatah cuti harus berupa angka'],
    'leave quota negative' => [['default_leave_quota_days' => -1], 'default_leave_quota_days', 'Jatah cuti minimal 0 hari'],
    'leave quota above 365' => [['default_leave_quota_days' => 366], 'default_leave_quota_days', 'Jatah cuti maksimal 365 hari'],
    'monthly leave not integer' => [['max_leave_days_per_month' => 'lima'], 'max_leave_days_per_month', 'Maksimal cuti per bulan harus berupa angka'],
    'monthly leave negative' => [['max_leave_days_per_month' => -2], 'max_leave_days_per_month', 'Maksimal cuti per bulan minimal 0 hari'],
    'monthly leave above 31' => [['max_leave_days_per_month' => 32], 'max_leave_days_per_month', 'Maksimal cuti per bulan maksimal 31 hari'],
]);

it('rejects over-long or non-string values on the fields that only have default messages', function (array $overrides, string $field) {
    $this->postJson(REGISTER_URL, registerPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    $this->assertDatabaseCount('teams', 0);
})->with([
    'name longer than 255' => [['name' => str_repeat('a', 256)], 'name'],
    'email longer than 255' => [['email' => str_repeat('a', 250).'@example.com'], 'email'],
    'team name longer than 255' => [['team_name' => str_repeat('t', 256)], 'team_name'],
    'team description longer than 1000' => [['team_description' => str_repeat('d', 1001)], 'team_description'],
    'team name as array' => [['team_name' => ['x']], 'team_name'],
]);

it('rejects an email that is already registered, whatever the role of the existing account', function () {
    User::factory()->create(['email' => 'budi@example.com']);
    $teamsBefore = Team::count();

    $response = $this->postJson(REGISTER_URL, registerPayload());

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
    expect(registerErrorsFor($response, 'email'))->toContain('Email sudah terdaftar');

    // No orphan team was created for the rejected registration.
    expect(Team::count())->toBe($teamsBefore)
        ->and(User::count())->toBe(1);
});

it('accepts the boundary values of every optional numeric setting', function () {
    $this->postJson(REGISTER_URL, registerPayload([
        'required_work_hours' => 24,
        'default_leave_quota_days' => 365,
        'max_leave_days_per_month' => 31,
    ]))->assertCreated()
        ->assertJsonPath('data.team.default_leave_quota_days', 365)
        ->assertJsonPath('data.team.max_leave_days_per_month', 31);

    $this->postJson(REGISTER_URL, registerPayload([
        'email' => 'low@example.com',
        'team_name' => 'Tim Low',
        'required_work_hours' => 1,
        'default_leave_quota_days' => 0,
        'max_leave_days_per_month' => 0,
    ]))->assertCreated();
});

// ---------------------------------------------------------------------------
// reCAPTCHA
// ---------------------------------------------------------------------------

it('verifies the submitted captcha token before creating anything', function () {
    $this->mock(RecaptchaService::class)
        ->shouldReceive('verify')
        ->once()
        ->with('captcha-from-client')
        ->andReturn(true);

    $this->postJson(REGISTER_URL, registerPayload(['captcha_token' => 'captcha-from-client']))
        ->assertCreated();
});

it('rejects the registration when the captcha verification fails', function () {
    $this->mock(RecaptchaService::class)
        ->shouldReceive('verify')
        ->once()
        ->with('bad-captcha')
        ->andReturn(false);

    $response = $this->postJson(REGISTER_URL, registerPayload(['captcha_token' => 'bad-captcha']));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['captcha_token']);
    expect(registerErrorsFor($response, 'captcha_token'))
        ->toContain('Verifikasi reCAPTCHA gagal. Silakan coba lagi.');

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('teams', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('verifies a captcha token that PHP would treat as falsy instead of skipping the check', function () {
    // "0" is falsy in PHP; it must go through verify() like any other token.
    $this->mock(RecaptchaService::class)
        ->shouldReceive('verify')
        ->once()
        ->with('0')
        ->andReturn(false);

    $response = $this->postJson(REGISTER_URL, registerPayload(['captcha_token' => '0']));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['captcha_token']);
    expect(registerErrorsFor($response, 'captcha_token'))
        ->toContain('Verifikasi reCAPTCHA gagal. Silakan coba lagi.');

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('teams', 0);
});

it('lets the verifier decide about a "0" captcha token', function () {
    $this->mock(RecaptchaService::class)
        ->shouldReceive('verify')
        ->once()
        ->with('0')
        ->andReturn(true);

    $this->postJson(REGISTER_URL, registerPayload(['captcha_token' => '0']))
        ->assertCreated();
});

it('answers 422 instead of crashing and never calls the verifier for a non-string captcha token', function (mixed $token) {
    $this->mock(RecaptchaService::class)->shouldNotReceive('verify');

    $this->postJson(REGISTER_URL, registerPayload(['captcha_token' => $token]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['captcha_token']);

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('teams', 0);
})->with([
    'array' => [['x']],
    'nested object' => [['a' => ['b' => 1]]],
    'integer' => [12345],
    'boolean' => [true],
]);

it('reports a failed captcha next to the other validation errors', function () {
    $this->mock(RecaptchaService::class)->shouldReceive('verify')->andReturn(false);

    $response = $this->postJson(REGISTER_URL, registerPayload([
        'captcha_token' => 'bad-captcha',
        'email' => 'invalid',
    ]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'captcha_token']);
});

// ---------------------------------------------------------------------------
// Registration feature flag
// ---------------------------------------------------------------------------

it('blocks registration at the middleware when the feature is disabled', function () {
    config(['app.registration.enabled' => false]);

    $this->postJson(REGISTER_URL, registerPayload())
        ->assertForbidden()
        ->assertJson([
            'success' => false,
            'message' => 'Registration is currently disabled',
            'errors' => [
                'registration' => ['Pendaftaran akun baru sedang dinonaktifkan'],
            ],
        ]);

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('teams', 0);
});

it('blocks a disabled registration before the payload is even validated', function () {
    config(['app.registration.enabled' => false]);

    // An empty payload would normally be a 422; the feature flag wins with a 403.
    $this->postJson(REGISTER_URL, [])->assertForbidden();
});

it('allows registration when the feature flag is explicitly enabled', function () {
    config(['app.registration.enabled' => true]);

    $this->postJson(REGISTER_URL, registerPayload())->assertCreated();
});

it('lets the controller refuse a registration on its own when the flag is off', function () {
    // The route middleware normally stops these requests first; this checks the
    // controller's defence-in-depth guard when it is reached some other way.
    config(['app.registration.enabled' => false]);

    $request = RegisterRequest::create(REGISTER_URL, 'POST', registerPayload());

    $response = app(RegisterController::class)->register($request);

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true))->toBe([
            'success' => false,
            'message' => 'Pendaftaran akun baru sedang dinonaktifkan.',
            'errors' => [
                'registration' => ['Pendaftaran akun baru sedang dinonaktifkan'],
            ],
        ]);

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('teams', 0);
});

it('reports the registration status as enabled', function () {
    config(['app.registration.enabled' => true]);

    $this->getJson('/api/v1/auth/registration-status')
        ->assertOk()
        ->assertExactJson([
            'enabled' => true,
            'message' => 'Registration is enabled',
        ]);
});

it('reports the registration status as disabled', function () {
    config(['app.registration.enabled' => false]);

    $this->getJson('/api/v1/auth/registration-status')
        ->assertOk()
        ->assertExactJson([
            'enabled' => false,
            'message' => 'Registration is disabled',
        ]);
});

// ---------------------------------------------------------------------------
// Failure handling
// ---------------------------------------------------------------------------

it('rolls everything back and answers 500 when creating the manager fails', function () {
    User::creating(function () {
        throw new RuntimeException('database exploded: secret internals');
    });
    Log::spy();

    $response = $this->postJson(REGISTER_URL, registerPayload());

    $response->assertStatus(500)
        ->assertExactJson([
            'success' => false,
            'message' => 'Registrasi gagal. Silakan coba lagi.',
        ]);

    // The team created earlier in the same transaction must not survive.
    $this->assertDatabaseCount('teams', 0);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);

    // Internals are logged, not leaked to the client.
    expect($response->getContent())->not->toContain('secret internals');
    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message) => str_contains($message, 'Registration failed: database exploded'))
        ->once();
    Log::shouldNotHaveReceived('info', ['New manager registered', Mockery::any()]);
});

it('answers 500 and leaves no data behind when the team itself cannot be created', function () {
    Team::creating(function () {
        throw new RuntimeException('cannot create team');
    });

    $this->postJson(REGISTER_URL, registerPayload())
        ->assertStatus(500)
        ->assertJsonPath('message', 'Registrasi gagal. Silakan coba lagi.');

    $this->assertDatabaseCount('teams', 0);
    $this->assertDatabaseCount('users', 0);
});
