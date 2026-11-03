<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\TeamSettingsController;
use App\Http\Requests\TeamSettingsRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

function teamSettingsHeaders(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('auth-token')->plainTextToken];
}

function createTeamSettingsSuperAdmin(): User
{
    return User::factory()->create([
        'team_id' => null,
        'role' => UserRole::SUPER_ADMIN,
        'leave_quota_days' => 0,
    ]);
}

// ---------------------------------------------------------------------------
// GET /api/v1/team/settings
// ---------------------------------------------------------------------------

it('lets a manager view the settings of their own team', function () {
    $team = $this->createTeam([
        'name' => 'Alpha Team',
        'description' => 'Alpha description',
        'required_work_hours' => 7.5,
        'default_leave_quota_days' => 14,
        'max_leave_days_per_month' => 4,
        'is_active' => true,
    ]);
    $this->team = $team;
    $manager = $this->createManager();

    $response = $this->getJson('/api/v1/team/settings', teamSettingsHeaders($manager));

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $team->id)
        ->assertJsonPath('data.name', 'Alpha Team')
        ->assertJsonPath('data.slug', $team->slug)
        ->assertJsonPath('data.description', 'Alpha description')
        ->assertJsonPath('data.required_work_hours', '7.50')
        ->assertJsonPath('data.default_leave_quota_days', 14)
        ->assertJsonPath('data.max_leave_days_per_month', 4)
        ->assertJsonPath('data.check_in_window_start', '09:00')
        ->assertJsonPath('data.check_in_window_end', '10:00')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.created_at', $team->created_at->toIso8601String());
});

it('formats the check-in window as HH:MM when viewing settings', function () {
    $team = $this->createTeam();
    $this->team = $team;
    $team->forceFill([
        'check_in_window_start' => '08:15:30',
        'check_in_window_end' => '11:45:59',
    ])->save();
    $manager = $this->createManager();

    $this->getJson('/api/v1/team/settings', teamSettingsHeaders($manager))
        ->assertOk()
        ->assertJsonPath('data.check_in_window_start', '08:15')
        ->assertJsonPath('data.check_in_window_end', '11:45');
});

it('only exposes the settings of the manager\'s own team, never another team', function () {
    $own = $this->createTeam(['name' => 'Own Team']);
    $this->team = $own;
    $manager = $this->createManager();
    $other = $this->createTeam(['name' => 'Other Team']);

    $response = $this->getJson('/api/v1/team/settings', teamSettingsHeaders($manager));

    $response->assertOk()
        ->assertJsonPath('data.id', $own->id)
        ->assertJsonPath('data.name', 'Own Team')
        ->assertJsonMissing(['name' => 'Other Team'])
        ->assertJsonMissing(['id' => $other->id, 'name' => 'Other Team']);
});

it('does not leak WhatsApp credentials in the team settings response', function () {
    $team = $this->createTeam([
        'whatsapp_api_secret' => 'super-secret-value',
        'whatsapp_account_unique_id' => 'acc-123',
        'whatsapp_recipient_phone' => '628123456789',
    ]);
    $this->team = $team;
    $manager = $this->createManager();

    $response = $this->getJson('/api/v1/team/settings', teamSettingsHeaders($manager));

    $response->assertOk();
    expect($response->json('data'))->not->toHaveKeys([
        'whatsapp_api_secret',
        'whatsapp_account_unique_id',
        'whatsapp_recipient_phone',
    ]);
    expect($response->getContent())->not->toContain('super-secret-value');
});

// ---------------------------------------------------------------------------
// Authentication / authorization (route middleware + EnsureTeamAccess)
// ---------------------------------------------------------------------------

it('rejects unauthenticated access to team settings with 401', function () {
    $this->getJson('/api/v1/team/settings')->assertUnauthorized();
    $this->putJson('/api/v1/team/settings', ['name' => 'Hacked'])->assertUnauthorized();
});

