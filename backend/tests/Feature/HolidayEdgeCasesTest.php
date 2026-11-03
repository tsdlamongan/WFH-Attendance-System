<?php

use App\Enums\ActivityType;
use App\Models\Holiday;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

/**
 * Store the holiday date as a plain Y-m-d string, exactly what a DATE column returns on
 * PostgreSQL/MySQL. SQLite keeps the "Y-m-d 00:00:00" text written by the Eloquent date cast,
 * which the unique validation rule (comparing against "Y-m-d") would never match.
 */
function hecStoreAsPlainDate(Holiday $holiday): Holiday
{
    DB::table('holidays')->where('id', $holiday->id)->update(['date' => $holiday->date->format('Y-m-d')]);

    return $holiday;
}

/**
 * Bearer header for a user. Guards are reset so several actors can be used in one test.
 */
function hecAuth(User $user): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer '.$user->createToken('auth-token')->plainTextToken];
}

beforeEach(function () {
    $this->manager = $this->createManager();
    $this->employee = $this->createEmployee();

    // A holiday that belongs to a different team.
    $this->otherTeamManager = User::factory()->create(['role' => 'manager']);
    $this->otherHoliday = Holiday::factory()->create([
        'team_id' => $this->otherTeamManager->team_id,
        'date' => '2030-08-17',
        'name' => 'Other Team Day',
    ]);
});

afterEach(function () {
    Holiday::flushEventListeners();
});

describe('index', function () {
    it('requires authentication', function () {
        $this->getJson('/api/v1/holidays')->assertUnauthorized();
    });

    it('defaults to the current year when no year is given', function () {
        $this->travelTo(Carbon::parse('2030-06-15 10:00:00'));
        $thisYear = $this->createHoliday(['date' => '2030-12-25', 'name' => 'This Year']);
        $this->createHoliday(['date' => '2029-12-25', 'name' => 'Last Year']);
        $this->createHoliday(['date' => '2031-01-01', 'name' => 'Next Year']);

        $response = $this->getJson('/api/v1/holidays', hecAuth($this->employee));

        $response->assertOk();
        expect(collect($response->json('data'))->pluck('id')->all())->toBe([$thisYear->id]);
    });

    it('filters by an explicit year and includes both year boundaries', function () {
        $first = $this->createHoliday(['date' => '2029-01-01', 'name' => 'New Year']);
        $last = $this->createHoliday(['date' => '2029-12-31', 'name' => 'New Years Eve']);
        $this->createHoliday(['date' => '2028-12-31', 'name' => 'Before']);
        $this->createHoliday(['date' => '2030-01-01', 'name' => 'After']);

        $response = $this->getJson('/api/v1/holidays?year=2029', hecAuth($this->manager));

        $response->assertOk();
        expect(collect($response->json('data'))->pluck('id')->all())->toBe([$first->id, $last->id]);
    });

    it('orders holidays chronologically regardless of creation order', function () {
        $december = $this->createHoliday(['date' => '2029-12-25']);
        $march = $this->createHoliday(['date' => '2029-03-10']);
        $july = $this->createHoliday(['date' => '2029-07-04']);

        $response = $this->getJson('/api/v1/holidays?year=2029', hecAuth($this->employee));

        expect(collect($response->json('data'))->pluck('id')->all())
            ->toBe([$march->id, $july->id, $december->id]);
    });

    it('never exposes another teams holidays', function () {
        $mine = $this->createHoliday(['date' => '2030-08-18', 'name' => 'Mine']);

        $response = $this->getJson('/api/v1/holidays?year=2030', hecAuth($this->employee));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        expect($ids)->toBe([$mine->id])
            ->and($ids)->not->toContain($this->otherHoliday->id);
    });

    it('returns an empty list for a year without holidays', function () {
        $this->createHoliday(['date' => '2030-08-18']);

        $this->getJson('/api/v1/holidays?year=1999', hecAuth($this->employee))
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => []]);
    });

    it('returns a generic 500 and logs when the query fails', function () {
        Log::spy();
        $this->createHoliday(['date' => '2030-08-18']);
        Holiday::retrieved(function () {
            throw new RuntimeException('connection lost');
        });

        $response = $this->getJson('/api/v1/holidays?year=2030', hecAuth($this->employee));

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Failed to get holidays']);
        expect($response->getContent())->not->toContain('connection lost');
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Get holidays failed: connection lost'))
            ->once();
    });
});

