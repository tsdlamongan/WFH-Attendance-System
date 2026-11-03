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

    /*
    | Repeated task alert. When an employee brings the same unfinished task to check-in on this
    | many days in a row, the manager gets a WhatsApp message asking to review the task. Days
    | count, not check-ins: several sessions on one day count once. The reminder repeats at
    | every further multiple (6th, 9th, ... day) while it goes on.
    */
    'repeated_task_threshold' => (int) env('REPEATED_TASK_ALERT_THRESHOLD', 3),
];