it('forbids employees from viewing or changing team settings', function () {
    $employee = $this->createEmployee();
    $headers = teamSettingsHeaders($employee);
    $originalName = $this->team->name;

    $this->getJson('/api/v1/team/settings', $headers)
        ->assertForbidden()
        ->assertJsonPath('message', 'Unauthorized. Manager role required.');

    $this->putJson('/api/v1/team/settings', ['name' => 'Employee Takeover'], $headers)
        ->assertForbidden()
        ->assertJsonPath('message', 'Unauthorized. Manager role required.');

    $this->assertDatabaseHas('teams', ['id' => $this->team->id, 'name' => $originalName]);
    $this->assertDatabaseMissing('teams', ['name' => 'Employee Takeover']);
});

it('blocks managers whose team has been deactivated', function () {
    $team = $this->createTeam(['is_active' => false]);
    $this->team = $team;
    $manager = $this->createManager();
    $headers = teamSettingsHeaders($manager);

    $this->getJson('/api/v1/team/settings', $headers)
        ->assertForbidden()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Tim Anda tidak aktif. Silakan hubungi administrator.');

    $this->putJson('/api/v1/team/settings', ['name' => 'Revived'], $headers)
        ->assertForbidden();

    $this->assertDatabaseMissing('teams', ['name' => 'Revived']);
});

it('blocks managers who do not belong to any team', function () {
    $manager = User::factory()->create([
        'team_id' => null,
        'role' => UserRole::MANAGER,
    ]);

    $this->getJson('/api/v1/team/settings', teamSettingsHeaders($manager))
        ->assertForbidden()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'User tidak tergabung dalam tim');
});

it('answers 404 when a super admin without a team opens team settings', function () {
    $superAdmin = createTeamSettingsSuperAdmin();
    $headers = teamSettingsHeaders($superAdmin);

    $this->getJson('/api/v1/team/settings', $headers)
        ->assertNotFound()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Tim tidak ditemukan');

    $this->putJson('/api/v1/team/settings', ['name' => 'Ghost'], $headers)
        ->assertNotFound()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Tim tidak ditemukan');

    $this->assertDatabaseMissing('teams', ['name' => 'Ghost']);
});

it('answers 404 from the controller when the authenticated user has no resolvable team', function () {
    $manager = $this->createManager();
    $manager->setRelation('team', null);
    $this->actingAs($manager);

    $response = app(TeamSettingsController::class)->show();

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getData(true))->toBe([
            'success' => false,
            'message' => 'Tim tidak ditemukan',
        ]);
});

it('answers 500 and logs the error when reading team settings blows up', function () {
    $manager = $this->createManager();
    $brokenTeam = new class extends Team
    {
        public function getNameAttribute(): string
        {
            throw new RuntimeException('database exploded');
        }
    };
    $manager->setRelation('team', $brokenTeam);
    $this->actingAs($manager);
    Log::spy();

    $response = app(TeamSettingsController::class)->show();

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getData(true))->toBe([
            'success' => false,
            'message' => 'Gagal mengambil pengaturan tim',
        ]);
    Log::shouldHaveReceived('error')
        ->once()
        ->with('Get team settings failed: database exploded');
});

// ---------------------------------------------------------------------------
// PUT /api/v1/team/settings - success paths
// ---------------------------------------------------------------------------

it('lets a manager update every team setting at once', function () {
    $team = $this->createTeam(['name' => 'Old Name', 'description' => 'Old']);
    $this->team = $team;
    $manager = $this->createManager();

    $response = $this->putJson('/api/v1/team/settings', [
        'name' => 'New Name',
        'description' => 'New description',
        'required_work_hours' => 6.5,
        'default_leave_quota_days' => 20,
        'max_leave_days_per_month' => 7,
        'check_in_window_start' => '07:30',
        'check_in_window_end' => '09:45',
    ], teamSettingsHeaders($manager));

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Pengaturan tim berhasil diperbarui')
        ->assertJsonPath('data.id', $team->id)
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.description', 'New description')
        ->assertJsonPath('data.required_work_hours', '6.50')
        ->assertJsonPath('data.default_leave_quota_days', 20)
        ->assertJsonPath('data.max_leave_days_per_month', 7)
        ->assertJsonPath('data.check_in_window_start', '07:30')
        ->assertJsonPath('data.check_in_window_end', '09:45')
        ->assertJsonPath('data.is_active', true);

    $fresh = $team->fresh();
    expect($fresh->name)->toBe('New Name')
        ->and($fresh->description)->toBe('New description')
        ->and($fresh->required_work_hours)->toBe('6.50')
        ->and($fresh->default_leave_quota_days)->toBe(20)
        ->and($fresh->max_leave_days_per_month)->toBe(7)
        ->and(substr($fresh->check_in_window_start, 0, 5))->toBe('07:30')
        ->and(substr($fresh->check_in_window_end, 0, 5))->toBe('09:45');
});

