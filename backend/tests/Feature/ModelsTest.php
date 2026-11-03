<?php

use App\Enums\ActivityType;
use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveQuota;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-03-11 14:00:00'));
});

// ---------------------------------------------------------------------------
// Team
// ---------------------------------------------------------------------------

describe('Team', function () {
    it('generates a slug from the name when none is given', function () {
        $team = Team::factory()->create(['name' => 'Acme Corp & Sons']);

        expect($team->slug)->toBe('acme-corp-sons');
        $this->assertDatabaseHas('teams', ['id' => $team->id, 'slug' => 'acme-corp-sons']);
    });

    it('keeps an explicitly provided slug', function () {
        $team = Team::factory()->create(['name' => 'Acme Corp', 'slug' => 'custom-slug']);

        expect($team->slug)->toBe('custom-slug');
    });

    it('appends a counter to keep generated slugs unique', function () {
        $first = Team::factory()->create(['name' => 'Acme Corp']);
        $second = Team::factory()->create(['name' => 'Acme Corp']);
        $third = Team::factory()->create(['name' => 'Acme Corp']);
        $other = Team::factory()->create(['name' => 'Different Team']);

        expect($first->slug)->toBe('acme-corp')
            ->and($second->slug)->toBe('acme-corp-1')
            ->and($third->slug)->toBe('acme-corp-2')
            ->and($other->slug)->toBe('different-team');
    });

    it('exposes its settings through typed getters', function () {
        $team = Team::factory()->create([
            'is_active' => false,
            'required_work_hours' => 6.5,
            'default_leave_quota_days' => 15,
            'max_leave_days_per_month' => 7,
        ]);

        expect($team->isActive())->toBeFalse()
            ->and($team->getRequiredWorkHours())->toBe(6.5)
            ->and($team->getDefaultLeaveQuotaDays())->toBe(15)
            ->and($team->getMaxLeaveDaysPerMonth())->toBe(7);

        expect(Team::factory()->create()->isActive())->toBeTrue();
    });

    it('returns the configured check-in window or falls back to the defaults', function () {
        $custom = Team::factory()->create(['check_in_window_start' => '07:30:00', 'check_in_window_end' => '08:15:00']);
        $stored = Team::factory()->create()->fresh();
        $unset = new Team;

        expect($custom->getCheckInWindowStart())->toBe('07:30:00')
            ->and($custom->getCheckInWindowEnd())->toBe('08:15:00')
            ->and($stored->getCheckInWindowStart())->toBe(Team::DEFAULT_CHECK_IN_WINDOW_START)
            ->and($stored->getCheckInWindowEnd())->toBe(Team::DEFAULT_CHECK_IN_WINDOW_END)
            ->and($unset->getCheckInWindowStart())->toBe('09:00:00')
            ->and($unset->getCheckInWindowEnd())->toBe('10:00:00');
    });

    it('stores the WhatsApp API secret encrypted and returns it decrypted', function () {
        $team = Team::factory()->create(['whatsapp_api_secret' => 'super-secret-key']);

        $raw = DB::table('teams')->where('id', $team->id)->value('whatsapp_api_secret');

        expect($raw)->not->toBe('super-secret-key')
            ->and(decrypt($raw))->toBe('super-secret-key')
            ->and($team->whatsapp_api_secret)->toBe('super-secret-key')
            ->and($team->fresh()->whatsapp_api_secret)->toBe('super-secret-key');
    });

    it('treats an empty WhatsApp API secret as not set', function () {
        $team = Team::factory()->create(['whatsapp_api_secret' => 'super-secret-key']);

        $team->update(['whatsapp_api_secret' => null]);
        expect(DB::table('teams')->where('id', $team->id)->value('whatsapp_api_secret'))->toBeNull()
            ->and($team->fresh()->whatsapp_api_secret)->toBeNull();

        $team->update(['whatsapp_api_secret' => '']);
        expect(DB::table('teams')->where('id', $team->id)->value('whatsapp_api_secret'))->toBeNull()
            ->and($team->fresh()->whatsapp_api_secret)->toBeNull();
    });

    it('normalises the WhatsApp recap time to include seconds', function (?string $input, ?string $expected) {
        $team = new Team(['whatsapp_recap_time' => $input]);

        expect($team->getAttributes()['whatsapp_recap_time'])->toBe($expected);
    })->with([
        'hours and minutes' => ['23:00', '23:00:00'],
        'already with seconds' => ['23:00:30', '23:00:30'],
        'null' => [null, null],
        'empty string' => ['', ''],
    ]);

    it('persists the normalised WhatsApp recap time', function () {
        $team = Team::factory()->create(['whatsapp_recap_time' => '21:45']);

        expect($team->fresh()->whatsapp_recap_time)->toBe('21:45:00');
    });

    it('is configured for WhatsApp only with an API secret and an account id', function (?string $secret, ?string $accountId, bool $expected) {
        $team = Team::factory()->make(['whatsapp_api_secret' => $secret, 'whatsapp_account_unique_id' => $accountId]);

        expect($team->isWhatsappConfigured())->toBe($expected);
    })->with([
        'both set' => ['secret', 'account-id', true],
        'missing secret' => [null, 'account-id', false],
        'missing account id' => ['secret', null, false],
        'nothing set' => [null, null, false],
    ]);

    it('is connected to WhatsApp only when flagged and configured', function (bool $connected, ?string $secret, ?string $accountId, bool $expected) {
        $team = Team::factory()->make([
            'whatsapp_connected' => $connected,
            'whatsapp_api_secret' => $secret,
            'whatsapp_account_unique_id' => $accountId,
        ]);

        expect($team->isWhatsappConnected())->toBe($expected);
    })->with([
        'flagged and configured' => [true, 'secret', 'account-id', true],
        'flagged but not configured' => [true, null, 'account-id', false],
        'configured but not flagged' => [false, 'secret', 'account-id', false],
    ]);

    it('can send a WhatsApp recap only when enabled, connected and a recipient is set', function (bool $enabled, bool $connected, ?string $secret, ?string $accountId, ?string $recipient, bool $expected) {
        $team = Team::factory()->make([
            'whatsapp_recap_enabled' => $enabled,
            'whatsapp_connected' => $connected,
            'whatsapp_api_secret' => $secret,
            'whatsapp_account_unique_id' => $accountId,
            'whatsapp_recipient_phone' => $recipient,
        ]);

        expect($team->canSendWhatsappRecap())->toBe($expected);
    })->with([
        'everything in place' => [true, true, 'secret', 'account-id', '628123456789', true],
        'recap disabled' => [false, true, 'secret', 'account-id', '628123456789', false],
        'not connected' => [true, false, 'secret', 'account-id', '628123456789', false],
        'missing secret' => [true, true, null, 'account-id', '628123456789', false],
        'missing account id' => [true, true, 'secret', null, '628123456789', false],
        'missing recipient' => [true, true, 'secret', 'account-id', null, false],
    ]);

    it('relates to its users, managers and employees', function () {
        $team = $this->createTeam();
        $this->team = $team;
        $manager = $this->createManager(['name' => 'Manager']);
        $employee = $this->createEmployee(['name' => 'Employee']);
        $admin = $this->createUser(['role' => UserRole::SUPER_ADMIN, 'name' => 'Admin']);
        $outsider = User::factory()->create(['role' => 'employee']);

        expect($team->users())->toBeInstanceOf(HasMany::class)
            ->and($team->users->pluck('id')->sort()->values()->all())->toBe(collect([$manager->id, $employee->id, $admin->id])->sort()->values()->all())
            ->and($team->managers->pluck('id')->all())->toBe([$manager->id])
            ->and($team->employees->pluck('id')->all())->toBe([$employee->id])
            ->and($team->users->pluck('id')->contains($outsider->id))->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// User
// ---------------------------------------------------------------------------

describe('User', function () {
    it('answers role questions for every role', function (UserRole $role, bool $manager, bool $employee, bool $admin) {
        $user = User::factory()->create(['role' => $role]);

        expect($user->role)->toBe($role)
            ->and($user->isManager())->toBe($manager)
            ->and($user->isEmployee())->toBe($employee)
            ->and($user->isSuperAdmin())->toBe($admin);
    })->with([
        'manager' => [UserRole::MANAGER, true, false, false],
        'employee' => [UserRole::EMPLOYEE, false, true, false],
        'super admin' => [UserRole::SUPER_ADMIN, false, false, true],
    ]);

    it('casts the role from its stored string', function () {
        $user = User::factory()->create(['role' => 'manager']);

        expect($user->fresh()->role)->toBe(UserRole::MANAGER);
    });

    it('reports whether the account is disabled', function () {
        $active = User::factory()->create();
        $disabled = User::factory()->disabled()->create();

        expect($active->isDisabled())->toBeFalse()
            ->and($disabled->isDisabled())->toBeTrue()
            ->and($disabled->fresh()->is_disabled)->toBeTrue()
            ->and($active->fresh()->is_disabled)->toBeFalse();
    });

    it('hashes passwords and hides sensitive attributes when serialised', function () {
        $user = User::factory()->create(['password' => 'plain-password-123']);

        expect($user->password)->not->toBe('plain-password-123')
            ->and(Hash::check('plain-password-123', $user->fresh()->password))->toBeTrue()
            ->and($user->toArray())->not->toHaveKeys(['password', 'remember_token'])
            ->and($user->toArray())->toHaveKeys(['id', 'name', 'email', 'role']);
    });

    it('casts leave quota and email verification', function () {
        $user = User::factory()->create(['leave_quota_days' => '14']);
        $unverified = User::factory()->unverified()->create();

        expect($user->fresh()->leave_quota_days)->toBe(14)
            ->and($user->email_verified_at)->toBeInstanceOf(Carbon::class)
            ->and($unverified->email_verified_at)->toBeNull();
    });

    it('belongs to a team', function () {
        $team = $this->createTeam();
        $user = User::factory()->create(['team_id' => $team->id]);

        expect($user->team())->toBeInstanceOf(BelongsTo::class)
            ->and($user->team->is($team))->toBeTrue();
    });

    it('has many attendances, leaves, leave quotas and activity logs', function () {
        $user = $this->createEmployee();
        $colleague = $this->createEmployee();

        $attendance = Attendance::factory()->create(['user_id' => $user->id]);
        Attendance::factory()->create(['user_id' => $colleague->id]);
        $leave = Leave::factory()->create(['user_id' => $user->id]);
        Leave::factory()->create(['user_id' => $colleague->id]);
        $quota = LeaveQuota::create(['user_id' => $user->id, 'year' => 2026, 'quota_days' => 18]);
        $log = ActivityLog::create(['team_id' => $user->team_id, 'user_id' => $user->id, 'action' => ActivityType::LOGIN, 'description' => 'in', 'created_at' => now()]);

        expect($user->attendances())->toBeInstanceOf(HasMany::class)
            ->and($user->leaves())->toBeInstanceOf(HasMany::class)
            ->and($user->leaveQuotas())->toBeInstanceOf(HasMany::class)
            ->and($user->activityLogs())->toBeInstanceOf(HasMany::class)
            ->and($user->attendances->pluck('id')->all())->toBe([$attendance->id])
            ->and($user->leaves->pluck('id')->all())->toBe([$leave->id])
            ->and($user->leaveQuotas->pluck('id')->all())->toBe([$quota->id])
            ->and($user->activityLogs->pluck('id')->all())->toBe([$log->id]);
    });

    it('issues Sanctum API tokens', function () {
        $user = $this->createEmployee();

        $token = $user->createToken('auth-token');

        expect($token->plainTextToken)->toBeString()->not->toBeEmpty()
            ->and($user->tokens()->count())->toBe(1);
    });
});

// ---------------------------------------------------------------------------
// Leave
// ---------------------------------------------------------------------------

describe('Leave', function () {
    it('reports its status through the helper methods', function (LeaveStatus $status, bool $pending, bool $approved, bool $rejected) {
        $leave = Leave::factory()->create(['status' => $status]);

        expect($leave->fresh()->status)->toBe($status)
            ->and($leave->isPending())->toBe($pending)
            ->and($leave->isApproved())->toBe($approved)
            ->and($leave->isRejected())->toBe($rejected);
    })->with([
        'pending' => [LeaveStatus::PENDING, true, false, false],
        'approved' => [LeaveStatus::APPROVED, false, true, false],
        'rejected' => [LeaveStatus::REJECTED, false, false, true],
    ]);

    it('defaults the factory leave to a pending request', function () {
        expect(Leave::factory()->create()->isPending())->toBeTrue();
    });

    it('casts dates and the approval timestamp', function () {
        $leave = Leave::create([
            'user_id' => User::factory()->create()->id,
            'start_date' => '2026-03-16',
            'end_date' => '2026-03-18',
            'reason' => 'Trip',
            'status' => LeaveStatus::APPROVED,
            'approved_at' => '2026-03-12 09:00:00',
        ])->fresh();

        expect($leave->start_date)->toBeInstanceOf(Carbon::class)
            ->and($leave->start_date->toDateString())->toBe('2026-03-16')
            ->and($leave->end_date->toDateString())->toBe('2026-03-18')
            ->and($leave->approved_at->format('Y-m-d H:i:s'))->toBe('2026-03-12 09:00:00');
    });

    it('belongs to the requesting user and to its approver', function () {
        $employee = $this->createEmployee();
        $manager = $this->createManager();
        $leave = Leave::factory()->create(['user_id' => $employee->id, 'approved_by' => $manager->id]);
        $unapproved = Leave::factory()->create(['user_id' => $employee->id]);

        expect($leave->user())->toBeInstanceOf(BelongsTo::class)
            ->and($leave->approver())->toBeInstanceOf(BelongsTo::class)
            ->and($leave->user->is($employee))->toBeTrue()
            ->and($leave->approver->is($manager))->toBeTrue()
            ->and($unapproved->approver)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// ActivityLog
// ---------------------------------------------------------------------------

describe('ActivityLog', function () {
    it('belongs to a user and a team', function () {
        $team = $this->createTeam();
        $this->team = $team;
        $user = $this->createEmployee();
        $log = ActivityLog::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'action' => ActivityType::CHECK_IN,
            'description' => 'Checked in',
            'ip_address' => '203.0.113.5',
            'user_agent' => 'PestAgent/1.0',
            'created_at' => '2026-03-11 09:00:00',
        ]);

        expect($log->user())->toBeInstanceOf(BelongsTo::class)
            ->and($log->team())->toBeInstanceOf(BelongsTo::class)
            ->and($log->user->is($user))->toBeTrue()
            ->and($log->team->is($team))->toBeTrue();
    });

    it('casts the action to an enum and the creation time to a date', function () {
        $user = $this->createEmployee();
        $log = ActivityLog::create([
            'team_id' => $user->team_id,
            'user_id' => $user->id,
            'action' => 'leave_approved',
            'description' => 'Approved',
            'created_at' => '2026-03-11 09:00:00',
        ])->fresh();

        expect($log->action)->toBe(ActivityType::LEAVE_APPROVED)
            ->and($log->created_at)->toBeInstanceOf(Carbon::class)
            ->and($log->created_at->format('Y-m-d H:i:s'))->toBe('2026-03-11 09:00:00');
    });

    it('does not maintain timestamps on its own', function () {
        $user = $this->createEmployee();
        $log = ActivityLog::factory()->create(['user_id' => $user->id, 'team_id' => $user->team_id]);

        expect($log->timestamps)->toBeFalse()
            ->and($log->getAttributes())->not->toHaveKey('updated_at');
    });
});

// ---------------------------------------------------------------------------
// Holiday
// ---------------------------------------------------------------------------

describe('Holiday', function () {
    it('belongs to a team', function () {
        $team = $this->createTeam();
        $holiday = Holiday::create(['team_id' => $team->id, 'date' => '2026-12-25', 'name' => 'Christmas Day', 'description' => 'Public holiday']);

        expect($holiday->team())->toBeInstanceOf(BelongsTo::class)
            ->and($holiday->team->is($team))->toBeTrue()
            ->and($holiday->description)->toBe('Public holiday');
    });

    it('casts the date to a Carbon instance', function () {
        $holiday = Holiday::factory()->create(['date' => '2026-12-25'])->fresh();

        expect($holiday->date)->toBeInstanceOf(Carbon::class)
            ->and($holiday->date->toDateString())->toBe('2026-12-25');
    });

    it('allows the same date for different teams', function () {
        $a = $this->createTeam();
        $b = $this->createTeam();

        Holiday::create(['team_id' => $a->id, 'date' => '2026-08-17', 'name' => 'Independence Day']);
        Holiday::create(['team_id' => $b->id, 'date' => '2026-08-17', 'name' => 'Independence Day']);

        expect(Holiday::whereDate('date', '2026-08-17')->count())->toBe(2);
    });
});

// ---------------------------------------------------------------------------
// LeaveQuota
// ---------------------------------------------------------------------------

describe('LeaveQuota', function () {
    it('belongs to a user and casts year and quota to integers', function () {
        $user = $this->createEmployee();
        $quota = LeaveQuota::create(['user_id' => $user->id, 'year' => '2026', 'quota_days' => '18'])->fresh();

        expect($quota->user())->toBeInstanceOf(BelongsTo::class)
            ->and($quota->user->is($user))->toBeTrue()
            ->and($quota->year)->toBe(2026)
            ->and($quota->quota_days)->toBe(18);
    });

    it('allows only one quota per user and year', function () {
        $user = $this->createEmployee();
        LeaveQuota::create(['user_id' => $user->id, 'year' => 2026, 'quota_days' => 12]);

        expect(fn () => LeaveQuota::create(['user_id' => $user->id, 'year' => 2026, 'quota_days' => 20]))
            ->toThrow(QueryException::class);

        LeaveQuota::create(['user_id' => $user->id, 'year' => 2027, 'quota_days' => 20]);
        expect(LeaveQuota::where('user_id', $user->id)->count())->toBe(2);
    });

    it('is removed together with its user', function () {
        $user = $this->createEmployee();
        LeaveQuota::create(['user_id' => $user->id, 'year' => 2026, 'quota_days' => 12]);

        $user->delete();

        $this->assertDatabaseCount('leave_quotas', 0);
    });
});
