<?php

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
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

const PS_FALLBACK_ENDPOINT = 'https://fallback.test/v1/chat/completions';

/**
 * The {id, points} answer for items of {id, summary, source?}: the summary is one point that cites a task
 * of its project as its source (by default the first task of psLongList()).
 */
function psAnswer(array $summaries): array
{
    return array_map(fn (array $item) => [
        'id' => $item['id'] ?? null,
        'points' => [['text' => $item['summary'] ?? '', 'source' => $item['source'] ?? 'Pekerjaan nomor 1 dengan deskripsi panjang']],
    ], $summaries);
}

/**
 * A Gemini success response carrying the given items (see psAnswer) as JSON text.
 */
function psReply(array $summaries): array
{
    return [
        'candidates' => [[
            'content' => ['role' => 'model', 'parts' => [['text' => json_encode(['summaries' => psAnswer($summaries)])]]],
            'finishReason' => 'STOP',
        ]],
        'usageMetadata' => ['promptTokenCount' => 200, 'candidatesTokenCount' => 60, 'totalTokenCount' => 260],
    ];
}

/**
 * A chat completions response carrying the given text as the assistant message.
 */
function psFallbackReply(string $content): array
{
    return ['id' => 'chatcmpl-1', 'object' => 'chat.completion', 'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $content]]]];
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
            ['id' => 1, 'summary' => "  Fokus pada  modul penggajian.\nMenunggu data HRD.  ", 'source' => 'Gaji nomor 1 dengan deskripsi panjang'],
            ['id' => 3, 'summary' => 'Integrasi dan pengujian modul KRS.', 'source' => 'KRS nomor 2 dengan deskripsi panjang'],
        ]))]);

        $result = $this->service->summarize([
            ['id' => 1, 'name' => 'Ustadz Link', 'problem' => psLongList('Gaji')],
            ['id' => 2, 'name' => 'Pendek', 'problem' => 'Fixing WA Notif'],
            ['id' => 3, 'name' => 'SIAKAD', 'problem' => psLongList('KRS')],
            ['id' => 4, 'name' => 'Kosong', 'problem' => EvaluationReportService::NO_WORK_LABEL],
        ]);

        expect($result)->toBe([
            1 => 'Fokus pada modul penggajian. Menunggu data HRD.',
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

    it('asks for the highlights of the period, without links or progress statuses, and switches thinking off', function () {
        Http::fake([PS_ENDPOINT => Http::response(psReply([['id' => 1, 'summary' => 'Ringkasan.']]))]);

        $this->service->summarize([['id' => 1, 'name' => 'A', 'problem' => psLongList()]]);

        Http::assertSent(function (Request $request) {
            $prompt = $request['contents'][0]['parts'][0]['text'];

            // The 3-5 points budget is the 300 character cap minus the headroom the prompt leaves.
            return $request['generationConfig']['thinkingConfig'] === ['thinkingBudget' => 0]
                && str_contains($prompt, 'SOROTAN pekerjaan')
                && str_contains($prompt, '3-5 poin pendek')
                && str_contains($prompt, 'seluruhnya paling banyak 250 karakter')
                && str_contains($prompt, 'sama-sama boleh jadi sorotan')
                && str_contains($prompt, 'JANGAN menulis status pengerjaan')
                && str_contains($prompt, 'jangan mengambil, menukar, atau menyalin pekerjaan dari project lain')
                && str_contains($prompt, '"belum rampung", "masih berjalan", "masih diuji"')
                && str_contains($prompt, 'Jangan menulis tautan atau URL')
                && str_contains($prompt, '"Belum selesai, tanpa alasan" adalah sesi yang ditutup otomatis, bukan kendala');
        });
    });

    it('keeps the projects apart with a blank line', function () {
        Http::fake([PS_ENDPOINT => Http::response(psReply([['id' => 1, 'summary' => 'Satu.']]))]);

        $this->service->summarize([
            ['id' => 1, 'name' => 'A', 'problem' => psLongList('A')],
            ['id' => 2, 'name' => 'B', 'problem' => psLongList('B')],
        ]);

        Http::assertSent(fn (Request $request) => preg_match('/nomor 6 dengan deskripsi panjang\n\n2 \| B \| B nomor 1/', $request['contents'][0]['parts'][0]['text']) === 1);
    });

    it('lists open work with a written reason apart from work closed without one', function () {
        Http::fake([PS_ENDPOINT => Http::response(psReply([['id' => 1, 'summary' => 'Ringkasan.']]))]);

        $entries = [
            ['title' => 'Slicing dashboard', 'blocker' => null],
            ['title' => 'Retest Anti-Fraud (Blacklist Nomor)', 'blocker' => Task::AUTO_CHECKOUT_BLOCKER],
            ['title' => 'Retest Esamsat', 'blocker' => "blocker \n gangguan layanan"],
            ['title' => 'Cek server', 'blocker' => 'lanjut nanti'],
            ['title' => 'Fixing tabel', 'blocker' => ' - '],
        ];
        $this->service->summarize([['id' => 1, 'name' => 'Sundra', 'problem' => psLongList(), 'entries' => $entries]]);

        Http::assertSent(fn (Request $request) => str_contains($request['contents'][0]['parts'][0]['text'], implode("\n", [
            '1 | Sundra',
            '  Belum selesai, dengan alasan tertulis: Retest Esamsat → blocker gangguan layanan; Cek server → lanjut nanti',
            '  Belum selesai, tanpa alasan: Retest Anti-Fraud (Blacklist Nomor); Fixing tabel',
            '  Selesai: Slicing dashboard',
        ])));
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
        expect(Cache::get('evaluation-report:problem-summary:'.sha1('3|gemini-2.5-flash|'.psLongList())))->toBe('Ringkasan.');
    });

    it('requests only the entries that are not cached yet', function () {
        Http::fake([PS_ENDPOINT => Http::sequence()
            ->push(psReply([['id' => 1, 'summary' => 'Satu.', 'source' => 'A nomor 1 dengan deskripsi panjang']]))
            ->push(psReply([['id' => 2, 'summary' => 'Dua.', 'source' => 'B nomor 1 dengan deskripsi panjang']]))]);

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
            ['id' => 2, 'summary' => str_repeat('Kalimat panjang sekali ', 5), 'source' => 'B nomor 1 dengan deskripsi panjang'],
            ['summary' => 'Tanpa id'],
        ]))]);

        $result = $this->service->summarize([
            ['id' => 1, 'name' => 'A', 'problem' => psLongList('A')],
            ['id' => 2, 'name' => 'B', 'problem' => psLongList('B')],
        ]);

        // Cut at a word boundary, so at most max_chars of text plus the ellipsis.
        expect($result)->toHaveKeys([2])->not->toHaveKey(1)->not->toHaveKey(99)
            ->and(mb_strlen($result[2]))->toBeLessThanOrEqual(41)
            ->and($result[2])->toBe('Kalimat panjang sekali Kalimat panjang…');
    });
});