it('applies a partial update and leaves the other settings untouched', function () {
    $team = $this->createTeam([
        'name' => 'Stable Team',
        'required_work_hours' => 8,
        'default_leave_quota_days' => 12,
        'max_leave_days_per_month' => 5,
    ]);
    $this->team = $team;
    $manager = $this->createManager();
    $slug = $team->slug;

    $this->putJson('/api/v1/team/settings', ['required_work_hours' => 5], teamSettingsHeaders($manager))
        ->assertOk()
        ->assertJsonPath('data.required_work_hours', '5.00')
        ->assertJsonPath('data.name', 'Stable Team')
        ->assertJsonPath('data.default_leave_quota_days', 12)
        ->assertJsonPath('data.check_in_window_start', '09:00')
        ->assertJsonPath('data.check_in_window_end', '10:00');

    $fresh = $team->fresh();
    expect($fresh->name)->toBe('Stable Team')
        ->and($fresh->slug)->toBe($slug)
        ->and($fresh->required_work_hours)->toBe('5.00')
        ->and($fresh->default_leave_quota_days)->toBe(12)
        ->and($fresh->max_leave_days_per_month)->toBe(5);
});

it('does not regenerate the slug when the team is renamed through settings', function () {
    $team = $this->createTeam(['name' => 'Slug Team']);
    $this->team = $team;
    $manager = $this->createManager();
    $slug = $team->slug;

    $this->putJson('/api/v1/team/settings', ['name' => 'Renamed Team'], teamSettingsHeaders($manager))
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed Team')
        ->assertJsonPath('data.slug', $slug);

    expect($team->fresh()->slug)->toBe($slug);
});

it('allows clearing the description with null', function () {
    $team = $this->createTeam(['description' => 'Something to remove']);
    $this->team = $team;
    $manager = $this->createManager();

    $this->putJson('/api/v1/team/settings', ['description' => null], teamSettingsHeaders($manager))
        ->assertOk()
        ->assertJsonPath('data.description', null);

    expect($team->fresh()->description)->toBeNull();
});

it('accepts an empty payload as a no-op update', function () {
    $team = $this->createTeam(['name' => 'Untouched']);
    $this->team = $team;
    $manager = $this->createManager();

    $this->putJson('/api/v1/team/settings', [], teamSettingsHeaders($manager))
        ->assertOk()
        ->assertJsonPath('data.name', 'Untouched');

    expect($team->fresh()->name)->toBe('Untouched');
});

it('accepts the boundary values of every numeric and time setting', function (array $payload) {
    $manager = $this->createManager();

    $this->putJson('/api/v1/team/settings', $payload, teamSettingsHeaders($manager))
        ->assertOk()
        ->assertJsonPath('success', true);
})->with([
    'min work hours' => [['required_work_hours' => 1]],
    'max work hours' => [['required_work_hours' => 24]],
    'fractional work hours' => [['required_work_hours' => 7.25]],
    'zero leave quota' => [['default_leave_quota_days' => 0]],
    'max leave quota' => [['default_leave_quota_days' => 365]],
    'zero monthly leave' => [['max_leave_days_per_month' => 0]],
    'max monthly leave' => [['max_leave_days_per_month' => 31]],
    'one minute window' => [['check_in_window_start' => '09:00', 'check_in_window_end' => '09:01']],
    'full day window' => [['check_in_window_start' => '00:00', 'check_in_window_end' => '23:59']],
    'name at max length' => [['name' => str_repeat('n', 255)]],
    'description at max length' => [['description' => str_repeat('d', 1000)]],
]);