describe('store', function () {
    it('requires authentication', function () {
        $this->postJson('/api/v1/manager/holidays', ['date' => '2030-01-01', 'name' => 'X'])
            ->assertUnauthorized();
    });

    it('requires a date and a name', function () {
        $response = $this->postJson('/api/v1/manager/holidays', [], hecAuth($this->manager));

        $response->assertUnprocessable()->assertJsonValidationErrors(['date', 'name']);
        expect($response->json('errors.date.0'))->toBe('Tanggal wajib diisi.')
            ->and($response->json('errors.name.0'))->toBe('Nama libur wajib diisi.');
        expect(Holiday::where('team_id', $this->manager->team_id)->count())->toBe(0);
    });

    it('rejects an invalid date', function () {
        $response = $this->postJson('/api/v1/manager/holidays', [
            'date' => 'someday',
            'name' => 'Mystery Day',
        ], hecAuth($this->manager));

        $response->assertUnprocessable()->assertJsonValidationErrors(['date']);
        expect($response->json('errors.date.0'))->toBe('Tanggal tidak valid.');
    });

    it('enforces the name and description length limits', function () {
        $response = $this->postJson('/api/v1/manager/holidays', [
            'date' => '2030-09-09',
            'name' => str_repeat('n', 256),
            'description' => str_repeat('d', 501),
        ], hecAuth($this->manager));

        $response->assertUnprocessable()->assertJsonValidationErrors(['name', 'description']);
    });

    it('accepts the maximum allowed name and description lengths', function () {
        $response = $this->postJson('/api/v1/manager/holidays', [
            'date' => '2030-09-09',
            'name' => str_repeat('n', 255),
            'description' => str_repeat('d', 500),
        ], hecAuth($this->manager));

        $response->assertCreated();
        $holiday = Holiday::where('team_id', $this->manager->team_id)->sole();
        expect($holiday->date->format('Y-m-d'))->toBe('2030-09-09')
            ->and(strlen($holiday->name))->toBe(255)
            ->and(strlen($holiday->description))->toBe(500);
    });

    it('allows the description to be omitted', function () {
        $response = $this->postJson('/api/v1/manager/holidays', [
            'date' => '2030-09-10',
            'name' => 'Quiet Day',
        ], hecAuth($this->manager));

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Quiet Day')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('message', 'Holiday created successfully');
    });

    it('rejects a second holiday on the same date within the team', function () {
        hecStoreAsPlainDate($this->createHoliday(['date' => '2030-10-01', 'name' => 'First']));

        $response = $this->postJson('/api/v1/manager/holidays', [
            'date' => '2030-10-01',
            'name' => 'Duplicate',
        ], hecAuth($this->manager));

        $response->assertUnprocessable()->assertJsonValidationErrors(['date']);
        expect($response->json('errors.date.0'))->toBe('Tanggal libur ini sudah terdaftar untuk tim Anda.');
        expect(Holiday::where('team_id', $this->manager->team_id)->count())->toBe(1);
    });

    it('allows a date that another team already uses and stamps the callers team', function () {
        $response = $this->postJson('/api/v1/manager/holidays', [
            'date' => $this->otherHoliday->date->format('Y-m-d'),
            'name' => 'Same date, my team',
        ], hecAuth($this->manager));

        $response->assertCreated();
        $this->assertDatabaseHas('holidays', [
            'team_id' => $this->manager->team_id,
            'name' => 'Same date, my team',
        ]);
        expect(Holiday::count())->toBe(2);
    });

    it('ignores a team_id supplied by the client', function () {
        $this->postJson('/api/v1/manager/holidays', [
            'date' => '2030-10-02',
            'name' => 'Spoofed',
            'team_id' => $this->otherTeamManager->team_id,
        ], hecAuth($this->manager))->assertCreated();

        expect(Holiday::where('name', 'Spoofed')->value('team_id'))->toBe($this->manager->team_id);
    });

    it('logs a holiday created activity', function () {
        $this->postJson('/api/v1/manager/holidays', [
            'date' => '2030-10-03',
            'name' => 'Logged',
        ], hecAuth($this->manager))->assertCreated();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->manager->id,
            'action' => ActivityType::HOLIDAY_CREATED->value,
        ]);
    });

    it('returns a generic 500 and stores nothing when creation fails', function () {
        Log::spy();
        Holiday::creating(function () {
            throw new RuntimeException('insert failed');
        });

        $response = $this->postJson('/api/v1/manager/holidays', [
            'date' => '2030-10-04',
            'name' => 'Doomed',
        ], hecAuth($this->manager));

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Failed to create holiday']);
        $this->assertDatabaseMissing('holidays', ['name' => 'Doomed']);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Create holiday failed: insert failed'))
            ->once();
    });
});

