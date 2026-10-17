<?php

use App\Models\Attendance;
use App\Models\Project;
use App\Models\Task;
use App\Services\EvaluationReportPdf;
use App\Services\EvaluationReportService;
use App\Services\ProblemSummaryService;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\Traits\CreatesTeamUsers;

uses(CreatesTeamUsers::class);

/*
 * PROBLEM column summaries via Gemini: one request per report for every long entry that is not
 * cached yet, and never a failure of the report itself.
 */

const PS_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

/**
 * A Gemini success response carrying the given {id, summary} pairs as JSON text.
 */
function psReply(array $summaries): array
{
    return [
        'candidates' => [[
            'content' => ['role' => 'model', 'parts' => [['text' => json_encode(['summaries' => $summaries])]]],
            'finishReason' => 'STOP',
        ]],
        'usageMetadata' => ['promptTokenCount' => 200, 'candidatesTokenCount' => 60, 'totalTokenCount' => 260],
    ];
}

function psLongList(string $prefix = 'Pekerjaan'): string
{
    return implode('; ', array_map(fn (int $n) => "{$prefix} nomor {$n} dengan deskripsi panjang", range(1, 6)));
}

beforeEach(function () {
    config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-2.5-flash']);
    Http::preventStrayRequests();
    Sleep::fake(); // retries wait between attempts
    $this->service = app(ProblemSummaryService::class);
});

describe('selection', function () {
    it('does nothing without an API key or when switched off', function () {
        Http::fake();

        config(['services.gemini.key' => null]);
        expect($this->service->isEnabled())->toBeFalse()
            ->and($this->service->summarize([['id' => 1, 'name' => 'A', 'problem' => psLongList()]]))->toBe([]);

        config(['services.gemini.key' => 'test-key', 'evaluation_report.problem_summary.enabled' => false]);
        expect($this->service->isEnabled())->toBeFalse()
            ->and($this->service->summarize([['id' => 1, 'name' => 'A', 'problem' => psLongList()]]))->toBe([]);

        Http::assertNothingSent();
    });

    it('sends only long entries, in one request, and maps the answers back by id', function () {
        Http::fake([PS_ENDPOINT => Http::response(psReply([
            ['id' => 1, 'summary' => "  Fokus pada  modul penggajian.\n(Menunggu data HRD)  "],
            ['id' => 3, 'summary' => 'Integrasi dan pengujian modul KRS.'],
        ]))]);

        $result = $this->service->summarize([
            ['id' => 1, 'name' => 'Ustadz Link', 'problem' => psLongList('Gaji')],
            ['id' => 2, 'name' => 'Pendek', 'problem' => 'Fixing WA Notif'],
            ['id' => 3, 'name' => 'SIAKAD', 'problem' => psLongList('KRS')],
            ['id' => 4, 'name' => 'Kosong', 'problem' => EvaluationReportService::NO_WORK_LABEL],
        ]);

        expect($result)->toBe([
            1 => 'Fokus pada modul penggajian. (Menunggu data HRD)',
            3 => 'Integrasi dan pengujian modul KRS.',
        ]);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $prompt = $request['contents'][0]['parts'][0]['text'];

            return $request->hasHeader('x-goog-api-key', 'test-key')
                && $request['generationConfig']['responseMimeType'] === 'application/json'
                && str_contains($prompt, '1 | Ustadz Link | Gaji nomor 1')
                && str_contains($prompt, '3 | SIAKAD | KRS nomor 1')
                && ! str_contains($prompt, 'Pendek')
                && ! str_contains($prompt, 'Kosong');
        });
    });

    it('does nothing when every entry is short', function () {
        Http::fake();

        expect($this->service->summarize([['id' => 1, 'name' => 'A', 'problem' => 'Fixing WA Notif']]))->toBe([]);

        Http::assertNothingSent();
    });
});

