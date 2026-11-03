@php
    use App\Services\EvaluationReportPdf as Pdf;

    $staff = $report['human_resources'];
    $work = $report['projects'];
    $attendance = $staff['summary']['attendance_percentage'];
    $attendanceLabel = $attendance === null ? '-' : rtrim(rtrim(number_format($attendance, 1, ',', ''), '0'), ',').'%';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Evaluasi {{ $report['title'] }} - {{ $report['period']['label'] }}</title>
    <style>
        /*
         * The decorative faces of the original deck are proprietary; these are open-licence stand-ins.
         * dompdf draws them lower than a browser would, so every "top" of a .hand / .brush text below is
         * calibrated for line-height 1: caps are centred at top + 0.735 x font-size (Amatic SC) and
         * top + 0.772 x font-size (Caveat Brush). Re-measure when swapping a font file.
         */
        @font-face { font-family: 'Amatic SC'; font-weight: normal; font-style: normal; src: url('{{ $fonts }}/AmaticSC-Regular.ttf') format('truetype'); }
        @font-face { font-family: 'Amatic SC'; font-weight: bold; font-style: normal; src: url('{{ $fonts }}/AmaticSC-Bold.ttf') format('truetype'); }
        @font-face { font-family: 'Caveat Brush'; font-weight: normal; font-style: normal; src: url('{{ $fonts }}/CaveatBrush-Regular.ttf') format('truetype'); }

        @page { margin: {{ Pdf::MARGIN_Y }}pt {{ Pdf::MARGIN_X }}pt; }

        body { margin: 0; font-family: Helvetica, Arial, sans-serif; color: #000000; }

        /* A designed slide: one page, positioned children use the slide's own coordinates (pt). */
        .slide { position: relative; height: {{ Pdf::PAGE_HEIGHT - 2 * Pdf::MARGIN_Y }}pt; page-break-after: always; }
        .canvas { position: absolute; left: -{{ Pdf::MARGIN_X }}pt; top: -{{ Pdf::MARGIN_Y }}pt; width: {{ Pdf::PAGE_WIDTH }}pt; height: {{ Pdf::PAGE_HEIGHT }}pt; }
        .canvas .at { position: absolute; }

        .hand { font-family: 'Amatic SC', Helvetica, sans-serif; line-height: 1; white-space: nowrap; }
        .brush { font-family: 'Caveat Brush', Helvetica, sans-serif; line-height: 1; white-space: nowrap; }

        /* Cover */
        .cover-line { left: 0; width: {{ Pdf::PAGE_WIDTH }}pt; text-align: center; color: #ffffff; }

        /* Staff summary */
        .summary-heading { left: 58pt; top: 205pt; width: 260pt; font-size: 35pt; line-height: 46pt; }
        .summary-card { left: 367pt; width: 542pt; height: 97pt; border-radius: 15pt; }
        .summary-card .hand { left: 21pt; top: 11.75pt; color: #ffffff; font-size: 50pt; }

        /* Staff table */
        table.staff { width: 826pt; border-collapse: collapse; }
        table.staff th, table.staff td { height: 28.3pt; padding: 0 7pt; border: 1pt solid #ffffff; font-size: 11pt; text-align: left; vertical-align: middle; white-space: nowrap; }
        table.staff th { height: 27.5pt; background-color: #00a5ab; color: #ffffff; font-weight: bold; }
        table.staff tr.odd td { background-color: #cbe1e2; }
        table.staff tr.even td { background-color: #e7f0f1; }

        /* Work summary */
        .work-heading { left: 0; top: 58pt; width: {{ Pdf::PAGE_WIDTH }}pt; text-align: center; font-size: 50pt; line-height: 76pt; }
        .tile-label { width: 219pt; text-align: center; color: #7b7b7b; font-size: 22.5pt; }

        /* Project table: flows over as many pages as it needs, the header repeats. */
        table.projects { width: 100%; border-collapse: collapse; }
        table.projects th, table.projects td { border: 0.75pt solid #21b0b5; background-color: #e7f0f1; vertical-align: middle; }
        table.projects th { padding: 3pt; font-size: 9.5pt; font-weight: normal; text-align: center; }
        table.projects th.step { padding: 3pt 0; font-size: 7.5pt; }
        table.projects td { height: 15.5pt; padding: 1.5pt 3pt; font-size: 7.5pt; line-height: 9.5pt; }
        table.projects td.center { padding: 1.5pt 0; text-align: center; }
        table.projects tr { page-break-inside: avoid; }
        .check { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; }
    </style>
</head>
<body>
    {{-- Cover: the photo, swoosh, underline and logos are one image, only the text is live --}}
    <div class="slide">
        <div class="canvas">
            <img class="at" src="{{ $assets }}/cover.jpg" style="left: 0; top: 0; width: {{ Pdf::PAGE_WIDTH }}pt; height: {{ Pdf::PAGE_HEIGHT }}pt;">
            <div class="at cover-line hand" style="top: 121pt; font-size: 30pt;">LAPORAN EVALUASI</div>
            <div class="at cover-line brush" style="top: {{ round(213 - 0.772 * $titleSize, 1) }}pt; font-size: {{ $titleSize }}pt;">{{ $report['title'] }}</div>
            <div class="at cover-line hand" style="top: 314pt; font-size: 29pt; color: #e3e9f0;">Periode: {{ $report['period']['label'] }}</div>
        </div>
    </div>

    {{-- Staff summary --}}
    <div class="slide">
        <div class="canvas">
            <div class="at summary-heading">RINGKASAN<br>LAPORAN<br>SDM</div>
            <img class="at" src="{{ $assets }}/stroke-vertical.jpg" style="left: 336.33pt; top: 120.33pt; width: 8.33pt; height: 299.67pt;">
            @foreach ([
                [54.6, '#9c6a6a', "TOTAL SDM: {$staff['summary']['total']} KARYAWAN"],
                [164.6, '#769860', "KARYAWAN TETAP: {$staff['summary']['permanent']} ORANG"],
                [275.4, '#578592', "KARYAWAN INTERN: {$staff['summary']['intern']} ORANG"],
                [385.4, '#824f8c', "PERSENTASE KEHADIRAN: {$attendanceLabel}"],
            ] as [$top, $colour, $text])
                <div class="at summary-card" style="top: {{ $top }}pt; background-color: {{ $colour }};">
                    <div class="at hand">{{ $text }}</div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Staff table, a fixed number of rows per slide --}}
    @foreach ($staffPages as $rows)
        <div class="slide">
            <div class="canvas">
                <div class="at brush" style="left: 75pt; top: 23.6pt; font-size: 64pt;">Laporan SDM</div>
                <img class="at" src="{{ $assets }}/stroke-title.jpg" style="left: 64pt; top: 130.67pt; width: 832pt; height: 9.67pt;">
                <div class="at" style="left: 66.2pt; top: 151.5pt; width: 826pt;">
                    <table class="staff">
                        <thead>
                            <tr>
                                <th style="width: 22pt;">No</th>
                                <th style="width: 250.5pt;">Nama</th>
                                <th style="width: 192pt;">Jabatan</th>
                                <th style="width: 159pt;">Jumlah Jam Kerja</th>
                                <th style="width: 128pt;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr class="{{ $loop->odd ? 'odd' : 'even' }}">
                                    <td>{{ $row['no'] ?? '' }}</td>
                                    <td>{{ Str::limit($row['name'] ?? '', 44) }}</td>
                                    <td>{{ Str::limit($row['job_title'] ?? '', 34) }}</td>
                                    <td>{{ $row['hours_label'] ?? '' }}</td>
                                    <td style="color: {{ $row['rating_color'] ?? '#000000' }};">{{ $row['rating'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endforeach

    {{-- Work summary --}}
    <div class="slide">
        <div class="canvas">
            <div class="at work-heading">Ringkasan Laporan Pekerjaan</div>
            <img class="at" src="{{ $assets }}/stroke-heading.jpg" style="left: 49pt; top: 173pt; width: 862.67pt; height: 8.33pt;">
            @foreach ([
                ['planned', 'TOTAL PROJECT PLANNED', $work['summary']['total'], 93],
                ['completed', 'PROJECT SELESAI', $work['summary']['completed'], 312.67],
                ['on-progress', 'PROJECT ON-PROGRESS', $work['summary']['on_progress'], 531.67],
                ['hold', 'PROJECT HOLD', $work['summary']['hold'], 751.33],
            ] as [$tile, $label, $value, $left])
                <img class="at" src="{{ $assets }}/tile-{{ $tile }}.jpg" style="left: {{ $left }}pt; top: 253.67pt; width: 114.67pt; height: 114.67pt;">
                <div class="at tile-label hand" style="left: {{ $left - 52 }}pt; top: 402.5pt;">{{ $label }}</div>
                <div class="at tile-label hand" style="left: {{ $left - 52 }}pt; top: 429.5pt; font-weight: bold;">{{ $value }}</div>
            @endforeach
        </div>
    </div>

    {{-- Project table --}}
    <table class="projects">
        <thead>
            <tr>
                <th rowspan="2" style="width: 18pt;">NO</th>
                <th rowspan="2" style="width: 124pt;">NAMA PROJECT</th>
                <th rowspan="2" style="width: 94pt;">PIC</th>
                <th colspan="10">UPDATE</th>
                <th rowspan="2">PROBLEM</th>
                <th rowspan="2" style="width: 144pt;">NOTE</th>
            </tr>
            <tr>
                @foreach (range(10, 100, 10) as $step)
                    <th class="step" style="width: 19pt;">{{ $step }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($work['rows'] as $row)
                <tr>
                    <td class="center">{{ $row['no'] }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['pic'] }}</td>
                    @foreach (range(10, 100, 10) as $step)
                        <td class="center">@if ($row['progress'] === $step)<span class="check">&#8730;</span>@endif</td>
                    @endforeach
                    <td>{{ $row['problem'] }}</td>
                    <td>{{ $row['note'] }}</td>
                </tr>
            @empty
                <tr>
                    <td class="center" colspan="15">Belum ada project.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