describe('update', function () {
    it('lets a manager keep the same date while changing other fields', function () {
        $holiday = $this->createHoliday(['date' => '2030-11-11', 'name' => 'Old', 'description' => 'old text']);

        $response = $this->putJson("/api/v1/manager/holidays/{$holiday->id}", [
            'date' => '2030-11-11',
            'name' => 'Renamed',
            'description' => 'new text',
        ], hecAuth($this->manager));

        $response->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.description', 'new text')
            ->assertJsonPath('data.date', '2030-11-11')
            ->assertJsonPath('message', 'Holiday updated successfully');
    });

    it('moves a holiday to a new date', function () {
        $holiday = $this->createHoliday(['date' => '2030-11-11']);

        $this->putJson("/api/v1/manager/holidays/{$holiday->id}", [
            'date' => '2030-11-12',
            'name' => $holiday->name,
        ], hecAuth($this->manager))->assertOk();

        expect($holiday->fresh()->date->format('Y-m-d'))->toBe('2030-11-12');
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->manager->id,
            'action' => ActivityType::HOLIDAY_UPDATED->value,
        ]);
    });

    it('rejects moving a holiday onto a date another holiday of the team uses', function () {
        hecStoreAsPlainDate($this->createHoliday(['date' => '2030-11-20', 'name' => 'Taken']));
        $holiday = $this->createHoliday(['date' => '2030-11-21', 'name' => 'Movable']);

        $response = $this->putJson("/api/v1/manager/holidays/{$holiday->id}", [
            'date' => '2030-11-20',
            'name' => 'Movable',
        ], hecAuth($this->manager));

        $response->assertUnprocessable()->assertJsonValidationErrors(['date']);
        expect($holiday->fresh()->date->format('Y-m-d'))->toBe('2030-11-21');
    });

    it('validates required fields on update', function () {
        $holiday = $this->createHoliday(['date' => '2030-11-11']);

        $response = $this->putJson("/api/v1/manager/holidays/{$holiday->id}", [], hecAuth($this->manager));

        $response->assertUnprocessable()->assertJsonValidationErrors(['date', 'name']);
    });

    it('returns 404 instead of a 500 when the holiday does not exist', function () {
        Log::spy();

        $response = $this->putJson('/api/v1/manager/holidays/999999', [
            'date' => '2030-12-01',
            'name' => 'Ghost',
        ], hecAuth($this->manager));

        $response->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Holiday not found']);
        Log::shouldNotHaveReceived('error');
    });

    it('returns 403 and changes nothing for another teams holiday', function () {
        $response = $this->putJson("/api/v1/manager/holidays/{$this->otherHoliday->id}", [
            'date' => '2030-12-01',
            'name' => 'Hijacked',
        ], hecAuth($this->manager));

        $response->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Unauthorized to update this holiday']);
        $fresh = $this->otherHoliday->fresh();
        expect($fresh->name)->toBe('Other Team Day')
            ->and($fresh->date->format('Y-m-d'))->toBe('2030-08-17');
    });

    it('forbids employees and unauthenticated users', function () {
        $holiday = $this->createHoliday(['date' => '2030-11-11']);
        $payload = ['date' => '2030-11-11', 'name' => 'Nope'];

        $this->putJson("/api/v1/manager/holidays/{$holiday->id}", $payload)->assertUnauthorized();
        $this->putJson("/api/v1/manager/holidays/{$holiday->id}", $payload, hecAuth($this->employee))
            ->assertForbidden();
        expect($holiday->fresh()->name)->not->toBe('Nope');
    });

    it('returns a generic 500 and keeps the old values when saving fails', function () {
        Log::spy();
        $holiday = $this->createHoliday(['date' => '2030-11-11', 'name' => 'Original']);
        Holiday::updating(function () {
            throw new RuntimeException('update failed');
        });

        $response = $this->putJson("/api/v1/manager/holidays/{$holiday->id}", [
            'date' => '2030-11-11',
            'name' => 'Changed',
        ], hecAuth($this->manager));

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Failed to update holiday']);
        expect($holiday->fresh()->name)->toBe('Original');
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Update holiday failed: update failed'))
            ->once();
    });
});

