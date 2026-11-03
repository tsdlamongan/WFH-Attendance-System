<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\EvaluationReportPdf;
use App\Services\EvaluationReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Evaluation report of one or more teams merged into a single report (super admin only).
 */
class EvaluationReportController extends Controller
{
    /** Longest period one report may cover, in days. */
    public const MAX_PERIOD_DAYS = 366;

    /** Longest custom title, in characters: what still fits on one line of the PDF cover. */
    public const MAX_TITLE_LENGTH = 70;

    public function __construct(
        private EvaluationReportService $reportService,
        private EvaluationReportPdf $reportPdf
    ) {}

    /**
     * Report data as JSON, the on-screen preview of the PDF.
     */
    public function show(Request $request): JsonResponse
    {
        // Validated outside the try block so a ValidationException renders as a 422, not a 500.
        [$teams, $startDate, $endDate, $title] = $this->input($request);

        try {
            return response()->json([
                'success' => true,
                'data' => $this->reportService->build($teams, $startDate, $endDate, $title),
            ], 200);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    /**
     * The report as a PDF download.
     */
    public function download(Request $request): Response|JsonResponse
    {
        [$teams, $startDate, $endDate, $title] = $this->input($request);

        try {
            $report = $this->reportService->build($teams, $startDate, $endDate, $title);

            $filename = sprintf(
                'laporan-evaluasi-%s-%s-sd-%s.pdf',
                $teams->count() === 1 ? $teams->first()->slug : "gabungan-{$teams->count()}-tim",
                $startDate->format('Y-m-d'),
                $endDate->format('Y-m-d')
            );

            return response($this->reportPdf->render($report), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    /**
     * Validated request: the teams (by name), the period as [start of first day, end of last day]
     * and the custom report title (null when the default one should be used).
     *
     * @return array{0: Collection<int, Team>, 1: Carbon, 2: Carbon, 3: ?string}
     */
    private function input(Request $request): array
    {
        $validated = $request->validate([
            'team_ids' => 'required|array|min:1',
            'team_ids.*' => 'integer|distinct|exists:teams,id',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'title' => 'nullable|string|max:'.self::MAX_TITLE_LENGTH,
        ], [
            'team_ids.required' => 'Pilih minimal satu tim.',
            'team_ids.array' => 'Daftar tim tidak valid.',
            'team_ids.min' => 'Pilih minimal satu tim.',
            'team_ids.*.integer' => 'Tim yang dipilih tidak valid.',
            'team_ids.*.distinct' => 'Tim yang dipilih tidak boleh dobel.',
            'team_ids.*.exists' => 'Tim yang dipilih tidak ditemukan.',
            'start_date.required' => 'Tanggal mulai wajib diisi.',
            'start_date.date_format' => 'Tanggal mulai tidak valid.',
            'end_date.required' => 'Tanggal selesai wajib diisi.',
            'end_date.date_format' => 'Tanggal selesai tidak valid.',
            'end_date.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            'title.string' => 'Judul laporan tidak valid.',
            'title.max' => 'Judul laporan maksimal '.self::MAX_TITLE_LENGTH.' karakter.',
        ]);

        $startDate = Carbon::createFromFormat('Y-m-d', $validated['start_date'])->startOfDay();
        $endDate = Carbon::createFromFormat('Y-m-d', $validated['end_date'])->endOfDay();

        if ($startDate->diffInDays($endDate) > self::MAX_PERIOD_DAYS) {
            throw ValidationException::withMessages([
                'end_date' => ['Periode laporan maksimal '.self::MAX_PERIOD_DAYS.' hari.'],
            ]);
        }

        return [
            Team::whereIn('id', $validated['team_ids'])->orderBy('name')->get(),
            $startDate,
            $endDate,
            $validated['title'] ?? null,
        ];
    }

    private function failure(\Throwable $e): JsonResponse
    {
        Log::error('Evaluation report failed: '.$e->getMessage());

        return response()->json([
            'success' => false,
            'message' => 'Gagal membuat laporan evaluasi.',
        ], 500);
    }
}