describe('answers', function () {
    it('caches a summary by its text, so the download after the preview costs no request', function () {
        Http::fake([PS_ENDPOINT => Http::response(psReply([['id' => 7, 'summary' => 'Ringkasan.']]))]);
        $projects = [['id' => 7, 'name' => 'A', 'problem' => psLongList()]];

        expect($this->service->summarize($projects))->toBe([7 => 'Ringkasan.'])
            ->and($this->service->summarize($projects))->toBe([7 => 'Ringkasan.'])
            // Same text under another project id: still cached.
            ->and($this->service->summarize([['id' => 9, 'name' => 'B', 'problem' => psLongList()]]))->toBe([9 => 'Ringkasan.']);

        Http::assertSentCount(1);
        expect(Cache::get('evaluation-report:problem-summary:'.sha1('gemini-2.5-flash|'.psLongList())))->toBe('Ringkasan.');
    });

    it('requests only the entries that are not cached yet', function () {
        Http::fake([PS_ENDPOINT => Http::sequence()
            ->push(psReply([['id' => 1, 'summary' => 'Satu.']]))
            ->push(psReply([['id' => 2, 'summary' => 'Dua.']]))]);

        $this->service->summarize([['id' => 1, 'name' => 'A', 'problem' => psLongList('A')]]);

        $result = $this->service->summarize([
            ['id' => 1, 'name' => 'A', 'problem' => psLongList('A')],
            ['id' => 2, 'name' => 'B', 'problem' => psLongList('B')],
        ]);

        expect($result)->toBe([1 => 'Satu.', 2 => 'Dua.']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_contains($request['contents'][0]['parts'][0]['text'], '2 | B |')
            && ! str_contains($request['contents'][0]['parts'][0]['text'], '1 | A |'));
    });

    it('ignores unknown ids and empty summaries and caps over-long ones', function () {
        config(['evaluation_report.problem_summary.max_chars' => 40]);
        Http::fake([PS_ENDPOINT => Http::response(psReply([
            ['id' => 99, 'summary' => 'Project yang tidak dikirim.'],
            ['id' => 1, 'summary' => '   '],
            ['id' => 2, 'summary' => str_repeat('Kalimat panjang sekali ', 5)],
            ['summary' => 'Tanpa id'],
        ]))]);

        $result = $this->service->summarize([
            ['id' => 1, 'name' => 'A', 'problem' => psLongList('A')],
            ['id' => 2, 'name' => 'B', 'problem' => psLongList('B')],
        ]);

        expect($result)->toHaveKeys([2])->not->toHaveKey(1)->not->toHaveKey(99)
            // max_chars of text plus the ellipsis
            ->and(mb_strlen($result[2]))->toBe(41)
            ->and($result[2])->toEndWith('…');
    });
});

describe('failures', function () {
    it('keeps the plain list when Gemini is unavailable, answers nonsense or cannot be reached', function (string $case) {
        Log::spy();
        Http::fake([PS_ENDPOINT => match ($case) {
            'http error' => Http::response(['error' => ['code' => 503, 'message' => 'high demand']], 503),
            'not json' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'maaf, tidak bisa']]]]]]),
            'no candidates' => Http::response(['promptFeedback' => ['blockReason' => 'SAFETY']]),
            'wrong shape' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"items": []}']]]]]]),
            default => fn () => throw new ConnectionException('timed out'),
        }]);

        expect($this->service->summarize([['id' => 1, 'name' => 'A', 'problem' => psLongList()]]))->toBe([]);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_starts_with($message, 'Problem summary skipped: '));
        expect(Cache::get('evaluation-report:problem-summary:'.sha1('gemini-2.5-flash|'.psLongList())))->toBeNull();
        // Server errors are retried, bad answers are not (a faked connection error leaves no record to count).
        if ($case !== 'connection error') {
            Http::assertSentCount($case === 'http error' ? 3 : 1);
        }
    })->with(['http error', 'not json', 'no candidates', 'wrong shape', 'connection error']);

    it('retries a momentary server error and does not retry a rejected request', function () {
        Log::spy();
        Http::fake([PS_ENDPOINT => Http::sequence()
            ->push(['error' => ['code' => 503, 'message' => 'high demand']], 503)
            ->push(psReply([['id' => 1, 'summary' => 'Akhirnya.']]))
            ->push(['error' => ['code' => 400, 'message' => 'API key not valid']], 400)]);

        expect($this->service->summarize([['id' => 1, 'name' => 'A', 'problem' => psLongList('A')]]))->toBe([1 => 'Akhirnya.'])
            ->and($this->service->summarize([['id' => 2, 'name' => 'B', 'problem' => psLongList('B')]]))->toBe([]);

        Http::assertSentCount(3);
        Log::shouldHaveReceived('warning')->once()->with('Problem summary skipped: Gemini answered HTTP 400');
    });
});