describe('destroy', function () {
    it('deletes only the targeted holiday and logs the activity', function () {
        $target = $this->createHoliday(['date' => '2030-12-24']);
        $keep = $this->createHoliday(['date' => '2030-12-25']);

        $response = $this->deleteJson("/api/v1/manager/holidays/{$target->id}", [], hecAuth($this->manager));

        $response->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Holiday deleted successfully']);
        $this->assertDatabaseMissing('holidays', ['id' => $target->id]);
        $this->assertDatabaseHas('holidays', ['id' => $keep->id]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->manager->id,
            'action' => ActivityType::HOLIDAY_DELETED->value,
        ]);
    });

    it('returns 404 instead of a 500 when the holiday does not exist', function () {
        Log::spy();

        $response = $this->deleteJson('/api/v1/manager/holidays/999999', [], hecAuth($this->manager));

        $response->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Holiday not found']);
        Log::shouldNotHaveReceived('error');
    });

    it('returns 403 and keeps another teams holiday', function () {
        $response = $this->deleteJson("/api/v1/manager/holidays/{$this->otherHoliday->id}", [], hecAuth($this->manager));

        $response->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Unauthorized to delete this holiday']);
        $this->assertDatabaseHas('holidays', ['id' => $this->otherHoliday->id]);
    });

    it('forbids employees and unauthenticated users', function () {
        $holiday = $this->createHoliday(['date' => '2030-12-24']);

        $this->deleteJson("/api/v1/manager/holidays/{$holiday->id}")->assertUnauthorized();
        $this->deleteJson("/api/v1/manager/holidays/{$holiday->id}", [], hecAuth($this->employee))
            ->assertForbidden();
        $this->assertDatabaseHas('holidays', ['id' => $holiday->id]);
    });

    it('returns a generic 500 and keeps the holiday when deletion fails', function () {
        Log::spy();
        $holiday = $this->createHoliday(['date' => '2030-12-24']);
        Holiday::deleting(function () {
            throw new RuntimeException('delete failed');
        });

        $response = $this->deleteJson("/api/v1/manager/holidays/{$holiday->id}", [], hecAuth($this->manager));

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Failed to delete holiday']);
        $this->assertDatabaseHas('holidays', ['id' => $holiday->id]);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Delete holiday failed: delete failed'))
            ->once();
    });
});
