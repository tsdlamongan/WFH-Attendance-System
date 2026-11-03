<?php

use App\Enums\ActivityType;
use App\Enums\LeaveStatus;
use App\Models\ActivityLog;
use App\Models\Leave;
use App\Models\User;
use App\Services\LeaveService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

/**
 * Bearer header for a user. Guards are reset so several actors can be used in one test.
 */
function mlecAuth(User $user): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer '.$user->createToken('auth-token')->plainTextToken];
}

beforeEach(function () {
    $this->manager = $this->createManager();
    $this->employee = $this->createEmployee();
    $this->leave = $this->createLeave([
        'user_id' => $this->employee->id,
        'start_date' => Carbon::tomorrow(),
        'end_date' => Carbon::tomorrow()->addDays(2),
        'status' => LeaveStatus::PENDING,
    ]);

    // A completely separate team with its own employee and pending leave.
    $this->otherEmployee = User::factory()->create(['role' => 'employee']);
    $this->otherLeave = Leave::factory()->create([
        'user_id' => $this->otherEmployee->id,
        'status' => LeaveStatus::PENDING,
    ]);
});

afterEach(function () {
    Leave::flushEventListeners();
});

describe('index', function () {
    it('requires authentication', function () {
        $this->getJson('/api/v1/manager/leaves')->assertUnauthorized();
    });

    it('only lists leave requests from the managers own team', function () {
        $response = $this->getJson('/api/v1/manager/leaves', mlecAuth($this->manager));

        $response->assertOk()->assertJson(['success' => true]);
        $ids = collect($response->json('data'))->pluck('id')->all();

        expect($ids)->toBe([$this->leave->id])
            ->and($ids)->not->toContain($this->otherLeave->id);
    });

    it('lists the newest requests first', function () {
        $older = $this->createLeave([
            'user_id' => $this->employee->id,
            'created_at' => Carbon::now()->subDays(5),
        ]);
        $newest = $this->createLeave([
            'user_id' => $this->employee->id,
            'created_at' => Carbon::now()->addDay(),
        ]);

        $ids = collect(
            $this->getJson('/api/v1/manager/leaves', mlecAuth($this->manager))->json('data')
        )->pluck('id')->all();

        expect($ids)->toBe([$newest->id, $this->leave->id, $older->id]);
    });

    it('filters by status and ignores other teams when filtering', function () {
        $approved = $this->createLeave([
            'user_id' => $this->employee->id,
            'status' => LeaveStatus::APPROVED,
        ]);
        Leave::factory()->create([
            'user_id' => $this->otherEmployee->id,
            'status' => LeaveStatus::APPROVED,
        ]);

        $response = $this->getJson('/api/v1/manager/leaves?status=approved', mlecAuth($this->manager));

        $response->assertOk();
        expect(collect($response->json('data'))->pluck('id')->all())->toBe([$approved->id])
            ->and($response->json('data.0.status'))->toBe('approved');
    });

    it('returns an empty list for a status nobody has', function () {
        $response = $this->getJson('/api/v1/manager/leaves?status=rejected', mlecAuth($this->manager));

        $response->assertOk()->assertExactJson(['success' => true, 'data' => []]);
    });

    it('includes the employee and approver in each row', function () {
        $this->leave->update([
            'status' => LeaveStatus::APPROVED,
            'approved_by' => $this->manager->id,
            'approved_at' => Carbon::now(),
        ]);

        $response = $this->getJson('/api/v1/manager/leaves', mlecAuth($this->manager));

        $response->assertOk()
            ->assertJsonPath('data.0.user.id', $this->employee->id)
            ->assertJsonPath('data.0.approver.id', $this->manager->id)
            ->assertJsonPath('data.0.days', 3);
    });

    it('returns a generic 500 and logs when loading fails', function () {
        Log::spy();
        Leave::retrieved(function () {
            throw new RuntimeException('database exploded');
        });

        $response = $this->getJson('/api/v1/manager/leaves', mlecAuth($this->manager));

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Failed to get leave requests']);
        expect($response->getContent())->not->toContain('database exploded');
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Get leave requests failed: database exploded'))
            ->once();
    });
});