it('ignores attributes that are not part of the settings whitelist', function () {
    $team = $this->createTeam(['name' => 'Locked Team', 'is_active' => true]);
    $this->team = $team;
    $manager = $this->createManager();
    $otherTeam = $this->createTeam();
    $slug = $team->slug;

    $this->putJson('/api/v1/team/settings', [
        'name' => 'Legit Rename',
        'is_active' => false,
        'slug' => 'hijacked-slug',
        'id' => 99999,
        'whatsapp_connected' => true,
        'whatsapp_recipient_phone' => '628000000000',
    ], teamSettingsHeaders($manager))
        ->assertOk()
        ->assertJsonPath('data.name', 'Legit Rename')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.id', $team->id);

    $fresh = $team->fresh();
    expect($fresh->name)->toBe('Legit Rename')
        ->and($fresh->is_active)->toBeTrue()
        ->and($fresh->slug)->toBe($slug)
        ->and($fresh->whatsapp_connected)->toBeFalse()
        ->and($fresh->whatsapp_recipient_phone)->toBeNull()
        ->and($otherTeam->fresh()->name)->not->toBe('Legit Rename');
});

it('updates only the manager\'s own team and leaves other teams alone', function () {
    $own = $this->createTeam(['name' => 'Own', 'required_work_hours' => 7]);
    $this->team = $own;
    $manager = $this->createManager();
    $other = $this->createTeam(['name' => 'Other', 'required_work_hours' => 7]);

    $this->putJson('/api/v1/team/settings', [
        'name' => 'Own Updated',
        'required_work_hours' => 4,
    ], teamSettingsHeaders($manager))->assertOk();

    expect($own->fresh()->name)->toBe('Own Updated')
        ->and($other->fresh()->name)->toBe('Other')
        ->and($other->fresh()->required_work_hours)->toBe('7.00');
});

it('logs who updated the team settings', function () {
    $team = $this->createTeam();
    $this->team = $team;
    $manager = $this->createManager();
    Log::spy();

    $this->putJson('/api/v1/team/settings', ['name' => 'Logged Rename'], teamSettingsHeaders($manager))
        ->assertOk();

    Log::shouldHaveReceived('info')
        ->once()
        ->with('Team settings updated', [
            'team_id' => $team->id,
            'updated_by' => $manager->id,
        ]);
});

// ---------------------------------------------------------------------------
// PUT /api/v1/team/settings - validation (TeamSettingsRequest::rules/messages)
// ---------------------------------------------------------------------------

