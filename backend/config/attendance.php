<?php

return [
    // Attendance settings are now managed per team (see teams table and TeamSettingsController).

    /*
    | First date (Y-m-d) from which reports count only manager-approved overtime.
    | Days before it keep the legacy rule (hours above the team's required hours),
    | so history recorded before overtime approval existed is not lost.
    | Set to null to always use the approval-based rule.
    */
    'overtime_approval_starts_on' => env('OVERTIME_APPROVAL_STARTS_ON', '2026-10-05'),
];
