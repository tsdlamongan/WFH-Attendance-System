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
 * logged for a project becomes three to five short highlights of the period (real blockers first),
 * without progress statuses.
 * When Gemini fails and a fallback is configured (an OpenAI compatible chat completions API,
 * services.fallback_ai), that answers instead. Best effort only: without an API key, or when
 * every request fails, nothing is returned and the caller keeps the plain list.
 */
class ProblemSummaryService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /** Bump when the prompt changes, so summaries cached under the old wording are not reused. */
    private const PROMPT_VERSION = 3;

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
            .'"PROBLEM" berupa SOROTAN pekerjaan selama periode laporan: 3-5 poin pendek dalam bahasa Indonesia, masing-masing satu '
            .'kalimat berakhir titik (tiap poin paling banyak 60 karakter, seluruhnya paling banyak '.$targetChars.' karakter). '
            .'Jumlah poin mengikuti banyaknya pekerjaan yang penting pada project itu sendiri: project dengan sedikit pekerjaan '
            .'cukup 2-3 poin, jangan mengisi poin dengan hal sepele. Gunakan HANYA pekerjaan yang tertulis di bagian project yang '
            .'sama; jangan mengambil, menukar, atau menyalin pekerjaan dari project lain. '
            ."Pilih yang paling penting, dengan urutan prioritas:\n"
            ."1. kendala nyata yang menghambat pekerjaan (gangguan layanan, menunggu pihak lain atau akses, blocker, error): sebut apa yang terhambat;\n"
            ."2. hotfix, bug, atau defect yang penting;\n"
            ."3. fitur, modul, atau integrasi utama yang dikerjakan.\n"
            .'Pekerjaan yang sudah selesai maupun yang masih berjalan sama-sama boleh jadi sorotan. JANGAN menulis status pengerjaan '
            .'seperti "belum selesai", "belum rampung", "masih berjalan", "masih diuji", "sedang dikerjakan" atau "dalam pengerjaan"; '
            .'cukup sebut pekerjaannya. Testing, retest, dan fixing kecil jangan dirinci. Sebut hal yang konkret (nama fitur, modul, '
            .'atau layanan), bukan pernyataan umum seperti "pemeliharaan sistem". Jangan menulis tautan atau URL, jangan menyebut '
            .'ClickUp atau ID task, jangan memakai bullet, jangan menyebut nama project, dan jangan menambah informasi yang tidak '
            .'ada di daftar. '
            .'Contoh gaya (bukan data): "Integrasi pembayaran terkendala menunggu akses sandbox. Hotfix perhitungan gaji. '
            ."Modul laporan penjualan. Perbaikan tampilan dashboard.\"\n"
            .'Balas hanya dengan JSON berbentuk {"summaries":[{"id":<id project>,"points":[{"text":"<satu poin>","source":"<judul pekerjaan '
            .'yang mendasarinya>"}]}]}, satu entri per project. "source" harus salinan persis salah satu judul pekerjaan di bagian project '
            .'itu sendiri; poin yang merangkum beberapa pekerjaan cukup menyalin salah satunya.'
            ."\n\n"
            .'Daftar pekerjaan per project (format: id | nama project, lalu bagian-bagiannya). Ketiga bagian sama-sama sumber '
            .'sorotan; nama bagiannya hanya petunjuk dan tidak perlu ditulis. "Belum selesai, dengan alasan tertulis" memuat '
            .'pekerjaan → alasan yang ditulis karyawan; alasan itu sering hanya catatan sesi seperti "lanjut nanti", "re-checkin", '
            .'"dilanjutkan sesi berikutnya" atau "on test", dan itu BUKAN kendala. Anggap kendala hanya jika alasannya menyebut '
            .'hambatan nyata (gangguan layanan, menunggu pihak lain atau akses, "blocker ...", error), dan kaitkan kendala itu '
            .'hanya dengan pekerjaan di sebelah kirinya. "Belum selesai, tanpa alasan" adalah sesi yang ditutup otomatis, bukan '
            .'kendala.'
            ."\n"
            // A blank line between projects keeps the model from carrying work over from one to the next.
            .implode("\n\n", $lines);

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
                                    'points' => [
                                        'type' => 'ARRAY',
                                        'items' => [
                                            'type' => 'OBJECT',
                                            'properties' => [
                                                'text' => ['type' => 'STRING'],
                                                'source' => ['type' => 'STRING'],
                                            ],
                                            'required' => ['text', 'source'],
                                        ],
                                    ],
                                ],
                                'required' => ['id', 'points'],
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
            $reason = $entry['blocker'] === null ? null : preg_replace('~^[\s\-–.]+|[\s\-–.]+$~u', '', Str::squish($entry['blocker']));

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

        $byId = array_column($projects, null, 'id');
        $summaries = [];

        foreach ($decoded['summaries'] as $item) {
            $project = $byId[(int) ($item['id'] ?? 0)] ?? null;
            $summary = $project === null ? '' : $this->ownPoints((array) ($item['points'] ?? []), $this->titles($project));

            if ($summary !== '') {
                $summaries[$project['id']] = $this->cap($summary);
            }
        }

        return $summaries;
    }

    /**
     * The points as one text, keeping only those that cite a task of the project itself. The model sees
     * every project at once and now and then borrows a task from the project next to it; a point that
     * cannot name a source of its own is dropped.
     *
     * @param  list<string>  $titles  normalized titles of the project's tasks
     */
    private function ownPoints(array $points, array $titles): string
    {
        $kept = [];

        foreach ($points as $point) {
            $text = is_array($point) ? Str::squish((string) ($point['text'] ?? '')) : '';

            if ($text !== '' && $this->isOwnTask((string) ($point['source'] ?? ''), $titles)) {
                $kept[] = preg_match('/[.!?]$/u', $text) === 1 ? $text : $text.'.';
            }
        }

        return implode(' ', $kept);
    }

    /**
     * @param  list<string>  $titles
     */
    private function isOwnTask(string $source, array $titles): bool
    {
        $source = $this->normalize($source);

        foreach ($titles as $title) {
            // A copy that is cut short, or carries a little extra, still names the task.
            if ($source !== '' && ($title === $source || (min(mb_strlen($title), mb_strlen($source)) >= 8
                && (str_contains($title, $source) || str_contains($source, $title))))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function titles(array $project): array
    {
        $titles = isset($project['entries']) ? array_column($project['entries'], 'title') : explode('; ', $project['problem']);

        return array_map($this->normalize(...), $titles);
    }

    /**
     * Case, spacing and punctuation differ between a title and the model's copy of it.
     */
    private function normalize(string $text): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($text));
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
