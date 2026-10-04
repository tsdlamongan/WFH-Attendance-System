<?php

use App\Enums\ActivityType;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Team;
use App\Models\User;
use App\Services\WhatsAppRecapService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const WA_COMMAND_SEND_URL = 'https://whatsapp.perekonomian.id/api/send/whatsapp';

/**
 * A team that qualifies for the recap command when it runs in its recap minute.
 */
function waCommandTeam(string $name, string $recapTime = '22:00', array $attributes = []): Team
{
    return Team::factory()->create(array_merge([
        'name' => $name,
        'whatsapp_api_secret' => 'secret-'.$name,
        'whatsapp_account_unique_id' => 'account-'.$name,
        'whatsapp_connected' => true,
        'whatsapp_recipient_phone' => '628123456789',
        'whatsapp_recap_enabled' => true,
        'whatsapp_recap_time' => $recapTime,
    ], $attributes));
}

beforeEach(function () {
    // Thursday 12 March 2026, 22:00:20 (app timezone): the 22:00 recap minute.
    // The scheduler fires a few seconds into the minute, so seconds must not matter.
    $this->travelTo(Carbon::parse('2026-03-12 22:00:20'));
    Http::preventStrayRequests();
});

it('reports that there is nothing to do when no team is scheduled for the current minute', function () {
    Http::fake();
    waCommandTeam('Morning Team', '08:00');

    $this->artisan('whatsapp:send-recap')
        ->expectsOutput('Found 0 team(s) scheduled for recap at 22:00:00')
        ->expectsOutput('No teams to process.')
        ->doesntExpectOutput('WhatsApp recap command completed.')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('does nothing when there are no teams at all', function () {
    Http::fake();

    $this->artisan('whatsapp:send-recap')
        ->expectsOutput('No teams to process.')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('sends the recap for a team scheduled at the current minute', function () {
    Http::fake([WA_COMMAND_SEND_URL => Http::response(['status' => 200], 200)]);

    $team = waCommandTeam('Alpha');
    $manager = User::factory()->create(['team_id' => $team->id, 'role' => UserRole::MANAGER]);
    User::factory()->create(['team_id' => $team->id, 'role' => UserRole::EMPLOYEE, 'name' => 'Alice']);

    $this->artisan('whatsapp:send-recap')
        ->expectsOutput('Found 1 team(s) scheduled for recap at 22:00:00')
        ->expectsOutput("Sending recap for team: Alpha (ID: {$team->id})")
        ->expectsOutput('✓ Successfully sent recap for team: Alpha')
        ->expectsOutput('WhatsApp recap command completed.')
        ->assertExitCode(0);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $fields = collect($request->data())->pluck('contents', 'name');

        return $request->url() === WA_COMMAND_SEND_URL
            && $fields['secret'] === 'secret-Alpha'
            && $fields['account'] === 'account-Alpha'
            && $fields['recipient'] === '628123456789'
            && str_contains($fields['message'], 'Alpha - 12 Mar 2026')
            && str_contains($fields['message'], '• Alice');
    });

    $team->refresh();
    expect($team->whatsapp_last_sent_at->toDateTimeString())->toBe('2026-03-12 22:00:20')
        ->and($team->whatsapp_last_error)->toBeNull();
    expect(ActivityLog::where('action', ActivityType::WHATSAPP_RECAP_SENT)->sole()->user_id)->toBe($manager->id);
});

it('only picks up enabled, connected teams with a recipient whose recap minute matches', function () {
    Http::fake([WA_COMMAND_SEND_URL => Http::response(['status' => 200], 200)]);

    $due = waCommandTeam('Due');
    waCommandTeam('Disabled', '22:00', ['whatsapp_recap_enabled' => false]);
    waCommandTeam('Disconnected', '22:00', ['whatsapp_connected' => false]);
    waCommandTeam('NoRecipient', '22:00', ['whatsapp_recipient_phone' => null]);
    waCommandTeam('OtherHour', '21:00');
    waCommandTeam('SameHourOtherMinute', '22:30');
    waCommandTeam('HalfPast', '23:30');

    $this->artisan('whatsapp:send-recap')
        ->expectsOutput('Found 1 team(s) scheduled for recap at 22:00:00')
        ->expectsOutput('✓ Successfully sent recap for team: Due')
        ->assertExitCode(0);

    Http::assertSentCount(1);
    expect($due->fresh()->whatsapp_last_sent_at)->not->toBeNull();
    expect(Team::whereNotNull('whatsapp_last_sent_at')->count())->toBe(1);
});

it('matches teams by the current minute regardless of the seconds', function () {
    Http::fake([WA_COMMAND_SEND_URL => Http::response(['status' => 200], 200)]);
    $team = waCommandTeam('Nine', '09:00');

    $this->travelTo(Carbon::parse('2026-03-12 09:00:59'));
    $this->artisan('whatsapp:send-recap')
        ->expectsOutput('Found 1 team(s) scheduled for recap at 09:00:00')
        ->assertExitCode(0);
    expect($team->fresh()->whatsapp_last_sent_at)->not->toBeNull();

    $this->travelTo(Carbon::parse('2026-03-12 09:01:00'));
    $this->artisan('whatsapp:send-recap')
        ->expectsOutput('Found 0 team(s) scheduled for recap at 09:01:00')
        ->assertExitCode(0);

    Http::assertSentCount(1);
});

it('fires a recap with minutes only in exactly that minute', function (string $now, bool $fires) {
    Http::fake([WA_COMMAND_SEND_URL => Http::response(['status' => 200], 200)]);
    $team = waCommandTeam('HalfPast', '22:30');

    $this->travelTo(Carbon::parse("2026-03-12 {$now}"));
    $this->artisan('whatsapp:send-recap')->assertExitCode(0);

    expect($team->fresh()->whatsapp_last_sent_at !== null)->toBe($fires);
    Http::assertSentCount($fires ? 1 : 0);
})->with([
    'the hour before' => ['22:00:00', false],
    'one minute early' => ['22:29:59', false],
    'first second of the minute' => ['22:30:00', true],
    'mid minute' => ['22:30:30', true],
    'last second of the minute' => ['22:30:59', true],
    'one minute late' => ['22:31:00', false],
    'the next hour' => ['23:00:00', false],
    'the same minute in another hour' => ['21:30:00', false],
]);

it('reports the minute it is looking for', function () {
    Http::fake();
    waCommandTeam('HalfPast', '22:30');

    $this->travelTo(Carbon::parse('2026-03-12 22:30:05'));
    $this->artisan('whatsapp:send-recap')
        ->expectsOutput('Found 1 team(s) scheduled for recap at 22:30:00')
        ->assertExitCode(0);
});

it('is scheduled every minute so that recap times with minutes can fire', function () {
    // Booting the console kernel loads routes/console.php, which registers the schedule.
    Artisan::call('list');

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command, 'whatsapp:send-recap'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});

it('reports a failed recap with the stored error and keeps processing the other teams', function () {
    Http::fake([
        WA_COMMAND_SEND_URL => Http::sequence()
            ->push(['status' => 500, 'message' => 'Gateway down'], 200)
            ->push(['status' => 200], 200),
    ]);

    $first = waCommandTeam('First');
    $second = waCommandTeam('Second');

    $this->artisan('whatsapp:send-recap')
        ->expectsOutput('Found 2 team(s) scheduled for recap at 22:00:00')
        ->expectsOutput('✗ Failed to send recap for team: First')
        ->expectsOutput('  Error: Gateway down')
        ->expectsOutput('✓ Successfully sent recap for team: Second')
        ->expectsOutput('WhatsApp recap command completed.')
        ->assertExitCode(0);

    Http::assertSentCount(2);

    $first->refresh();
    expect($first->whatsapp_last_error)->toBe('Gateway down')
        ->and($first->whatsapp_last_sent_at)->toBeNull();

    $second->refresh();
    expect($second->whatsapp_last_error)->toBeNull()
        ->and($second->whatsapp_last_sent_at)->not->toBeNull();
});

it('reports a team whose gateway credentials are missing without calling the gateway', function () {
    Http::fake();

    $team = waCommandTeam('NoSecret', '22:00', ['whatsapp_api_secret' => null]);

    $this->artisan('whatsapp:send-recap')
        ->expectsOutput('✗ Failed to send recap for team: NoSecret')
        ->expectsOutput('  Error: Team is not properly configured for WhatsApp recap')
        ->assertExitCode(0);

    Http::assertNothingSent();
    expect($team->fresh()->whatsapp_last_error)->toBe('Team is not properly configured for WhatsApp recap');
});

it('logs an unexpected exception for one team and continues with the next', function () {
    Log::spy();

    $first = waCommandTeam('Boom');
    waCommandTeam('Calm');

    $handled = [];
    $this->mock(WhatsAppRecapService::class, function ($mock) use (&$handled) {
        $mock->shouldReceive('sendRecap')->twice()->andReturnUsing(function (Team $team) use (&$handled) {
            $handled[] = $team->name;

            if ($team->name === 'Boom') {
                throw new Exception('unexpected failure');
            }

            return true;
        });
    });

    $this->artisan('whatsapp:send-recap')
        ->expectsOutput('Error processing team Boom: unexpected failure')
        ->expectsOutput('✓ Successfully sent recap for team: Calm')
        ->expectsOutput('WhatsApp recap command completed.')
        ->assertExitCode(0);

    expect($handled)->toBe(['Boom', 'Calm']);
    Log::shouldHaveReceived('error')
        ->with("WhatsApp recap command failed for team {$first->id}: unexpected failure")
        ->once();
});
