<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Shortens the PROBLEM column of the evaluation report with Gemini: the list of work employees
 * logged for a project becomes one or two sentences. Best effort only: without an API key, or
 * when the request fails, nothing is returned and the caller keeps the plain list.
 */
class ProblemSummaryService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /** Characters below max_chars that the prompt asks for, leaving room for the model to overshoot. */
    private const PROMPT_HEADROOM = 50;

    private const ATTEMPTS = 3;

    private const RETRY_DELAY_MS = 2000;

    /**
     * Summaries keyed by project id, for the projects whose text was long enough and could be
     * summarised. One request covers every project that is not cached yet.
     *
     * @param  array<int, array{id: int, name: string, problem: string}>  $projects
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
        return (bool) $this->option('enabled') && filled(config('services.gemini.key'));
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
     * @param  array<int, array{id: int, name: string, problem: string}>  $projects
     * @return array<int, string>
     */
    private function request(array $projects): array
    {
        try {
            // Gemini answers 503 "high demand" now and then; a short retry rides that out.
            $response = Http::withHeaders(['x-goog-api-key' => config('services.gemini.key')])
                ->timeout($this->option('timeout'))
                ->retry(self::ATTEMPTS, self::RETRY_DELAY_MS, fn (\Throwable $exception) => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()), throw: false)
                ->post(sprintf(self::ENDPOINT, config('services.gemini.model')), $this->payload($projects));

            if ($response->failed()) {
                throw new \RuntimeException("Gemini answered HTTP {$response->status()}");
            }

            return $this->parse($response->json('candidates.0.content.parts.0.text'), $projects);
        } catch (\Throwable $e) {
            // The report must never fail because of the summary: the plain list is printed instead.
            Log::warning('Problem summary skipped: '.$e->getMessage());

            return [];
        }
    }

    private function payload(array $projects): array
    {
        $lines = array_map(
            fn (array $project) => $project['id'].' | '.Str::squish($project['name']).' | '.Str::squish($project['problem']),
            $projects
        );

        // Models overshoot the requested length, so ask for less than the hard cap applied in parse().
        $targetChars = max(80, $this->option('max_chars') - self::PROMPT_HEADROOM);

        $prompt = 'Anda membantu menyusun laporan evaluasi divisi IT. Untuk setiap project, ringkas daftar pekerjaan '
            .'yang dicatat karyawan selama periode laporan menjadi 1-2 kalimat pendek dalam bahasa Indonesia untuk kolom "PROBLEM", '
            ."paling banyak {$targetChars} karakter. Sebutkan pekerjaan utama yang dikerjakan dan, jika ada, kendala yang masih "
            .'terbuka dalam tanda kurung di akhir. Jangan menambah informasi yang tidak ada, jangan memakai bullet, '
            ."dan jangan menyebut nama project di dalam ringkasan.\n\n"
            .'Daftar pekerjaan per project (format: id | nama project | pekerjaan dipisah titik koma). Pekerjaan yang diikuti '
            ."tanda kurung belum selesai dan isi kurungnya adalah kendalanya; pekerjaan tanpa tanda kurung sudah selesai.\n"
            .implode("\n", $lines);

        return [
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature' => 0.2,
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
     * @return array<int, string>
     */
    private function parse(mixed $text, array $projects): array
    {
        $decoded = is_string($text) ? json_decode($text, true) : null;

        if (! is_array($decoded['summaries'] ?? null)) {
            throw new \RuntimeException('Gemini answered without summaries');
        }

        $known = array_column($projects, 'id');
        $summaries = [];

        foreach ($decoded['summaries'] as $item) {
            $id = (int) ($item['id'] ?? 0);
            $summary = Str::squish((string) ($item['summary'] ?? ''));

            if ($summary === '' || ! in_array($id, $known, true)) {
                continue;
            }

            // The prompt asks for max_chars; the cap protects the table layout if the model ignores it.
            $summaries[$id] = Str::limit($summary, $this->option('max_chars'), '…');
        }

        return $summaries;
    }

    private function cacheKey(string $problem): string
    {
        return 'evaluation-report:problem-summary:'.sha1(config('services.gemini.model').'|'.$problem);
    }

    private function option(string $key): mixed
    {
        return config("evaluation_report.problem_summary.{$key}");
    }
}