describe('own tasks only', function () {
    /**
     * Summarises one project (id 1) whose tasks are the given titles and the model answers with the given points.
     */
    function psOwn(array $titles, array $points, ?array $entries = null): array
    {
        $project = ['id' => 1, 'name' => 'MyStore', 'problem' => implode('; ', $titles).'; '.psLongList()];

        if ($entries !== null) {
            $project['entries'] = $entries;
        }

        Http::fake([PS_ENDPOINT => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['summaries' => [['id' => 1, 'points' => $points]]])]]]]]])]);

        return test()->service->summarize([$project]);
    }

    it('drops a point that cites a task of another project and keeps the rest', function () {
        $result = psOwn(['Fixing ansible auto deploy', 'Mapping database'], [
            ['text' => 'Perbaikan ansible auto deploy.', 'source' => 'Fixing ansible auto deploy'],
            ['text' => 'Pemasangan pencatatan log aktivitas.', 'source' => 'Pemasangan Pencatatan Log pada Aksi & Modul Aplikasi'],
            ['text' => 'Pemetaan database.', 'source' => 'Mapping database'],
        ]);

        expect($result)->toBe([1 => 'Perbaikan ansible auto deploy. Pemetaan database.']);
    });

    it('accepts a source that differs in case, spacing and punctuation, or is cut short or carries extra text', function (string $source) {
        expect(psOwn(['Retest backlog Esamsat jatim fastpay (blocker gangguan layanan)'], [['text' => 'Esamsat terkendala.', 'source' => $source]]))
            ->toBe([1 => 'Esamsat terkendala.']);
    })->with([
        'other case and spacing' => ['RETEST  backlog esamsat jatim fastpay'],
        'cut short' => ['Retest backlog Esamsat'],
        'with extra text' => ['Task: Retest backlog Esamsat jatim fastpay (blocker gangguan layanan) - selesai'],
        'without the reason' => ['retest backlog, esamsat jatim fastpay'],
    ]);

    it('drops points without a usable source, and a project left without points has no summary', function (mixed $point) {
        expect(psOwn(['Fixing ansible auto deploy'], [$point]))->toBe([]);
    })->with([
        'no source' => [['text' => 'Perbaikan ansible.']],
        'blank source' => [['text' => 'Perbaikan ansible.', 'source' => '  ']],
        'too short a source to prove anything' => [['text' => 'Perbaikan ansible.', 'source' => 'ansible']],
        'unrelated source' => [['text' => 'Perbaikan ansible.', 'source' => 'Slicing halaman login pengguna']],
        'point that is not an object' => ['Perbaikan ansible.'],
        'empty text' => [['text' => ' ', 'source' => 'Fixing ansible auto deploy']],
    ]);

    it('checks the sources against the entries when the work comes as entries', function () {
        $entries = [['title' => 'Retest Esamsat', 'blocker' => 'blocker gangguan layanan'], ['title' => 'Slicing dashboard', 'blocker' => null]];

        // The first task of psLongList() is in the plain list but not among the entries: it does not count.
        $result = psOwn([], [
            ['text' => 'Esamsat terkendala gangguan layanan.', 'source' => 'Retest Esamsat'],
            ['text' => 'Pekerjaan dari daftar polos.', 'source' => 'Pekerjaan nomor 1 dengan deskripsi panjang'],
        ], $entries);

        expect($result)->toBe([1 => 'Esamsat terkendala gangguan layanan.']);
    });

    it('ends each point with a full stop and joins them', function () {
        expect(psOwn(['Fixing ansible auto deploy'], [
            ['text' => 'Perbaikan ansible', 'source' => 'Fixing ansible auto deploy'],
            ['text' => 'Apakah deploy aman?', 'source' => 'Fixing ansible auto deploy'],
        ]))->toBe([1 => 'Perbaikan ansible. Apakah deploy aman?']);
    });
});