describe('update', function () {
    it('returns 404 when the leave does not exist', function () {
        $response = $this->putJson('/api/v1/manager/leaves/999999', [
            'start_date' => Carbon::tomorrow()->format('Y-m-d'),
        ], mlecAuth($this->manager));

        $response->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Pengajuan cuti tidak ditemukan.']);
    });

    it('returns 403 and leaves the record untouched for another teams leave', function () {
        $before = $this->otherLeave->fresh()->start_date->format('Y-m-d');

        $response = $this->putJson("/api/v1/manager/leaves/{$this->otherLeave->id}", [
            'start_date' => Carbon::now()->addDays(20)->format('Y-m-d'),
            'end_date' => Carbon::now()->addDays(21)->format('Y-m-d'),
        ], mlecAuth($this->manager));

        $response->assertForbidden()->assertJson(['success' => false]);
        expect($this->otherLeave->fresh()->start_date->format('Y-m-d'))->toBe($before);
        $this->assertDatabaseMissing('activity_logs', ['action' => ActivityType::LEAVE_EDITED->value]);
    });

    it('rejects malformed dates with field errors', function () {
        $response = $this->putJson("/api/v1/manager/leaves/{$this->leave->id}", [
            'start_date' => 'not-a-date',
            'created_at' => 'yesterday-ish',
            'approved_at' => 'nope',
        ], mlecAuth($this->manager));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date', 'created_at', 'approved_at']);
        expect($response->json('errors.start_date.0'))->toBe('Tanggal mulai harus berupa tanggal yang valid.');
    });

    it('rejects an end date before the start date', function () {
        $response = $this->putJson("/api/v1/manager/leaves/{$this->leave->id}", [
            'start_date' => '2030-05-10',
            'end_date' => '2030-05-09',
        ], mlecAuth($this->manager));

        $response->assertUnprocessable()->assertJsonValidationErrors(['end_date']);
        expect($response->json('errors.end_date.0'))
            ->toBe('Tanggal selesai harus sama atau setelah tanggal mulai.');
    });

    it('accepts a single day leave where start and end are equal', function () {
        $response = $this->putJson("/api/v1/manager/leaves/{$this->leave->id}", [
            'start_date' => '2030-05-10',
            'end_date' => '2030-05-10',
        ], mlecAuth($this->manager));

        $response->assertOk()
            ->assertJsonPath('data.days', 1)
            ->assertJsonPath('data.start_date', '2030-05-10')
            ->assertJsonPath('data.end_date', '2030-05-10');
    });

    it('records a change summary in the activity log', function () {
        $oldStart = $this->leave->start_date->format('Y-m-d');

        $this->putJson("/api/v1/manager/leaves/{$this->leave->id}", [
            'start_date' => '2030-06-01',
            'end_date' => '2030-06-03',
            'approved_at' => '2030-06-02 10:00:00',
        ], mlecAuth($this->manager))->assertOk();

        $log = ActivityLog::where('action', ActivityType::LEAVE_EDITED->value)->first();

        expect($log)->not->toBeNull()
            ->and($log->user_id)->toBe($this->manager->id)
            ->and($log->description)->toContain("start_date: {$oldStart} -> 2030-06-01")
            ->and($log->description)->toContain('end_date:')
            ->and($log->description)->toContain('approved_at: null ->');
    });

    it('writes no edit log entry when nothing actually changed', function () {
        $same = $this->leave->start_date->format('Y-m-d');

        $this->putJson("/api/v1/manager/leaves/{$this->leave->id}", [
            'start_date' => $same,
        ], mlecAuth($this->manager))->assertOk();

        $this->assertDatabaseMissing('activity_logs', ['action' => ActivityType::LEAVE_EDITED->value]);
    });

    it('returns a generic 500 when the service fails', function () {
        Log::spy();
        $this->mock(LeaveService::class, function ($mock) {
            $mock->shouldReceive('updateLeave')->once()->andThrow(new RuntimeException('disk full'));
        });

        $response = $this->putJson("/api/v1/manager/leaves/{$this->leave->id}", [
            'start_date' => '2030-06-01',
        ], mlecAuth($this->manager));

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Gagal memperbarui pengajuan cuti.']);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Update leave failed: disk full'))
            ->once();
    });

    it('returns 500 and leaves the leave unchanged when saving fails', function () {
        $before = $this->leave->fresh()->start_date->format('Y-m-d');
        Leave::updating(function () {
            throw new RuntimeException('cannot save');
        });

        $response = $this->putJson("/api/v1/manager/leaves/{$this->leave->id}", [
            'start_date' => '2031-01-01',
            'end_date' => '2031-01-02',
        ], mlecAuth($this->manager));

        $response->assertStatus(500)->assertJson(['success' => false]);
        expect($this->leave->fresh()->start_date->format('Y-m-d'))->toBe($before);
    });
});