it('rejects invalid settings with 422 and a localized message', function (array $payload, string $field, string $message) {
    $team = $this->createTeam(['name' => 'Pristine']);
    $this->team = $team;
    $manager = $this->createManager();

    $this->putJson('/api/v1/team/settings', $payload, teamSettingsHeaders($manager))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field => $message]);

    expect($team->fresh()->name)->toBe('Pristine');
})->with([
    'name too long' => [['name' => str_repeat('a', 256)], 'name', 'Nama tim maksimal 255 karakter'],
    'name not a string' => [['name' => ['array']], 'name', 'Nama tim harus berupa teks'],
    'work hours not numeric' => [['required_work_hours' => 'abc'], 'required_work_hours', 'Jam kerja harus berupa angka'],
    'work hours below one' => [['required_work_hours' => 0.5], 'required_work_hours', 'Jam kerja minimal 1 jam'],
    'work hours above 24' => [['required_work_hours' => 25], 'required_work_hours', 'Jam kerja maksimal 24 jam'],
    'leave quota not integer' => [['default_leave_quota_days' => 1.5], 'default_leave_quota_days', 'Jatah cuti harus berupa angka'],
    'leave quota negative' => [['default_leave_quota_days' => -1], 'default_leave_quota_days', 'Jatah cuti minimal 0 hari'],
    'leave quota above 365' => [['default_leave_quota_days' => 366], 'default_leave_quota_days', 'Jatah cuti maksimal 365 hari'],
    'monthly leave not integer' => [['max_leave_days_per_month' => 'many'], 'max_leave_days_per_month', 'Maksimal cuti per bulan harus berupa angka'],
    'monthly leave negative' => [['max_leave_days_per_month' => -3], 'max_leave_days_per_month', 'Maksimal cuti per bulan minimal 0 hari'],
    'monthly leave above 31' => [['max_leave_days_per_month' => 32], 'max_leave_days_per_month', 'Maksimal cuti per bulan maksimal 31 hari'],
    'start not a time' => [['check_in_window_start' => '9am'], 'check_in_window_start', 'Waktu mulai check-in harus dalam format HH:MM'],
    'start hour out of range' => [['check_in_window_start' => '25:00'], 'check_in_window_start', 'Waktu mulai check-in harus dalam format HH:MM'],
    'start with seconds' => [['check_in_window_start' => '09:00:00'], 'check_in_window_start', 'Waktu mulai check-in harus dalam format HH:MM'],
    'end not a time' => [['check_in_window_end' => 'noon'], 'check_in_window_end', 'Waktu akhir check-in harus dalam format HH:MM'],
    'end before start' => [['check_in_window_start' => '10:00', 'check_in_window_end' => '09:00'], 'check_in_window_end', 'Waktu akhir check-in harus setelah waktu mulai'],
    'end equal to start' => [['check_in_window_start' => '09:00', 'check_in_window_end' => '09:00'], 'check_in_window_end', 'Waktu akhir check-in harus setelah waktu mulai'],
]);

it('compares a lone window side with the stored other side and refuses an inverted window', function (array $payload, string $field, string $message) {
    $team = $this->createTeam(['check_in_window_start' => '09:00:00', 'check_in_window_end' => '10:00:00']);
    $this->team = $team;
    $manager = $this->createManager();

    $this->putJson('/api/v1/team/settings', $payload, teamSettingsHeaders($manager))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field => $message]);

    $fresh = $team->fresh();
    expect(substr($fresh->check_in_window_start, 0, 5))->toBe('09:00')
        ->and(substr($fresh->check_in_window_end, 0, 5))->toBe('10:00');
})->with([
    'end before stored start' => [['check_in_window_end' => '08:00'], 'check_in_window_end', 'Waktu akhir check-in harus setelah waktu mulai'],
    'end equal to stored start' => [['check_in_window_end' => '09:00'], 'check_in_window_end', 'Waktu akhir check-in harus setelah waktu mulai'],
    'start after stored end' => [['check_in_window_start' => '11:00'], 'check_in_window_start', 'Waktu mulai check-in harus sebelum waktu akhir'],
    'start equal to stored end' => [['check_in_window_start' => '10:00'], 'check_in_window_start', 'Waktu mulai check-in harus sebelum waktu akhir'],
]);

it('accepts a lone window side that stays consistent with the stored other side', function (array $payload, string $expectedStart, string $expectedEnd) {
    $team = $this->createTeam(['check_in_window_start' => '09:00:00', 'check_in_window_end' => '10:00:00']);
    $this->team = $team;
    $manager = $this->createManager();

    $this->putJson('/api/v1/team/settings', $payload, teamSettingsHeaders($manager))
        ->assertOk()
        ->assertJsonPath('data.check_in_window_start', $expectedStart)
        ->assertJsonPath('data.check_in_window_end', $expectedEnd);

    $fresh = $team->fresh();
    expect(substr($fresh->check_in_window_start, 0, 5))->toBe($expectedStart)
        ->and(substr($fresh->check_in_window_end, 0, 5))->toBe($expectedEnd);
})->with([
    'end after stored start' => [['check_in_window_end' => '09:01'], '09:00', '09:01'],
    'late end' => [['check_in_window_end' => '23:59'], '09:00', '23:59'],
    'start before stored end' => [['check_in_window_start' => '09:59'], '09:59', '10:00'],
    'early start' => [['check_in_window_start' => '00:00'], '00:00', '10:00'],
]);