describe('fallback AI', function () {
    beforeEach(function () {
        config(['services.fallback_ai' => ['url' => 'https://fallback.test/v1/', 'key' => 'fb-key', 'model' => 'all-sonnet']]);
        $this->projects = [['id' => 1, 'name' => 'A', 'problem' => psLongList()]];
    });

    it('answers when Gemini fails, after a single Gemini attempt', function () {
        Log::spy();
        Http::fake([
            PS_ENDPOINT => Http::response(['error' => ['code' => 503, 'message' => 'high demand']], 503),
            PS_FALLBACK_ENDPOINT => Http::response(psFallbackReply(json_encode(['summaries' => psAnswer([['id' => 1, 'summary' => 'Dari fallback.']])]))),
        ]);

        expect($this->service->summarize($this->projects))->toBe([1 => 'Dari fallback.']);

        // No retries of Gemini while a fallback is waiting.
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === PS_FALLBACK_ENDPOINT
            && $request->hasHeader('Authorization', 'Bearer fb-key')
            && $request['model'] === 'all-sonnet'
            && $request['response_format'] === ['type' => 'json_object']
            && ! isset($request['temperature'])
            && str_contains($request['messages'][0]['content'], '1 | A | Pekerjaan nomor 1')
            && str_contains($request['messages'][0]['content'], '{"summaries":[{"id":<id project>'));
        Log::shouldHaveReceived('warning')->once()->with('Problem summary skipped: Gemini answered HTTP 503');
        // Cached like any other summary, so the PDF download does not ask again.
        expect(Cache::get('evaluation-report:problem-summary:'.sha1('3|gemini-2.5-flash|'.psLongList())))->toBe('Dari fallback.');
    });

    it('is not asked while Gemini answers', function () {
        Http::fake([PS_ENDPOINT => Http::response(psReply([['id' => 1, 'summary' => 'Dari Gemini.']]))]);

        expect($this->service->summarize($this->projects))->toBe([1 => 'Dari Gemini.']);

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => $request->url() === PS_FALLBACK_ENDPOINT);
    });

    it('is the only provider without a Gemini key', function () {
        config(['services.gemini.key' => null]);
        Http::fake([PS_FALLBACK_ENDPOINT => Http::response(psFallbackReply(json_encode(['summaries' => psAnswer([['id' => 1, 'summary' => 'Hanya fallback.']])])))]);

        expect($this->service->isEnabled())->toBeTrue()
            ->and($this->service->summarize($this->projects))->toBe([1 => 'Hanya fallback.']);

        Http::assertSentCount(1);
    });

    it('accepts the JSON in a code fence', function () {
        config(['services.gemini.key' => null]);
        Http::fake([PS_FALLBACK_ENDPOINT => Http::response(psFallbackReply("```json\n".json_encode(['summaries' => psAnswer([['id' => 1, 'summary' => 'Berpagar.']])])."\n```"))]);

        expect($this->service->summarize($this->projects))->toBe([1 => 'Berpagar.']);
    });

    it('keeps the plain list when both providers fail', function (string $case) {
        Log::spy();
        Http::fake([
            PS_ENDPOINT => Http::response(['error' => ['code' => 429]], 429),
            PS_FALLBACK_ENDPOINT => match ($case) {
                'http error' => Http::response(['error' => ['message' => 'bad key']], 401),
                'not json' => Http::response(psFallbackReply('maaf, tidak bisa')),
                'no content' => Http::response(['choices' => []]),
            },
        ]);

        expect($this->service->summarize($this->projects))->toBe([]);

        // Each provider logs its own failure.
        Log::shouldHaveReceived('warning')->with('Problem summary skipped: Gemini answered HTTP 429')->once();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_starts_with($message, 'Problem summary skipped: Fallback AI answered'))->once();
    })->with(['http error', 'not json', 'no content']);

    it('is off without a URL, key or model', function (string $missing) {
        config(['services.gemini.key' => null, "services.fallback_ai.{$missing}" => null]);
        Http::fake();

        expect($this->service->isEnabled())->toBeFalse()
            ->and($this->service->summarize($this->projects))->toBe([]);

        Http::assertNothingSent();
    })->with(['url', 'key', 'model']);
});

