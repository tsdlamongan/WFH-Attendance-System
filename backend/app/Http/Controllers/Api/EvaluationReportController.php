<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\EvaluationReportPdf;
use App\Services\EvaluationReportService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EvaluationReportController extends Controller
{
    /** Longest period one report may cover, in days. */
    public const MAX_PERIOD_DAYS = 366;

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
        [$startDate, $endDate] = $this->period($request);

        try {
            return response()->json([
                'success' => true,
                'data' => $this->reportService->build($this->team(), $startDate, $endDate),
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
        [$startDate, $endDate] = $this->period($request);

        try {
            $team = $this->team();
            $report = $this->reportService->build($team, $startDate, $endDate);

            $filename = sprintf(
                'laporan-evaluasi-%s-%s-sd-%s.pdf',
                $team->slug,
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
     * Validated report period as [start of first day, end of last day].
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
        ], [
            'start_date.required' => 'Tanggal mulai wajib diisi.',
            'start_date.date_format' => 'Tanggal mulai tidak valid.',
            'end_date.required' => 'Tanggal selesai wajib diisi.',
            'end_date.date_format' => 'Tanggal selesai tidak valid.',
            'end_date.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
        ]);

        $startDate = Carbon::createFromFormat('Y-m-d', $validated['start_date'])->startOfDay();
        $endDate = Carbon::createFromFormat('Y-m-d', $validated['end_date'])->endOfDay();

        if ($startDate->diffInDays($endDate) > self::MAX_PERIOD_DAYS) {
            throw ValidationException::withMessages([
                'end_date' => ['Periode laporan maksimal '.self::MAX_PERIOD_DAYS.' hari.'],
            ]);
        }

        return [$startDate, $endDate];
    }

    /**
     * Team the report is about. A super admin has no team and must impersonate a manager.
     */
    private function team(): Team
    {
        $team = auth()->user()->team;

        if (! $team) {
            throw new AuthorizationException('Laporan evaluasi dibuat per tim. Masuk sebagai manager tim untuk mengunduhnya.');
        }

        return $team;
    }

    private function failure(\Throwable $e): JsonResponse
    {
        if ($e instanceof AuthorizationException) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        }

        Log::error('Evaluation report failed: '.$e->getMessage());

        return response()->json([
            'success' => false,
            'message' => 'Gagal membuat laporan evaluasi.',
        ], 500);
    }
}
