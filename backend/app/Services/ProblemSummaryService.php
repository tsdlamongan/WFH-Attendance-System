<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Shortens the PROBLEM column of the evaluation report with Gemini: the list of work employees
 * logged for a project becomes two or three short sentences about what needs attention most.
 * When Gemini fails and a fallback is configured (an OpenAI compatible chat completions API,
 * services.fallback_ai), that answers instead. Best effort only: without an API key, or when
 * every request fails, nothing is returned and the caller keeps the plain list.
 */
class ProblemSummaryService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /** Bump when the prompt changes, so summaries cached under the old wording are not reused. */
    private const PROMPT_VERSION = 2;

    /** Characters below max_chars that the prompt asks for, leaving room for the model to overshoot. */
    private const PROMPT_HEADROOM = 50;

    private const ATTEMPTS = 3;

    private const RETRY_DELAY_MS = 2000;

    /**
     * Summaries keyed by project id, for the projects whose text was long enough and could be
     * summarised. One request covers every project that is not cached yet.
     *
     * `entries` (optional) is the same work as `problem`, but split up so that blockers reach the
     * model unambiguously instead of as trailing parentheses.
     *
     * @param  array<int, array{id: int, name: string, problem: string, entries?: list<array{title: string, blocker: ?string}>}>  $projects
     * @return array<int, string>
     */
    public function summarize(array $projects): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $summaries = [];
        $pending = [];

        foreach ($projects as $project) {
            if (! $this->needsSummary($project['problem'])) {
                continue;
            }

            $cached = Cache::get($this->cacheKey($project['problem']));

            if (is_string($cached)) {
                $summaries[$project['id']] = $cached;
            } else {
                $pending[$project['id']] = $project;
            }
        }

        if ($pending === []) {
            return $summaries;
        }

        foreach ($this->request(array_values($pending)) as $id => $summary) {
            $summaries[$id] = $summary;
            Cache::put($this->cacheKey($pending[$id]['problem']), $summary, now()->addDays($this->option('cache_days')));
        }

        return $summaries;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->option('enabled') && (filled(config('services.gemini.key')) || $this->hasFallback());
    }

    private function hasFallback(): bool
    {
        return filled(config('services.fallback_ai.url'))
            && filled(config('services.fallback_ai.key'))
            && filled(config('services.fallback_ai.model'));
    }

    /**
     * Short lists already fit the column; "Tidak ada" has nothing to summarise.
     */
    private function needsSummary(string $problem): bool
    {
        return $problem !== EvaluationReportService::NO_WORK_LABEL
            && mb_strlen($problem) >= $this->option('min_length');
    }

    /**
     * @param  array<int, array{id: int, name: string, problem: string, entries?: list<array{title: string, blocker: ?string}>}>  $projects
     * @return array<int, string>
     */
    private function request(array $projects): array
    {
        $prompt = $this->prompt($projects);
        $asks = [];

        if (filled(config('services.gemini.key'))) {
            // Without a fallback Gemini gets a few tries; with one, a failure moves on at once instead of waiting.
            $asks[] = fn () => $this->askGemini($prompt, $projects, $this->hasFallback() ? 1 : self::ATTEMPTS);
        }

        if ($this->hasFallback()) {
            $asks[] = fn () => $this->askFallback($prompt, $projects);
        }

        foreach ($asks as $ask) {
            try {
                return $ask();
            } catch (\Throwable $e) {
                // The report must never fail because of the summary: the next provider, else the plain list.
                Log::warning('Problem summary skipped: '.$e->getMessage());
            }
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function askGemini(string $prompt, array $projects, int $attempts): array
    {
        // Gemini answers 503 "high demand" now and then; a short retry rides that out.
        $response = Http::withHeaders(['x-goog-api-key' => config('services.gemini.key')])
            ->timeout($this->option('timeout'))
            ->retry($attempts, self::RETRY_DELAY_MS, fn (\Throwable $exception) => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()), throw: false)
            ->post(sprintf(self::ENDPOINT, config('services.gemini.model')), $this->geminiPayload($prompt));

        if ($response->failed()) {
            throw new \RuntimeException("Gemini answered HTTP {$response->status()}");
        }

        return $this->parse($response->json('candidates.0.content.parts.0.text'), $projects, 'Gemini');
    }

    /**
     * @return array<int, string>
     */
    private function askFallback(string $prompt, array $projects): array
    {
        // No temperature: the newest models of some providers reject it. The JSON shape is in the prompt.
        $response = Http::withToken(config('services.fallback_ai.key'))
            ->timeout($this->option('fallback_timeout'))
            ->post(rtrim(config('services.fallback_ai.url'), '/').'/chat/completions', [
                'model' => config('services.fallback_ai.model'),
                'response_format' => ['type' => 'json_object'],
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("Fallback AI answered HTTP {$response->status()}");
        }

        // Some models wrap the JSON in a code fence even when asked for a JSON object.
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim((string) $response->json('choices.0.message.content')));

        return $this->parse($text, $projects, 'Fallback AI');
    }

    private function prompt(array $projects): string
    {
        $lines = array_map(
            fn (array $project) => $this->workLine($project),
            $projects
        );

        // Models overshoot the requested length, so ask for less than the hard cap applied in parse().
        $targetChars = max(80, $this->option('max_chars') - self::PROMPT_HEADROOM);

        $prompt = 'Anda membantu menyusun laporan evaluasi divisi IT untuk pimpinan. Untuk setiap project, tulis isi kolom '
            .'"PROBLEM": 2-3 kalimat pendek dalam bahasa Indonesia (tiap kalimat paling banyak 60 karakter, seluruhnya paling banyak '
            .$targetChars.' karakter) yang hanya '
            ."memuat hal PALING MENDESAK atau perlu perhatian khusus selama periode laporan. Urutan prioritasnya:\n"
            ."1. kendala nyata yang menghambat pekerjaan (gangguan layanan, menunggu pihak lain atau akses, blocker, error);\n"
            ."2. hotfix, bug, atau defect yang penting;\n"
            ."3. pekerjaan besar yang belum rampung.\n"
            .'Sebut hal yang konkret (nama fitur, modul, atau layanan), bukan kalimat umum seperti "banyak pekerjaan belum selesai". '
            .'Pekerjaan rutin yang sudah selesai (testing, retest, fixing kecil) jangan dirinci. Jika tidak ada hal yang mendesak, '
            .'cukup satu kalimat yang menyebut 2-3 pekerjaan utama yang konkret, bukan pernyataan umum seperti "pemeliharaan sistem". '
            .'Jangan menulis tautan atau URL, jangan menyebut ClickUp atau ID task, jangan memakai bullet, jangan menyebut nama project, dan jangan menambah informasi yang tidak ada di daftar. '
            .'Contoh gaya (bukan data): "Integrasi pembayaran terkendala menunggu akses sandbox. Hotfix perhitungan gaji masih diuji. '
            ."Modul laporan belum rampung.\"\n"
            .'Balas hanya dengan JSON berbentuk {"summaries":[{"id":<id project>,"summary":"<isi kolom PROBLEM>"}]}, satu entri per project.'
            ."\n\n"
            .'Daftar pekerjaan per project (format: id | nama project, lalu bagian-bagiannya). "Belum selesai, dengan alasan tertulis" '
            .'memuat pekerjaan → alasan yang ditulis karyawan; alasan itu sering hanya catatan sesi seperti "lanjut nanti", '
            .'"re-checkin", "dilanjutkan sesi berikutnya" atau "on test", dan itu BUKAN kendala. Anggap kendala hanya jika alasannya '
            .'menyebut hambatan nyata (gangguan layanan, menunggu pihak lain atau akses, "blocker ...", error), dan kaitkan kendala '
            .'itu hanya dengan pekerjaan di sebelah kirinya. "Belum selesai, tanpa alasan" adalah sesi yang ditutup otomatis: sebut '
            .'pekerjaannya "belum selesai" atau "masih berjalan", jangan "terkendala" atau "ada kendala". "Selesai" cukup disebut '
            .'bila tidak ada hal lain yang mendesak.'
            ."\n"
            .implode("\n", $lines);

        return $prompt;
    }

    private function geminiPayload(string $prompt): array
    {
        return [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature' => 0.2,
                // Thinking made one request for every project take 10+ seconds and time out on production;
                // a summary does not need it. 0 is accepted by the Flash models (not by Pro).
                'thinkingConfig' => ['thinkingBudget' => 0],
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'summaries' => [
                            'type' => 'ARRAY',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'id' => ['type' => 'INTEGER'],
                                    'summary' => ['type' => 'STRING'],
                                ],
                                'required' => ['id', 'summary'],
                            ],
                        ],
                    ],
                    'required' => ['summaries'],
                ],
            ],
        ];
    }

    /**
     * The work of one project as the prompt lists it. With entries it is split in sections, so the few
     * open tasks that carry a written reason stand out from the many closed without one. Reasons are
     * often just a session note ("lanjut nanti", "-"): blank ones count as no reason, the model judges
     * the rest.
     */
    private function workLine(array $project): string
    {
        $header = $project['id'].' | '.Str::squish($project['name']);

        if (! isset($project['entries'])) {
            return $header.' | '.Str::squish($project['problem']);
        }

        $sections = ['Belum selesai, dengan alasan tertulis' => [], 'Belum selesai, tanpa alasan' => [], 'Selesai' => []];

        foreach ($project['entries'] as $entry) {
            $reason = $entry['blocker'] === null ? null : trim(Str::squish($entry['blocker']), ' -–.');

            match (true) {
                $reason === null => $sections['Selesai'][] = $entry['title'],
                $reason === '' || $reason === Task::AUTO_CHECKOUT_BLOCKER => $sections['Belum selesai, tanpa alasan'][] = $entry['title'],
                default => $sections['Belum selesai, dengan alasan tertulis'][] = "{$entry['title']} → {$reason}",
            };
        }

        $lines = [];

        foreach (array_filter($sections) as $label => $items) {
            $lines[] = "  {$label}: ".implode('; ', $items);
        }

        return implode("\n", [$header, ...$lines]);
    }

    /**
     * @return array<int, string>
     */
    private function parse(mixed $text, array $projects, string $provider): array
    {
        $decoded = is_string($text) ? json_decode($text, true) : null;

        if (! is_array($decoded['summaries'] ?? null)) {
            throw new \RuntimeException("{$provider} answered without summaries");
        }

        $known = array_column($projects, 'id');
        $summaries = [];

        foreach ($decoded['summaries'] as $item) {
            $id = (int) ($item['id'] ?? 0);
            $summary = Str::squish((string) ($item['summary'] ?? ''));

            if ($summary === '' || ! in_array($id, $known, true)) {
                continue;
            }

            $summaries[$id] = $this->cap($summary);
        }

        return $summaries;
    }

    /**
     * The prompt asks for max_chars; the cap protects the table layout when the model overshoots, as it
     * does by a sentence now and then. Whole trailing sentences go first, a half sentence is the last resort.
     */
    private function cap(string $summary): string
    {
        $max = $this->option('max_chars');

        if (mb_strlen($summary) <= $max) {
            return $summary;
        }

        // One character more than the cap, so a sentence that ends exactly at the cap is kept.
        if (preg_match('/^(.*[.!?])\s/su', mb_substr($summary, 0, $max + 1), $sentences) === 1) {
            return $sentences[1];
        }

        return Str::limit($summary, $max, '…', preserveWords: true);
    }

    private function cacheKey(string $problem): string
    {
        return 'evaluation-report:problem-summary:'.sha1(self::PROMPT_VERSION.'|'.config('services.gemini.model').'|'.$problem);
    }

    private function option(string $key): mixed
    {
        return config("evaluation_report.problem_summary.{$key}");
    }
}