describe('length cap', function () {
    it('drops whole trailing sentences instead of cutting one in half', function (string $summary, string $expected) {
        config(['evaluation_report.problem_summary.max_chars' => 40]);
        Http::fake([PS_ENDPOINT => Http::response(psReply([['id' => 1, 'summary' => $summary]]))]);

        expect($this->service->summarize([['id' => 1, 'name' => 'A', 'problem' => psLongList()]]))->toBe([1 => $expected]);
    })->with([
        'cuts after the last sentence that fits' => ['Fitur A belum rampung. Fitur B masih berjalan. Fitur C diuji.', 'Fitur A belum rampung.'],
        'keeps a sentence that ends exactly at the cap' => ['Fitur AB belum rampung dan masih di-uji. Fitur C diuji.', 'Fitur AB belum rampung dan masih di-uji.'],
        'falls back to a word boundary when no sentence fits' => ['Fitur A masih dalam pengerjaan oleh seluruh tim saat ini juga.', 'Fitur A masih dalam pengerjaan oleh…'],
        'leaves a short summary alone' => ['Fitur A belum rampung.', 'Fitur A belum rampung.'],
    ]);
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
        expect(Cache::get('evaluation-report:problem-summary:'.sha1('3|gemini-2.5-flash|'.psLongList())))->toBeNull();
        // Server errors are retried, bad answers are not (a faked connection error leaves no record to count).
        if ($case !== 'connection error') {
            Http::assertSentCount($case === 'http error' ? 3 : 1);
        }
    })->with(['http error', 'not json', 'no candidates', 'wrong shape', 'connection error']);

    it('retries a momentary server error and does not retry a rejected request', function () {
        Log::spy();
        Http::fake([PS_ENDPOINT => Http::sequence()
            ->push(['error' => ['code' => 503, 'message' => 'high demand']], 503)
            ->push(psReply([['id' => 1, 'summary' => 'Akhirnya.', 'source' => 'A nomor 1 dengan deskripsi panjang']]))
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

        Http::fake([PS_ENDPOINT => Http::response(psReply([['id' => $busy->id, 'summary' => 'Slicing dan pengujian enam halaman.', 'source' => 'Slicing halaman nomor 1 beserta pengujiannya']]))]);

        $rows = collect(app(EvaluationReportService::class)->build(collect([$team]), Carbon::parse('2030-03-18'), Carbon::parse('2030-03-23')->endOfDay())['projects']['rows'])->keyBy('name');

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
        $superAdmin = User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'team_id' => null]);
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
        Http::fake([PS_ENDPOINT => Http::response(psReply([['id' => $project->id, 'summary' => 'Slicing dan pengujian enam halaman.', 'source' => 'Slicing halaman nomor 1 beserta pengujiannya']]))]);
        $headers = ['Authorization' => 'Bearer '.$superAdmin->createToken('auth-token')->plainTextToken];

        $this->getJson('/api/v1/super-admin/reports/evaluation?team_ids[]='.$this->team->id.'&start_date=2030-03-18&end_date=2030-03-23', $headers)
            ->assertOk()
            ->assertJsonPath('data.projects.rows.0.problem', 'Slicing dan pengujian enam halaman.')
            ->assertJsonPath('data.projects.rows.0.problem_summarized', true);

        $html = app(EvaluationReportPdf::class)->html(
            app(EvaluationReportService::class)->build(collect([$this->team]), Carbon::parse('2030-03-18'), Carbon::parse('2030-03-23')->endOfDay())
        );

        expect($html)->toContain('Slicing dan pengujian enam halaman.')
            ->not->toContain('Slicing halaman nomor 1');

        // Preview and PDF share the cached summary: a single request in total.
        Http::assertSentCount(1);
    });
});
