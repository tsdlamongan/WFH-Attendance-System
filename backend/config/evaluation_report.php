<?php

return [

    /*
    | "Status" column of the evaluation report (Laporan SDM): an employee's worked hours
    | are compared with the expected hours of the period (working days x 8 hours, the same
    | basis as the monthly attendance report). The first entry whose "min" percentage is
    | reached wins, so keep the last one at 0.
    */
    'hour_ratings' => [
        ['min' => 100, 'label' => 'Sangat Baik', 'color' => '#09963b'],
        ['min' => 90, 'label' => 'Baik', 'color' => '#00a5ab'],
        ['min' => 0, 'label' => 'Kurang Baik', 'color' => '#f0b400'],
    ],

    /*
    | PROBLEM column: the work employees logged for a project can be shortened to two or three
    | short sentences on what needs attention most, with Gemini (needs GEMINI_API_KEY in
    | services.gemini; services.fallback_ai takes over when Gemini fails). Only entries of at least
    | min_length characters are sent; the result is cached by text for cache_days. When the API
    | is unavailable the plain list is printed instead.
    */
    'problem_summary' => [
        'enabled' => env('EVALUATION_REPORT_AI_SUMMARY', true),
        'min_length' => 120,
        'max_chars' => 200,
        'cache_days' => 7,
        'timeout' => 20, // seconds per attempt (3 attempts for server/connection errors, 1 when a fallback is set)
        'fallback_timeout' => 45, // seconds for the fallback API, a slower general purpose model
    ],

    /*
    | Storage directory for the PDF renderer's font cache (generated on first use, must be
    | writable). The fonts themselves are shipped in resources/reports/fonts.
    */
    'font_cache' => storage_path('app/private/dompdf'),

];
