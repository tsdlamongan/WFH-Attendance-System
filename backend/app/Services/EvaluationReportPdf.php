<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;

/**
 * Renders the evaluation report (see EvaluationReportService::build) as a PDF slide deck:
 * cover, staff summary, staff table, work summary and project table.
 */
class EvaluationReportPdf
{
    /** 16:9 slide in points, the size of the presentation the report replaces. */
    public const PAGE_WIDTH = 959.76;

    public const PAGE_HEIGHT = 540.0;

    /** Page margins in points; the designed slides bleed over them, the project table respects them. */
    public const MARGIN_X = 14.0;

    public const MARGIN_Y = 18.0;

    public const STAFF_ROWS_PER_PAGE = 10;

    private const TITLE_MAX_SIZE = 86;

    private const TITLE_MIN_SIZE = 28;

    /**
     * The report as PDF file contents.
     */
    public function render(array $report): string
    {
        $dompdf = new Dompdf($this->options());
        $dompdf->setPaper([0, 0, self::PAGE_WIDTH, self::PAGE_HEIGHT]);
        $dompdf->loadHtml($this->html($report), 'UTF-8');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * The HTML document the PDF is rendered from.
     */
    public function html(array $report): string
    {
        return view('reports.evaluation', [
            'report' => $report,
            'titleSize' => $this->titleSize($report['title']),
            'staffPages' => $this->staffPages($report['human_resources']['rows']),
            'assets' => $this->path(resource_path('reports/evaluation')),
            'fonts' => $this->path(resource_path('reports/fonts')),
        ])->render();
    }

    /**
     * Font size (pt) of the cover title: as large as the original deck's, smaller for long team
     * names so the single line still fits the slide (a brush glyph is about 0.43 em wide).
     */
    private function titleSize(string $title): int
    {
        $fitting = (int) floor((self::PAGE_WIDTH - 100) / (0.43 * max(1, mb_strlen($title))));

        return max(self::TITLE_MIN_SIZE, min(self::TITLE_MAX_SIZE, $fitting));
    }

    /**
     * Staff rows in pages of a fixed size; the last page is padded with empty rows so every
     * table keeps the same height. A team without staff still gets one (empty) page.
     *
     * @return array<int, array<int, array<string, mixed>|null>>
     */
    private function staffPages(array $rows): array
    {
        $pages = array_chunk($rows, self::STAFF_ROWS_PER_PAGE) ?: [[]];

        return array_map(
            fn (array $page) => array_pad($page, self::STAFF_ROWS_PER_PAGE, null),
            $pages
        );
    }

    private function options(): Options
    {
        $fontCache = config('evaluation_report.font_cache');
        File::ensureDirectoryExists($fontCache);

        $options = new Options;
        // Only the bundled report assets and the font cache may be read; nothing is fetched remotely.
        $options->setChroot([resource_path('reports'), $fontCache]);
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setFontDir($fontCache);
        $options->setFontCache($fontCache);
        $options->setIsFontSubsettingEnabled(true);
        $options->setDefaultFont('helvetica');

        return $options;
    }

    /**
     * Forward slashes, so Windows paths survive inside CSS url() values.
     */
    private function path(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
