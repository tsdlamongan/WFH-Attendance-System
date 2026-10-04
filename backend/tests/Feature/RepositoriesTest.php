<?php

use App\Enums\ActivityType;
use App\Enums\LeaveStatus;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Repositories\ActivityLogRepository;
use App\Repositories\AttendanceRepository;
use App\Repositories\TaskRepository;
use App\Repositories\UserRepository;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Hash;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

/*
 * Repository tests. "Now" is frozen at Wednesday 11 March 2026 14:00 (Asia/Jakarta)
 * so the "today / yesterday" lookups and elapsed-time calculations are deterministic.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-03-11 14:00:00'));
    $this->team = $this->createTeam(['required_work_hours' => 7.0]);
});

function repoLog(User $user, ActivityType $action, string $createdAt, string $description = 'log'): ActivityLog
{
    return ActivityLog::create([
        'team_id' => $user->team_id,
        'user_id' => $user->id,
        'action' => $action,
        'description' => $description,
        'created_at' => Carbon::parse($createdAt),
    ]);
}

function repoAttendance(User $user, string $checkIn, ?string $checkOut = null, ?float $totalHours = null): Attendance
{
    $in = Carbon::parse($checkIn);
    $out = $checkOut ? Carbon::parse($checkOut) : null;

    return Attendance::create([
        'user_id' => $user->id,
        'check_in' => $in,
        'check_out' => $out,
        'date' => $in->toDateString(),
        'total_hours' => $totalHours ?? ($out ? round($in->diffInMinutes($out) / 60, 2) : 0),
    ]);
}

function repoTask(Attendance $attendance, string $title, bool $completed = false, ?string $blocker = null): Task
{
    return Task::create([
        'attendance_id' => $attendance->id,
        'title' => $title,
        'is_completed' => $completed,
        'blocker_reason' => $blocker,
    ]);
}

// ---------------------------------------------------------------------------
// ActivityLogRepository
// ---------------------------------------------------------------------------

describe('ActivityLogRepository', function () {
    beforeEach(function () {
        $this->logs = app(ActivityLogRepository::class);
        $this->userA = $this->createEmployee(['name' => 'Alice']);
        $this->userB = $this->createEmployee(['name' => 'Bob']);

        $this->l1 = repoLog($this->userA, ActivityType::CHECK_IN, '2026-03-08 10:00:00');
        $this->l2 = repoLog($this->userA, ActivityType::CHECK_OUT, '2026-03-09 23:59:59');
        $this->l3 = repoLog($this->userB, ActivityType::CHECK_IN, '2026-03-10 00:00:00');
        $this->l4 = repoLog($this->userA, ActivityType::LOGIN, '2026-03-11 09:00:00');
        $this->l5 = repoLog($this->userB, ActivityType::LOGOUT, '2026-03-12 12:00:00');
    });

    it('creates a log entry stamped with the user team, request metadata and the current time', function () {
        $log = $this->logs->create($this->userA, ActivityType::PASSWORD_CHANGED, 'Changed password', '10.0.0.7', 'PestAgent/1.0');

        expect($log)->toBeInstanceOf(ActivityLog::class)
            ->and($log->action)->toBe(ActivityType::PASSWORD_CHANGED);

        $this->assertDatabaseHas('activity_logs', [
            'id' => $log->id,
            'team_id' => $this->team->id,
            'user_id' => $this->userA->id,
            'action' => 'password_changed',
            'description' => 'Changed password',
            'ip_address' => '10.0.0.7',
            'user_agent' => 'PestAgent/1.0',
            'created_at' => '2026-03-11 14:00:00',
        ]);
    });

    it('stores null request metadata when none is supplied', function () {
        $log = $this->logs->create($this->userB, ActivityType::LOGIN, 'Logged in');

        $this->assertDatabaseHas('activity_logs', [
            'id' => $log->id,
            'ip_address' => null,
            'user_agent' => null,
        ]);
    });

    it('returns logs newest first with the user eager loaded', function () {
        $result = $this->logs->getWithFilters();

        expect($result->pluck('id')->all())->toBe([$this->l5->id, $this->l4->id, $this->l3->id, $this->l2->id, $this->l1->id])
            ->and($result->every(fn ($log) => $log->relationLoaded('user')))->toBeTrue();
    });

    it('limits the number of logs returned', function () {
        $result = $this->logs->getWithFilters(perPage: 2);

        expect($result->pluck('id')->all())->toBe([$this->l5->id, $this->l4->id]);
    });

    it('filters logs by user and by action', function () {
        $byUser = $this->logs->getWithFilters($this->userB->id);
        $byAction = $this->logs->getWithFilters(null, ActivityType::CHECK_IN);
        $both = $this->logs->getWithFilters($this->userA->id, ActivityType::CHECK_IN);

        expect($byUser->pluck('id')->all())->toBe([$this->l5->id, $this->l3->id])
            ->and($byAction->pluck('id')->all())->toBe([$this->l3->id, $this->l1->id])
            ->and($both->pluck('id')->all())->toBe([$this->l1->id]);
    });

    it('filters logs between two whole days inclusively', function () {
        $result = $this->logs->getWithFilters(null, null, Carbon::parse('2026-03-09'), Carbon::parse('2026-03-10'));

        expect($result->pluck('id')->all())->toBe([$this->l3->id, $this->l2->id]);
    });

    it('filters logs from a start date only', function () {
        $result = $this->logs->getWithFilters(null, null, Carbon::parse('2026-03-10'));

        expect($result->pluck('id')->all())->toBe([$this->l5->id, $this->l4->id, $this->l3->id]);
    });

    it('filters logs up to an end date only', function () {
        $result = $this->logs->getWithFilters(null, null, null, Carbon::parse('2026-03-09'));

        expect($result->pluck('id')->all())->toBe([$this->l2->id, $this->l1->id]);
    });

    it('paginates logs and restricts them to a team', function () {
        $otherTeam = $this->createTeam();
        $outsider = User::factory()->create(['team_id' => $otherTeam->id]);
        repoLog($outsider, ActivityType::LOGIN, '2026-03-11 10:00:00');

        $page = $this->logs->getPaginatedWithFilters(perPage: 2, teamId: $this->team->id);

        expect($page->total())->toBe(5)
            ->and($page->perPage())->toBe(2)
            ->and($page->lastPage())->toBe(3)
            ->and(collect($page->items())->pluck('id')->all())->toBe([$this->l5->id, $this->l4->id])
            ->and($this->logs->getPaginatedWithFilters()->total())->toBe(6);
    });

    it('applies user, action and date filters when paginating', function () {
        $byUser = $this->logs->getPaginatedWithFilters($this->userA->id);
        $byAction = $this->logs->getPaginatedWithFilters(null, ActivityType::LOGOUT);
        $range = $this->logs->getPaginatedWithFilters(null, null, Carbon::parse('2026-03-09'), Carbon::parse('2026-03-10'));
        $from = $this->logs->getPaginatedWithFilters(null, null, Carbon::parse('2026-03-11'));
        $until = $this->logs->getPaginatedWithFilters(null, null, null, Carbon::parse('2026-03-08'));
        $combined = $this->logs->getPaginatedWithFilters($this->userB->id, ActivityType::CHECK_IN, Carbon::parse('2026-03-10'), Carbon::parse('2026-03-10'));

        expect(collect($byUser->items())->pluck('id')->all())->toBe([$this->l4->id, $this->l2->id, $this->l1->id])
            ->and(collect($byAction->items())->pluck('id')->all())->toBe([$this->l5->id])
            ->and(collect($range->items())->pluck('id')->all())->toBe([$this->l3->id, $this->l2->id])
            ->and(collect($from->items())->pluck('id')->all())->toBe([$this->l5->id, $this->l4->id])
            ->and(collect($until->items())->pluck('id')->all())->toBe([$this->l1->id])
            ->and(collect($combined->items())->pluck('id')->all())->toBe([$this->l3->id])
            ->and($byUser->items()[0]->relationLoaded('user'))->toBeTrue();
    });

    it('returns the logs of a single user newest first with a configurable limit', function () {
        $all = $this->logs->getByUser($this->userA);
        $limited = $this->logs->getByUser($this->userA, 2);

        expect($all->pluck('id')->all())->toBe([$this->l4->id, $this->l2->id, $this->l1->id])
            ->and($limited->pluck('id')->all())->toBe([$this->l4->id, $this->l2->id])
            ->and($this->logs->getByUser($this->createEmployee()))->toHaveCount(0);
    });
});

// ---------------------------------------------------------------------------
// UserRepository
// ---------------------------------------------------------------------------

describe('UserRepository', function () {
    beforeEach(function () {
        $this->users = app(UserRepository::class);
    });

    it('lists active users ordered by name and optionally includes disabled ones', function () {
        $this->createEmployee(['name' => 'Charlie']);
        $this->createManager(['name' => 'Alice']);
        $this->createDisabledEmployee(['name' => 'Bob']);

        expect($this->users->getAll()->pluck('name')->all())->toBe(['Alice', 'Charlie'])
            ->and($this->users->getAll(null, false)->pluck('name')->all())->toBe(['Alice', 'Bob', 'Charlie']);
    });

    it('lists users of a single team only', function () {
        $this->createEmployee(['name' => 'Mine']);
        $otherTeam = $this->createTeam();
        User::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Theirs']);

        expect($this->users->getAll($this->team->id)->pluck('name')->all())->toBe(['Mine'])
            ->and($this->users->getAll($otherTeam->id)->pluck('name')->all())->toBe(['Theirs'])
            ->and($this->users->getAll()->pluck('name')->sort()->values()->all())->toBe(['Mine', 'Theirs']);
    });

    it('paginates users including disabled ones by default', function () {
        $this->createEmployee(['name' => 'Alice']);
        $this->createEmployee(['name' => 'Bob']);
        $this->createDisabledEmployee(['name' => 'Carol']);

        $page = $this->users->getPaginated(2);

        expect($page->total())->toBe(3)
            ->and($page->lastPage())->toBe(2)
            ->and(collect($page->items())->pluck('name')->all())->toBe(['Alice', 'Bob'])
            ->and($page->items()[0]->relationLoaded('team'))->toBeTrue();

        request()->merge(['page' => 2]);
        expect(collect($this->users->getPaginated(2)->items())->pluck('name')->all())->toBe(['Carol']);
    });

    it('can exclude disabled users and restrict pagination to a team', function () {
        $this->createEmployee(['name' => 'Alice']);
        $this->createDisabledEmployee(['name' => 'Bob']);
        $otherTeam = $this->createTeam();
        User::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Zed']);

        $active = $this->users->getPaginated(10, $this->team->id, true);
        $everyone = $this->users->getPaginated(10, $this->team->id);
        $otherTeamPage = $this->users->getPaginated(10, $otherTeam->id);

        expect(collect($active->items())->pluck('name')->all())->toBe(['Alice'])
            ->and(collect($everyone->items())->pluck('name')->all())->toBe(['Alice', 'Bob'])
            ->and(collect($otherTeamPage->items())->pluck('name')->all())->toBe(['Zed']);
    });

    it('eager loads only the approved leaves of the current year when paginating', function () {
        $employee = $this->createEmployee(['name' => 'Alice']);
        $approvedThisYear = Leave::create(['user_id' => $employee->id, 'start_date' => '2026-02-02', 'end_date' => '2026-02-03', 'reason' => 'Trip', 'status' => LeaveStatus::APPROVED]);
        Leave::create(['user_id' => $employee->id, 'start_date' => '2025-12-02', 'end_date' => '2025-12-03', 'reason' => 'Last year', 'status' => LeaveStatus::APPROVED]);
        Leave::create(['user_id' => $employee->id, 'start_date' => '2026-04-02', 'end_date' => '2026-04-03', 'reason' => 'Pending', 'status' => LeaveStatus::PENDING]);
        Leave::create(['user_id' => $employee->id, 'start_date' => '2026-05-02', 'end_date' => '2026-05-03', 'reason' => 'Rejected', 'status' => LeaveStatus::REJECTED]);

        $row = $this->users->getPaginated(10, $this->team->id)->items()[0];

        expect($row->relationLoaded('leaves'))->toBeTrue()
            ->and($row->leaves->pluck('id')->all())->toBe([$approvedThisYear->id]);
    });

    it('finds users by id and by email', function () {
        $user = $this->createEmployee(['email' => 'find.me@example.com']);

        expect($this->users->findById($user->id)->is($user))->toBeTrue()
            ->and($this->users->findById(999999))->toBeNull()
            ->and($this->users->findByEmail('find.me@example.com')->is($user))->toBeTrue()
            ->and($this->users->findByEmail('nobody@example.com'))->toBeNull();
    });

    it('hashes the password and uses the team default leave quota on create', function () {
        $team = $this->createTeam(['default_leave_quota_days' => 20]);

        $user = $this->users->create([
            'team_id' => $team->id,
            'name' => 'New Hire',
            'email' => 'new.hire@example.com',
            'password' => 'secret-pass-1',
            'role' => 'employee',
        ]);

        expect($user->exists)->toBeTrue()
            ->and($user->leave_quota_days)->toBe(20)
            ->and($user->password)->not->toBe('secret-pass-1')
            ->and(Hash::check('secret-pass-1', $user->fresh()->password))->toBeTrue();
    });

    it('keeps an explicit leave quota on create, even when it is zero', function () {
        $team = $this->createTeam(['default_leave_quota_days' => 20]);

        $custom = $this->users->create([
            'team_id' => $team->id, 'name' => 'Custom', 'email' => 'custom@example.com',
            'password' => 'secret-pass-1', 'role' => 'employee', 'leave_quota_days' => 5,
        ]);
        $none = $this->users->create([
            'team_id' => $team->id, 'name' => 'None', 'email' => 'none@example.com',
            'password' => 'secret-pass-1', 'role' => 'employee', 'leave_quota_days' => 0,
        ]);

        expect($custom->leave_quota_days)->toBe(5)
            ->and($none->fresh()->leave_quota_days)->toBe(0);
    });

    it('falls back to the global default leave quota when the user has no team', function () {
        $user = $this->users->create([
            'name' => 'Floating Admin',
            'email' => 'floating@example.com',
            'password' => 'secret-pass-1',
            'role' => 'super_admin',
        ]);

        expect($user->team_id)->toBeNull()
            ->and($user->leave_quota_days)->toBe(Team::DEFAULT_LEAVE_QUOTA_DAYS);
    });

    it('hashes the password only when it is part of the update', function () {
        $user = $this->createEmployee(['name' => 'Before']);
        $originalHash = $user->password;

        expect($this->users->update($user, ['name' => 'After']))->toBeTrue()
            ->and($user->fresh()->name)->toBe('After')
            ->and($user->fresh()->password)->toBe($originalHash);

        expect($this->users->update($user, ['password' => 'brand-new-pass']))->toBeTrue();

        $fresh = $user->fresh();
        expect($fresh->password)->not->toBe('brand-new-pass')
            ->and(Hash::check('brand-new-pass', $fresh->password))->toBeTrue();
    });

    it('deletes a user', function () {
        $user = $this->createEmployee();

        expect($this->users->delete($user))->toBeTrue();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    });

    it('lists employees and managers separately', function () {
        $this->createEmployee(['name' => 'Eve']);
        $this->createEmployee(['name' => 'Dan']);
        $this->createDisabledEmployee(['name' => 'Gone']);
        $this->createManager(['name' => 'Zoe']);
        $this->createManager(['name' => 'Amy']);
        $this->createManager(['name' => 'Retired', 'is_disabled' => true]);
        $this->createUser(['name' => 'Root', 'role' => 'super_admin']);

        expect($this->users->getEmployees()->pluck('name')->all())->toBe(['Dan', 'Eve'])
            ->and($this->users->getEmployees(null, false)->pluck('name')->all())->toBe(['Dan', 'Eve', 'Gone'])
            ->and($this->users->getManagers()->pluck('name')->all())->toBe(['Amy', 'Zoe'])
            ->and($this->users->getManagers(null, false)->pluck('name')->all())->toBe(['Amy', 'Retired', 'Zoe']);
    });

    it('restricts employees and managers to a team', function () {
        $this->createEmployee(['name' => 'Mine Employee']);
        $this->createManager(['name' => 'Mine Manager']);
        $otherTeam = $this->createTeam();
        User::factory()->create(['team_id' => $otherTeam->id, 'role' => 'employee', 'name' => 'Their Employee']);
        User::factory()->create(['team_id' => $otherTeam->id, 'role' => 'manager', 'name' => 'Their Manager']);

        expect($this->users->getEmployees($otherTeam->id)->pluck('name')->all())->toBe(['Their Employee'])
            ->and($this->users->getManagers($otherTeam->id)->pluck('name')->all())->toBe(['Their Manager'])
            ->and($this->users->getEmployees($this->team->id)->pluck('name')->all())->toBe(['Mine Employee'])
            ->and($this->users->getManagers($this->team->id)->pluck('name')->all())->toBe(['Mine Manager']);
    });

    it('searches users by name or email case-insensitively', function () {
        $this->createEmployee(['name' => 'Alice Johnson', 'email' => 'alice@example.com']);
        $this->createEmployee(['name' => 'Bob Smith', 'email' => 'bob.johnson@example.com']);
        $this->createEmployee(['name' => 'Carol White', 'email' => 'carol@example.com']);

        expect($this->users->searchByName('JOHNSON')->pluck('name')->all())->toBe(['Alice Johnson', 'Bob Smith'])
            ->and($this->users->searchByName('carol@')->pluck('name')->all())->toBe(['Carol White'])
            ->and($this->users->searchByName('zzz'))->toHaveCount(0);
    });

    it('excludes disabled users from search unless asked otherwise and scopes it to a team', function () {
        $this->createEmployee(['name' => 'Sam Active']);
        $this->createDisabledEmployee(['name' => 'Sam Disabled']);
        $otherTeam = $this->createTeam();
        User::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Sam Elsewhere']);

        expect($this->users->searchByName('sam', 10, $this->team->id)->pluck('name')->all())->toBe(['Sam Active'])
            ->and($this->users->searchByName('sam', 10, $this->team->id, false)->pluck('name')->all())->toBe(['Sam Active', 'Sam Disabled'])
            ->and($this->users->searchByName('sam')->pluck('name')->all())->toBe(['Sam Active', 'Sam Elsewhere']);
    });

    it('clamps the search limit between 1 and 50', function () {
        foreach (range(1, 55) as $i) {
            $this->createEmployee(['name' => sprintf('Searchable %02d', $i)]);
        }

        expect($this->users->searchByName('searchable', 5))->toHaveCount(5)
            ->and($this->users->searchByName('searchable', 0))->toHaveCount(1)
            ->and($this->users->searchByName('searchable', -10))->toHaveCount(1)
            ->and($this->users->searchByName('searchable', 500))->toHaveCount(50);
    });

    it('strips unsafe characters from the search term', function () {
        $this->createEmployee(['name' => 'Alice', 'email' => 'alice@example.com']);

        // An injection attempt is sanitised into a harmless literal that matches nothing and drops nothing.
        expect($this->users->searchByName("alice'; DROP TABLE users;--"))->toHaveCount(0)
            ->and(User::count())->toBe(1);

        // LIKE wildcards are removed instead of being interpreted, so "%" cannot be used to list everyone.
        expect($this->users->searchByName('zz%'))->toHaveCount(0)
            ->and($this->users->searchByName('al%ice')->pluck('name')->all())->toBe(['Alice']);

        // Allowed characters (letters, digits, @ . _ -) survive sanitising.
        expect($this->users->searchByName('alice@example.com')->pluck('name')->all())->toBe(['Alice']);
    });

    it('returns an empty eloquent collection when the search term is empty once sanitised', function (string $term) {
        $this->createEmployee(['name' => 'Alice', 'email' => 'alice@example.com']);

        $result = $this->users->searchByName($term);

        expect($result)->toBeInstanceOf(EloquentCollection::class)
            ->and($result)->toHaveCount(0);
    })->with(['%', '!!!', '', "%'"]);

    it('truncates very long search terms to 100 characters', function () {
        $this->createEmployee(['name' => str_repeat('a', 100).'c']);

        // The trailing "b" is cut off, leaving 100 "a" characters that match the name.
        expect($this->users->searchByName(str_repeat('a', 100).'b'))->toHaveCount(1)
            ->and($this->users->searchByName(str_repeat('b', 150)))->toHaveCount(0);
    });
});

// ---------------------------------------------------------------------------
// AttendanceRepository
// ---------------------------------------------------------------------------

describe('AttendanceRepository', function () {
    beforeEach(function () {
        $this->attendances = app(AttendanceRepository::class);
        $this->employee = $this->createEmployee(['name' => 'Alice']);
    });

    it('finds an attendance by id', function () {
        $attendance = repoAttendance($this->employee, '2026-03-11 09:00:00');

        expect($this->attendances->findById($attendance->id)->is($attendance))->toBeTrue()
            ->and($this->attendances->findById(999999))->toBeNull();
    });

    it('finds an attendance by user and calendar date', function () {
        $mine = repoAttendance($this->employee, '2026-03-10 09:00:00', '2026-03-10 17:00:00');
        $colleague = $this->createEmployee();
        repoAttendance($colleague, '2026-03-09 09:00:00', '2026-03-09 17:00:00');

        expect($this->attendances->findByUserAndDate($this->employee, Carbon::parse('2026-03-10 22:30:00'))->is($mine))->toBeTrue()
            ->and($this->attendances->findByUserAndDate($this->employee, Carbon::parse('2026-03-09')))->toBeNull()
            ->and($this->attendances->findByUserAndDate($colleague, Carbon::parse('2026-03-10')))->toBeNull();
    });

    it('finds the active attendance of today with its tasks', function () {
        $active = repoAttendance($this->employee, '2026-03-11 09:00:00');
        repoTask($active, 'Write tests');

        $found = $this->attendances->findActiveByUser($this->employee);

        expect($found->is($active))->toBeTrue()
            ->and($found->relationLoaded('tasks'))->toBeTrue()
            ->and($found->tasks->pluck('title')->all())->toBe(['Write tests']);
    });

    it('finds an active attendance left open since yesterday', function () {
        $overnight = repoAttendance($this->employee, '2026-03-10 23:00:00');

        expect($this->attendances->findActiveByUser($this->employee)->is($overnight))->toBeTrue();
    });

    it('ignores attendances that are older than yesterday, checked out, or belong to someone else', function () {
        repoAttendance($this->employee, '2026-03-09 23:00:00'); // two days ago, still open
        repoAttendance($this->employee, '2026-03-11 08:00:00', '2026-03-11 12:00:00'); // today but finished
        repoAttendance($this->createEmployee(), '2026-03-11 09:00:00'); // colleague, open

        expect($this->attendances->findActiveByUser($this->employee))->toBeNull();
    });

    it('prefers the most recent active attendance', function () {
        repoAttendance($this->employee, '2026-03-10 23:00:00');
        $today = repoAttendance($this->employee, '2026-03-11 13:00:00');

        expect($this->attendances->findActiveByUser($this->employee)->is($today))->toBeTrue();
    });

    it('returns attendances in a date range newest first with tasks', function () {
        $monday = repoAttendance($this->employee, '2026-03-09 09:00:00', '2026-03-09 12:00:00');
        $tuesdayMorning = repoAttendance($this->employee, '2026-03-10 08:00:00', '2026-03-10 12:00:00');
        $tuesdayAfternoon = repoAttendance($this->employee, '2026-03-10 13:00:00', '2026-03-10 17:00:00');
        $outside = repoAttendance($this->employee, '2026-03-12 09:00:00', '2026-03-12 12:00:00');
        repoAttendance($this->createEmployee(), '2026-03-10 09:00:00', '2026-03-10 12:00:00');
        repoTask($monday, 'Plan');

        $result = $this->attendances->getByUserInDateRange($this->employee, Carbon::parse('2026-03-09'), Carbon::parse('2026-03-11'));

        expect($result->pluck('id')->all())->toBe([$tuesdayAfternoon->id, $tuesdayMorning->id, $monday->id])
            ->and($result->pluck('id')->contains($outside->id))->toBeFalse()
            ->and($result->every(fn ($attendance) => $attendance->relationLoaded('tasks')))->toBeTrue()
            ->and($result->last()->tasks->pluck('title')->all())->toBe(['Plan']);
    });

    it('returns every session of a user on a date ordered by check-in', function () {
        $afternoon = repoAttendance($this->employee, '2026-03-10 13:00:00', '2026-03-10 17:00:00');
        $morning = repoAttendance($this->employee, '2026-03-10 08:00:00', '2026-03-10 12:00:00');
        repoAttendance($this->employee, '2026-03-11 08:00:00', '2026-03-11 12:00:00');

        $result = $this->attendances->getAllByUserAndDate($this->employee, Carbon::parse('2026-03-10'));

        expect($result->pluck('id')->all())->toBe([$morning->id, $afternoon->id])
            ->and($result->every(fn ($attendance) => $attendance->relationLoaded('tasks')))->toBeTrue()
            ->and($this->attendances->getAllByUserAndDate($this->employee, Carbon::parse('2026-03-01')))->toHaveCount(0);
    });

    it('creates, updates and deletes attendances', function () {
        $created = $this->attendances->create([
            'user_id' => $this->employee->id,
            'check_in' => Carbon::parse('2026-03-11 09:00:00'),
            'check_out' => null,
            'date' => '2026-03-11',
            'total_hours' => 0,
        ]);

        $this->assertDatabaseHas('attendances', ['id' => $created->id, 'user_id' => $this->employee->id, 'check_out' => null]);

        expect($this->attendances->update($created, ['check_out' => Carbon::parse('2026-03-11 13:00:00'), 'total_hours' => 4]))->toBeTrue();
        $this->assertDatabaseHas('attendances', ['id' => $created->id, 'check_out' => '2026-03-11 13:00:00']);
        expect((float) $created->fresh()->total_hours)->toBe(4.0);

        expect($this->attendances->delete($created))->toBeTrue();
        $this->assertDatabaseMissing('attendances', ['id' => $created->id]);
    });

    it('lists all attendances with a team filter and a date range', function () {
        $otherTeam = $this->createTeam();
        $outsider = User::factory()->create(['team_id' => $otherTeam->id]);

        $mine = repoAttendance($this->employee, '2026-03-10 09:00:00', '2026-03-10 17:00:00');
        $mineLater = repoAttendance($this->employee, '2026-03-11 09:00:00', '2026-03-11 17:00:00');
        $theirs = repoAttendance($outsider, '2026-03-10 09:00:00', '2026-03-10 17:00:00');
        $old = repoAttendance($this->employee, '2026-02-01 09:00:00', '2026-02-01 17:00:00');

        $everything = $this->attendances->getAllInDateRange();
        $team = $this->attendances->getAllInDateRange(null, null, $this->team->id);
        $range = $this->attendances->getAllInDateRange(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'), $this->team->id);
        $otherRange = $this->attendances->getAllInDateRange(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        expect($everything->pluck('id')->sort()->values()->all())->toBe(collect([$mine->id, $mineLater->id, $theirs->id, $old->id])->sort()->values()->all())
            ->and($team->pluck('id')->all())->toBe([$mineLater->id, $mine->id, $old->id])
            ->and($range->pluck('id')->all())->toBe([$mineLater->id, $mine->id])
            ->and($otherRange->pluck('id')->sort()->values()->all())->toBe(collect([$mine->id, $mineLater->id, $theirs->id])->sort()->values()->all())
            ->and($range->every(fn ($attendance) => $attendance->relationLoaded('user') && $attendance->relationLoaded('tasks')))->toBeTrue();
    });

    it('paginates attendances filtered by team, range and user', function () {
        $colleague = $this->createEmployee(['name' => 'Bob']);
        $otherTeam = $this->createTeam();
        $outsider = User::factory()->create(['team_id' => $otherTeam->id]);

        $a1 = repoAttendance($this->employee, '2026-03-09 09:00:00', '2026-03-09 17:00:00');
        $a2 = repoAttendance($this->employee, '2026-03-10 09:00:00', '2026-03-10 17:00:00');
        $b1 = repoAttendance($colleague, '2026-03-10 10:00:00', '2026-03-10 17:00:00');
        repoAttendance($outsider, '2026-03-10 09:00:00', '2026-03-10 17:00:00');
        repoAttendance($this->employee, '2026-01-10 09:00:00', '2026-01-10 17:00:00');

        $teamPage = $this->attendances->getPaginatedInDateRange(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'), 2, null, $this->team->id);
        $userPage = $this->attendances->getPaginatedInDateRange(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'), 10, $this->employee->id, $this->team->id);
        $unfiltered = $this->attendances->getPaginatedInDateRange(perPage: 100);

        expect($teamPage->total())->toBe(3)
            ->and($teamPage->perPage())->toBe(2)
            ->and(collect($teamPage->items())->pluck('id')->all())->toBe([$b1->id, $a2->id])
            ->and(collect($userPage->items())->pluck('id')->all())->toBe([$a2->id, $a1->id])
            ->and($unfiltered->total())->toBe(5)
            ->and($teamPage->items()[0]->relationLoaded('user'))->toBeTrue();
    });

    it('finds active attendances that exceed the team required hours', function () {
        $over = repoAttendance($this->employee, '2026-03-11 05:30:00'); // 8.5h elapsed vs 7h
        $under = repoAttendance($this->createEmployee(), '2026-03-11 10:00:00'); // 4h elapsed
        $exactly = repoAttendance($this->createEmployee(), '2026-03-11 07:00:00'); // exactly 7h elapsed

        $result = $this->attendances->findAllActiveExceedingHours();

        expect($result->pluck('id')->sort()->values()->all())->toBe(collect([$over->id, $exactly->id])->sort()->values()->all())
            ->and($result->pluck('id')->contains($under->id))->toBeFalse();
    });

    it('adds completed hours of the same day before comparing with the required hours', function () {
        $user = $this->createEmployee();
        repoAttendance($user, '2026-03-11 06:00:00', '2026-03-11 09:00:00', 3.0);
        $active = repoAttendance($user, '2026-03-11 10:00:00'); // 4h elapsed -> 3h + 4h = 7h

        $result = $this->attendances->findAllActiveExceedingHours();

        expect($result->pluck('id')->all())->toBe([$active->id]);
    });

    it('skips intentional overtime sessions once the completed hours already meet the requirement', function () {
        $user = $this->createEmployee();
        repoAttendance($user, '2026-03-11 00:00:00', '2026-03-11 07:30:00', 7.5);
        repoAttendance($user, '2026-03-11 08:00:00'); // 6h elapsed, but the day is already complete

        expect($this->attendances->findAllActiveExceedingHours())->toHaveCount(0);
    });

    it('skips active attendances of users that do not belong to a team', function () {
        $teamless = User::factory()->create(['team_id' => null, 'role' => 'employee']);
        repoAttendance($teamless, '2026-03-11 01:00:00'); // 13h elapsed

        expect($this->attendances->findAllActiveExceedingHours())->toHaveCount(0);
    });
});

// ---------------------------------------------------------------------------
// TaskRepository
// ---------------------------------------------------------------------------

describe('TaskRepository', function () {
    beforeEach(function () {
        $this->tasks = app(TaskRepository::class);
        $this->employee = $this->createEmployee();
        $this->attendance = repoAttendance($this->employee, '2026-03-11 09:00:00');
    });

    it('creates open tasks for an attendance and returns them as an eloquent collection', function () {
        $created = $this->tasks->createMany($this->attendance, [['title' => 'First'], ['title' => 'Second']]);

        expect($created)->toBeInstanceOf(EloquentCollection::class)
            ->and($created->pluck('title')->sort()->values()->all())->toBe(['First', 'Second'])
            ->and($created->every(fn (Task $task) => $task->is_completed === false && $task->blocker_reason === null))->toBeTrue();

        $this->assertDatabaseHas('tasks', ['attendance_id' => $this->attendance->id, 'title' => 'First', 'is_completed' => false]);
        $this->assertDatabaseCount('tasks', 2);
    });

    it('returns an empty collection when there are no tasks to create', function () {
        $created = $this->tasks->createMany($this->attendance, []);

        expect($created)->toBeInstanceOf(EloquentCollection::class)
            ->and($created)->toHaveCount(0);
        $this->assertDatabaseCount('tasks', 0);
    });

    it('updates the status and blocker of one task', function () {
        $task = repoTask($this->attendance, 'Deploy');

        expect($this->tasks->updateStatus($task, false, 'Server down'))->toBeTrue();
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'is_completed' => false, 'blocker_reason' => 'Server down']);

        expect($this->tasks->updateStatus($task, true))->toBeTrue();
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'is_completed' => true, 'blocker_reason' => null]);
    });

    it('updates several task statuses at once', function () {
        $done = repoTask($this->attendance, 'Done soon');
        $blocked = repoTask($this->attendance, 'Blocked soon');

        $this->tasks->updateMultipleStatuses([
            ['id' => $done->id, 'is_completed' => true],
            ['id' => $blocked->id, 'is_completed' => false, 'blocker_reason' => 'Waiting for review'],
        ]);

        $this->assertDatabaseHas('tasks', ['id' => $done->id, 'is_completed' => true, 'blocker_reason' => null]);
        $this->assertDatabaseHas('tasks', ['id' => $blocked->id, 'is_completed' => false, 'blocker_reason' => 'Waiting for review']);
    });

    it('fails when a task to update does not exist', function () {
        $this->tasks->updateMultipleStatuses([['id' => 999999, 'is_completed' => true]]);
    })->throws(ModelNotFoundException::class);

    it('lists the incomplete tasks of a user across all sessions with their attendance', function () {
        $yesterday = repoAttendance($this->employee, '2026-03-10 09:00:00', '2026-03-10 17:00:00');
        $open1 = repoTask($yesterday, 'Old open', false, 'Blocked');
        $open2 = repoTask($this->attendance, 'New open');
        repoTask($this->attendance, 'Finished', true);
        repoTask(repoAttendance($this->createEmployee(), '2026-03-11 09:00:00'), 'Colleague open');

        $result = $this->tasks->getIncompleteTasksForUser($this->employee->id);

        expect($result->pluck('id')->sort()->values()->all())->toBe(collect([$open1->id, $open2->id])->sort()->values()->all())
            ->and($result->every(fn (Task $task) => $task->relationLoaded('attendance')))->toBeTrue()
            ->and($this->tasks->getIncompleteTasksForUser($this->createEmployee()->id))->toHaveCount(0);
    });

    it('returns no tasks from the last session when the user has not checked out yet', function () {
        repoTask($this->attendance, 'Still running');

        $result = $this->tasks->getIncompleteTasksFromLastSession($this->employee->id);

        expect($result)->toBeInstanceOf(EloquentCollection::class)
            ->and($result)->toHaveCount(0);
    });

    it('returns only the incomplete tasks of the most recently checked out session', function () {
        $older = repoAttendance($this->employee, '2026-03-09 09:00:00', '2026-03-09 17:00:00');
        repoTask($older, 'Older open');

        $last = repoAttendance($this->employee, '2026-03-10 09:00:00', '2026-03-10 17:00:00');
        $lastOpen = repoTask($last, 'Last open', false, 'Blocked');
        repoTask($last, 'Last done', true);

        // The currently active attendance (no check-out) is ignored.
        repoTask($this->attendance, 'Active open');

        $result = $this->tasks->getIncompleteTasksFromLastSession($this->employee->id);

        expect($result->pluck('id')->all())->toBe([$lastOpen->id])
            ->and($result->first()->relationLoaded('attendance'))->toBeTrue();
    });

    it('lists the tasks of an attendance', function () {
        $a = repoTask($this->attendance, 'A');
        $b = repoTask($this->attendance, 'B', true);
        repoTask(repoAttendance($this->employee, '2026-03-10 09:00:00', '2026-03-10 17:00:00'), 'Elsewhere');

        $result = $this->tasks->getByAttendance($this->attendance);

        expect($result->pluck('id')->sort()->values()->all())->toBe([$a->id, $b->id]);
    });
});