it('only compares the two window sides with each other when both are sent', function () {
    $team = $this->createTeam(['check_in_window_start' => '09:00:00', 'check_in_window_end' => '10:00:00']);
    $this->team = $team;
    $manager = $this->createManager();
    $headers = teamSettingsHeaders($manager);

    // Both sides replace the stored window, so the stored values are irrelevant to the comparison.
    $this->putJson('/api/v1/team/settings', [
        'check_in_window_start' => '13:00',
        'check_in_window_end' => '14:00',
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.check_in_window_start', '13:00')
        ->assertJsonPath('data.check_in_window_end', '14:00');

    // Still refuses an inverted window even though it would be valid against the stored values.
    $this->putJson('/api/v1/team/settings', [
        'check_in_window_start' => '12:00',
        'check_in_window_end' => '11:00',
    ], $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['check_in_window_end' => 'Waktu akhir check-in harus setelah waktu mulai']);

    expect(substr($team->fresh()->check_in_window_start, 0, 5))->toBe('13:00')
        ->and(substr($team->fresh()->check_in_window_end, 0, 5))->toBe('14:00');
});

it('leaves the window comparison to the controller when the user has no team', function () {
    $superAdmin = createTeamSettingsSuperAdmin();

    $this->putJson('/api/v1/team/settings', ['check_in_window_end' => '08:00'], teamSettingsHeaders($superAdmin))
        ->assertNotFound()
        ->assertExactJson(['success' => false, 'message' => 'Tim tidak ditemukan']);
});

it('rejects a description longer than 1000 characters', function () {
    $team = $this->createTeam(['description' => 'Short']);
    $this->team = $team;
    $manager = $this->createManager();

    $this->putJson('/api/v1/team/settings', ['description' => str_repeat('d', 1001)], teamSettingsHeaders($manager))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['description']);

    expect($team->fresh()->description)->toBe('Short');
});

it('reports every invalid field in a single 422 response', function () {
    $manager = $this->createManager();

    $this->putJson('/api/v1/team/settings', [
        'required_work_hours' => 100,
        'default_leave_quota_days' => -5,
        'max_leave_days_per_month' => 99,
        'check_in_window_start' => 'bad',
    ], teamSettingsHeaders($manager))
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'required_work_hours',
            'default_leave_quota_days',
            'max_leave_days_per_month',
            'check_in_window_start',
        ]);
});

// ---------------------------------------------------------------------------
// PUT /api/v1/team/settings - failure handling
// ---------------------------------------------------------------------------

it('answers 500, logs the error and keeps data intact when saving fails', function () {
    $team = $this->createTeam(['name' => 'Before']);
    $this->team = $team;
    $manager = $this->createManager();
    $headers = teamSettingsHeaders($manager);
    Team::updating(function () {
        throw new RuntimeException('write failed');
    });
    Log::spy();

    $this->putJson('/api/v1/team/settings', ['name' => 'After'], $headers)
        ->assertStatus(500)
        ->assertExactJson([
            'success' => false,
            'message' => 'Gagal memperbarui pengaturan tim',
        ]);

    expect($team->fresh()->name)->toBe('Before');
    Log::shouldHaveReceived('error')
        ->once()
        ->with('Update team settings failed: write failed');
    Log::shouldNotHaveReceived('info', ['Team settings updated', Mockery::any()]);
});

// ---------------------------------------------------------------------------
// TeamSettingsRequest::authorize
// ---------------------------------------------------------------------------

it('authorizes managers and super admins but not employees or guests to update team settings', function () {
    $request = new TeamSettingsRequest;

    expect($request->authorize())->toBeFalse();

    $this->actingAs($this->createEmployee());
    expect($request->authorize())->toBeFalse();

    $this->actingAs($this->createManager());
    expect($request->authorize())->toBeTrue();

    $this->actingAs(createTeamSettingsSuperAdmin());
    expect($request->authorize())->toBeTrue();
});