describe('approve', function () {
    it('approves, stamps the approver and stores the notes', function () {
        $this->travelTo(Carbon::parse('2030-02-01 09:30:00'));

        $response = $this->putJson("/api/v1/manager/leaves/{$this->leave->id}/approve", [
            'notes' => 'Enjoy the break',
        ], mlecAuth($this->manager));

        $response->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.notes', 'Enjoy the break')
            ->assertJsonPath('data.approved_by', $this->manager->id)
            ->assertJsonPath('message', 'Leave request approved');

        $fresh = $this->leave->fresh();
        expect($fresh->status)->toBe(LeaveStatus::APPROVED)
            ->and($fresh->approved_by)->toBe($this->manager->id)
            ->and($fresh->approved_at->format('Y-m-d H:i:s'))->toBe('2030-02-01 09:30:00');
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->manager->id,
            'action' => ActivityType::LEAVE_APPROVED->value,
        ]);
    });

    it('approves without notes and leaves notes empty', function () {
        $response = $this->putJson("/api/v1/manager/leaves/{$this->leave->id}/approve", [], mlecAuth($this->manager));

        $response->assertOk()->assertJsonPath('data.notes', null);
    });

    it('returns 404 instead of a 500 when the leave does not exist', function () {
        Log::spy();

        $response = $this->putJson('/api/v1/manager/leaves/999999/approve', [], mlecAuth($this->manager));

        $response->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Leave request not found']);
        Log::shouldNotHaveReceived('error');
    });

    it('refuses to approve a leave from another team', function () {
        $response = $this->putJson("/api/v1/manager/leaves/{$this->otherLeave->id}/approve", [
            'notes' => 'sneaky',
        ], mlecAuth($this->manager));

        $response->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Unauthorized to approve this leave request']);
        expect($this->otherLeave->fresh()->status)->toBe(LeaveStatus::PENDING)
            ->and($this->otherLeave->fresh()->approved_by)->toBeNull();
    });

    it('is forbidden for employees and unauthenticated users', function () {
        $this->putJson("/api/v1/manager/leaves/{$this->leave->id}/approve")->assertUnauthorized();

        $this->putJson("/api/v1/manager/leaves/{$this->leave->id}/approve", [], mlecAuth($this->employee))
            ->assertForbidden();
        expect($this->leave->fresh()->status)->toBe(LeaveStatus::PENDING);
    });

    it('returns a generic 500 and keeps the leave pending when approval fails', function () {
        Log::spy();
        $this->mock(LeaveService::class, function ($mock) {
            $mock->shouldReceive('approveLeave')->once()->andThrow(new RuntimeException('smtp down'));
        });

        $response = $this->putJson("/api/v1/manager/leaves/{$this->leave->id}/approve", [], mlecAuth($this->manager));

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Failed to approve leave request']);
        expect($this->leave->fresh()->status)->toBe(LeaveStatus::PENDING);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Approve leave failed: smtp down'))
            ->once();
    });
});

describe('reject', function () {
    it('rejects, stamps the approver and stores the notes', function () {
        $response = $this->putJson("/api/v1/manager/leaves/{$this->leave->id}/reject", [
            'notes' => 'Team is short staffed',
        ], mlecAuth($this->manager));

        $response->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.notes', 'Team is short staffed')
            ->assertJsonPath('data.approved_by', $this->manager->id)
            ->assertJsonPath('message', 'Leave request rejected');

        expect($this->leave->fresh()->status)->toBe(LeaveStatus::REJECTED);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->manager->id,
            'action' => ActivityType::LEAVE_REJECTED->value,
        ]);
    });

    it('returns 404 instead of a 500 when the leave does not exist', function () {
        Log::spy();

        $response = $this->putJson('/api/v1/manager/leaves/999999/reject', [], mlecAuth($this->manager));

        $response->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Leave request not found']);
        Log::shouldNotHaveReceived('error');
    });

    it('refuses to reject a leave from another team', function () {
        $response = $this->putJson("/api/v1/manager/leaves/{$this->otherLeave->id}/reject", [
            'notes' => 'nope',
        ], mlecAuth($this->manager));

        $response->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Unauthorized to reject this leave request']);
        expect($this->otherLeave->fresh()->status)->toBe(LeaveStatus::PENDING);
    });

    it('is forbidden for employees and unauthenticated users', function () {
        $this->putJson("/api/v1/manager/leaves/{$this->leave->id}/reject")->assertUnauthorized();

        $this->putJson("/api/v1/manager/leaves/{$this->leave->id}/reject", [], mlecAuth($this->employee))
            ->assertForbidden();
        expect($this->leave->fresh()->status)->toBe(LeaveStatus::PENDING);
    });

    it('returns a generic 500 and keeps the leave pending when rejection fails', function () {
        Log::spy();
        $this->mock(LeaveService::class, function ($mock) {
            $mock->shouldReceive('rejectLeave')->once()->andThrow(new RuntimeException('queue broken'));
        });

        $response = $this->putJson("/api/v1/manager/leaves/{$this->leave->id}/reject", [], mlecAuth($this->manager));

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Failed to reject leave request']);
        expect($this->leave->fresh()->status)->toBe(LeaveStatus::PENDING);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Reject leave failed: queue broken'))
            ->once();
    });
});