describe('in the evaluation report', function () {
    it('prints the summary in PROBLEM and keeps the full list next to it', function () {
        $this->travelTo(Carbon::parse('2030-03-25 10:00:00'));
        $team = $this->createTeam(['name' => 'IT Development']);
        $alice = $this->createEmployee(['name' => 'Alice']);
        $busy = Project::factory()->create(['team_id' => $team->id, 'name' => 'SIAKAD']);
        $quiet = Project::factory()->create(['team_id' => $team->id, 'name' => 'Sukasir']);

        $attendance = Attendance::create([
            'user_id' => $alice->id,
            'check_in' => '2030-03-18 08:00:00',
            'check_out' => '2030-03-18 16:00:00',
            'date' => '2030-03-18',
            'total_hours' => 8.0,
        ]);
        foreach (range(1, 6) as $n) {
            Task::create(['attendance_id' => $attendance->id, 'project_id' => $busy->id, 'title' => "Slicing halaman nomor {$n} beserta pengujiannya", 'is_completed' => true]);
        }
        Task::create(['attendance_id' => $attendance->id, 'project_id' => $quiet->id, 'title' => 'Fixing WA Notif', 'is_completed' => true]);

        Http::fake([PS_ENDPOINT => Http::response(psReply([['id' => $busy->id, 'summary' => 'Slicing dan pengujian enam halaman.']]))]);

        $rows = collect(app(EvaluationReportService::class)->build($team, Carbon::parse('2030-03-18'), Carbon::parse('2030-03-23')->endOfDay())['projects']['rows'])->keyBy('name');

        expect($rows['SIAKAD'])->toMatchArray([
            'problem' => 'Slicing dan pengujian enam halaman.',
            'problem_detail' => implode('; ', array_map(fn (int $n) => "Slicing halaman nomor {$n} beserta pengujiannya", range(1, 6))),
            'problem_summarized' => true,
        ])->and($rows['Sukasir'])->toMatchArray([
            'problem' => 'Fixing WA Notif',
            'problem_detail' => 'Fixing WA Notif',
            'problem_summarized' => false,
        ]);

        Http::assertSentCount(1);
    });

    it('exposes the summary flags through the API and uses the summary in the PDF', function () {
        $this->travelTo(Carbon::parse('2030-03-25 10:00:00'));
        $this->createTeam(['name' => 'IT Development']);
        $manager = $this->createManager();
        $alice = $this->createEmployee(['name' => 'Alice']);
        $project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'SIAKAD']);
        $attendance = Attendance::create([
            'user_id' => $alice->id,
            'check_in' => '2030-03-18 08:00:00',
            'check_out' => '2030-03-18 16:00:00',
            'date' => '2030-03-18',
            'total_hours' => 8.0,
        ]);
        foreach (range(1, 6) as $n) {
            Task::create(['attendance_id' => $attendance->id, 'project_id' => $project->id, 'title' => "Slicing halaman nomor {$n} beserta pengujiannya", 'is_completed' => true]);
        }
        Http::fake([PS_ENDPOINT => Http::response(psReply([['id' => $project->id, 'summary' => 'Slicing dan pengujian enam halaman.']]))]);
        $headers = ['Authorization' => 'Bearer '.$manager->createToken('auth-token')->plainTextToken];

        $this->getJson('/api/v1/manager/reports/evaluation?start_date=2030-03-18&end_date=2030-03-23', $headers)
            ->assertOk()
            ->assertJsonPath('data.projects.rows.0.problem', 'Slicing dan pengujian enam halaman.')
            ->assertJsonPath('data.projects.rows.0.problem_summarized', true);

        $html = app(EvaluationReportPdf::class)->html(
            app(EvaluationReportService::class)->build($this->team, Carbon::parse('2030-03-18'), Carbon::parse('2030-03-23')->endOfDay())
        );

        expect($html)->toContain('Slicing dan pengujian enam halaman.')
            ->not->toContain('Slicing halaman nomor 1');

        // Preview and PDF share the cached summary: a single request in total.
        Http::assertSentCount(1);
    });
});
