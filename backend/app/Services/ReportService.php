<?php

namespace App\Services;

use App\Enums\LeaveStatus;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\Team;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use Carbon\Carbon;

class ReportService
{
    public function __construct(
        private AttendanceRepository $attendanceRepository
    ) {}

    /**
     * Get employee personal report.
     */
    public function getEmployeeReport(User $user, Carbon $startDate, Carbon $endDate): array
    {
        $user->loadMissing('team');
        $requiredWorkHours = $user->team?->getRequiredWorkHours() ?? Team::DEFAULT_REQUIRED_WORK_HOURS;

        $attendances = $this->attendanceRepository->getByUserInDateRange($user, $startDate, $endDate);

        // Group by date
        $groupedByDate = $attendances->groupBy(function ($attendance) {
            return $attendance->date->format('Y-m-d');
        });

        $dailyData = [];
        $totalDaysWorked = 0;
        $totalHours = 0;
        $incompleteDays = 0;
        $totalTasksCompleted = 0;
        $totalTasksIncomplete = 0;
        $totalTasks = 0;

        foreach ($groupedByDate as $date => $dayAttendances) {
            $dailyTotalHours = $dayAttendances->sum('total_hours');
            $totalHours += $dailyTotalHours;
            $totalDaysWorked++;

            // Sort attendances by check_in ascending so Session 1 is the earliest
            $sortedDayAttendances = $dayAttendances->sortBy(function ($attendance) {
                return $attendance->check_in;
            })->values();

            $sessions = $sortedDayAttendances->map(function ($attendance) {
                $tasksCompleted = $attendance->tasks->where('is_completed', true)->count();
                $tasksIncomplete = $attendance->tasks->where('is_completed', false)->count();

                return [
                    'check_in' => $attendance->check_in,
                    'check_out' => $attendance->check_out,
                    'total_hours' => $attendance->total_hours,
                    'tasks_completed' => $tasksCompleted,
                    'tasks_incomplete' => $tasksIncomplete,
                    'tasks' => $attendance->tasks->map(function ($task) {
                        return [
                            'id' => $task->id,
                            'title' => $task->title,
                            'is_completed' => $task->is_completed,
                            'blocker_reason' => $task->blocker_reason,
                        ];
                    })->values(),
                ];
            })->values();

            // Count tasks (using sorted attendances for consistency)
            foreach ($sortedDayAttendances as $attendance) {
                $totalTasksCompleted += $attendance->tasks->where('is_completed', true)->count();
                $totalTasksIncomplete += $attendance->tasks->where('is_completed', false)->count();
                $totalTasks += $attendance->tasks->count();
            }

            $status = 'complete';
            if ($dailyTotalHours < $requiredWorkHours) {
                $status = 'incomplete';
                $incompleteDays++;
            } elseif ($dailyTotalHours > $requiredWorkHours) {
                $status = 'overtime';
            }

            $dailyData[] = [
                'date' => $date,
                'sessions' => $sessions,
                'daily_total_hours' => round($dailyTotalHours, 2),
                'status' => $status,
            ];
        }

        $averageHoursPerDay = $totalDaysWorked > 0 ? round($totalHours / $totalDaysWorked, 2) : 0;
        $requiredHours = $totalDaysWorked * $requiredWorkHours;
        $overtimeHours = max(0, $totalHours - $requiredHours);
        $taskCompletionRate = $totalTasks > 0 ? round(($totalTasksCompleted / $totalTasks) * 100, 2) : 0;

        return [
            'summary' => [
                'total_days_worked' => $totalDaysWorked,
                'total_hours' => round($totalHours, 2),
                'average_hours_per_day' => $averageHoursPerDay,
                'required_hours' => $requiredHours,
                'overtime_hours' => round($overtimeHours, 2),
                'incomplete_days' => $incompleteDays,
                'task_completion_rate' => $taskCompletionRate,
            ],
            'attendances' => $dailyData,
        ];
    }

    /**
     * Get manager dashboard data.
     */
    public function getManagerDashboard(?Carbon $date = null, ?int $teamId = null): array
    {
        $targetDate = $date ?? Carbon::today();

        $query = User::where('role', 'employee');

        if ($teamId) {
            $query->where('team_id', $teamId);
        }

        $allEmployees = $query->get();

        $employees = [];
        $checkedInNow = 0;
        $onLeave = 0;
        $totalDailyHours = 0;
        $activeEmployeeCount = 0;

        foreach ($allEmployees as $employee) {
            $todayAttendances = $this->attendanceRepository->getAllByUserAndDate($employee, $targetDate);
            $activeAttendance = $this->attendanceRepository->findActiveByUser($employee);
            $todayTotalHours = $todayAttendances->sum('total_hours');

            if ($activeAttendance) {
                $elapsedHours = $activeAttendance->check_in->diffInHours(Carbon::now());
                $todayTotalHours += $elapsedHours;
                $checkedInNow++;
            }

            // Check if on leave
            $leave = $employee->leaves()
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $targetDate)
                ->whereDate('end_date', '>=', $targetDate)
                ->first();

            $status = 'checked_out';
            if ($activeAttendance) {
                $status = 'checked_in';
            } elseif ($leave) {
                $status = 'on_leave';
                $onLeave++;
            }

            if ($todayTotalHours > 0) {
                $totalDailyHours += $todayTotalHours;
                $activeEmployeeCount++;
            }

            // Calculate week and month totals
            $weekStart = $targetDate->copy()->startOfWeek();
            $weekEnd = $targetDate->copy()->endOfWeek();
            $monthStart = $targetDate->copy()->startOfMonth();
            $monthEnd = $targetDate->copy()->endOfMonth();

            $weekAttendances = $this->attendanceRepository->getByUserInDateRange($employee, $weekStart, $weekEnd);
            $monthAttendances = $this->attendanceRepository->getByUserInDateRange($employee, $monthStart, $monthEnd);

            $weekTotalHours = round($weekAttendances->sum('total_hours'), 2);
            $monthTotalHours = round($monthAttendances->sum('total_hours'), 2);

            $employeeData = [
                'id' => $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'status' => $status,
                'current_session' => $activeAttendance ? [
                    'check_in' => $activeAttendance->check_in,
                    'elapsed_hours' => round($activeAttendance->check_in->diffInMinutes(Carbon::now()) / 60, 2),
                ] : null,
                'today_total_hours' => round($todayTotalHours, 2),
                'week_total_hours' => $weekTotalHours,
                'month_total_hours' => $monthTotalHours,
            ];

            if ($leave) {
                $employeeData['leave'] = [
                    'start_date' => $leave->start_date->format('Y-m-d'),
                    'end_date' => $leave->end_date->format('Y-m-d'),
                    'reason' => $leave->reason,
                ];
            }

            $employees[] = $employeeData;
        }

        $averageDailyHours = $activeEmployeeCount > 0 ? round($totalDailyHours / $activeEmployeeCount, 2) : 0;

        return [
            'summary' => [
                'total_employees' => $allEmployees->count(),
                'checked_in_now' => $checkedInNow,
                'on_leave' => $onLeave,
                'average_daily_hours' => $averageDailyHours,
            ],
            'employees' => $employees,
        ];
    }

    /**
     * Get employee report for manager.
     */
    public function getEmployeeReportForManager(User $employee, Carbon $startDate, Carbon $endDate): array
    {
        return $this->getEmployeeReport($employee, $startDate, $endDate);
    }

    /**
     * Get daily attendance report for all employees on a specific date.
     * Shows employee list with total hours, overtime, sessions, and tasks.
     */
    public function getDailyAttendanceReport(Carbon $date, ?int $teamId = null): array
    {
        $team = $teamId ? Team::find($teamId) : null;
        $requiredWorkHours = $team?->getRequiredWorkHours() ?? Team::DEFAULT_REQUIRED_WORK_HOURS;

        $employeeQuery = User::where('role', 'employee');

        if ($teamId) {
            $employeeQuery->where('team_id', $teamId);
        }

        $allEmployees = $employeeQuery->get();
        $employeeReports = [];

        foreach ($allEmployees as $employee) {
            $dayAttendances = $this->attendanceRepository->getAllByUserAndDate($employee, $date);

            // Skip if no attendance for this date
            if ($dayAttendances->isEmpty()) {
                // Check if on leave
                $leave = $employee->leaves()
                    ->where('status', 'approved')
                    ->whereDate('start_date', '<=', $date)
                    ->whereDate('end_date', '>=', $date)
                    ->first();

                if ($leave) {
                    $employeeReports[] = [
                        'employee' => [
                            'id' => $employee->id,
                            'name' => $employee->name,
                            'email' => $employee->email,
                        ],
                        'status' => 'on_leave',
                        'daily_total_hours' => 0,
                        'overtime_hours' => 0,
                        'sessions' => [],
                    ];
                }

                continue;
            }

            $dailyTotalHours = $dayAttendances->sum('total_hours');
            $overtimeHours = max(0, $dailyTotalHours - $requiredWorkHours);

            // Sort attendances by check_in ascending so Session 1 is the earliest
            $sortedDayAttendances = $dayAttendances->sortBy(function ($attendance) {
                return $attendance->check_in;
            })->values();

            $sessions = $sortedDayAttendances->map(function ($attendance, $index) {
                $tasksCompleted = $attendance->tasks->where('is_completed', true)->count();
                $tasksIncomplete = $attendance->tasks->where('is_completed', false)->count();

                return [
                    'session_number' => $index + 1,
                    'check_in' => $attendance->check_in,
                    'check_out' => $attendance->check_out,
                    'total_hours' => $attendance->total_hours,
                    'tasks_completed' => $tasksCompleted,
                    'tasks_incomplete' => $tasksIncomplete,
                    'tasks' => $attendance->tasks->map(function ($task) {
                        return [
                            'id' => $task->id,
                            'title' => $task->title,
                            'is_completed' => $task->is_completed,
                            'blocker_reason' => $task->blocker_reason,
                        ];
                    })->values(),
                ];
            })->values();

            $status = 'complete';
            if ($dailyTotalHours < $requiredWorkHours) {
                $status = 'incomplete';
            } elseif ($dailyTotalHours > $requiredWorkHours) {
                $status = 'overtime';
            }

            $employeeReports[] = [
                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'email' => $employee->email,
                ],
                'status' => $status,
                'daily_total_hours' => round($dailyTotalHours, 2),
                'overtime_hours' => round($overtimeHours, 2),
                'required_hours' => $requiredWorkHours,
                'sessions' => $sessions,
            ];
        }

        // Sort by employee name
        usort($employeeReports, function ($a, $b) {
            return strcmp($a['employee']['name'], $b['employee']['name']);
        });

        return [
            'date' => $date->format('Y-m-d'),
            'required_hours' => $requiredWorkHours,
            'employees' => $employeeReports,
        ];
    }

    /**
     * Get monthly attendance report for all employees within a date range.
     * Shows employee list with total work hours. Clicking employee shows daily details.
     */
    private const EXPECTED_HOURS_PER_DAY = 8;

    public function getMonthlyAttendanceReport(Carbon $startDate, Carbon $endDate, ?int $teamId = null): array
    {
        $team = $teamId ? Team::find($teamId) : null;
        $requiredWorkHours = $team?->getRequiredWorkHours() ?? Team::DEFAULT_REQUIRED_WORK_HOURS;

        // Calculate working days in the filter range (excluding Sundays and holidays)
        $workingDaysInRange = $this->calculateWorkingDays($startDate, $endDate, $teamId);
        // Expected hours = working days × 8 hours per day
        $expectedTotalHours = $workingDaysInRange * self::EXPECTED_HOURS_PER_DAY;

        $employeeQuery = User::where('role', 'employee');

        if ($teamId) {
            $employeeQuery->where('team_id', $teamId);
        }

        $allEmployees = $employeeQuery->get();
        $employeeReports = [];

        // Get holidays for the team in the date range
        $holidayDates = $this->getHolidayDates($startDate, $endDate, $teamId);

        foreach ($allEmployees as $employee) {
            $attendances = $this->attendanceRepository->getByUserInDateRange($employee, $startDate, $endDate);

            // Group by date for daily details
            $groupedByDate = $attendances->groupBy(function ($attendance) {
                return $attendance->date->format('Y-m-d');
            });

            // Get approved leaves for this employee in the date range
            $approvedLeaves = Leave::where('user_id', $employee->id)
                ->where('status', LeaveStatus::APPROVED)
                ->where(function ($query) use ($startDate, $endDate) {
                    $query->whereBetween('start_date', [$startDate, $endDate])
                        ->orWhereBetween('end_date', [$startDate, $endDate])
                        ->orWhere(function ($q) use ($startDate, $endDate) {
                            $q->where('start_date', '<=', $startDate)
                                ->where('end_date', '>=', $endDate);
                        });
                })
                ->get();

            // Calculate leave days and collect leave dates
            $leaveDates = [];
            foreach ($approvedLeaves as $leave) {
                $leaveStart = $leave->start_date->max($startDate);
                $leaveEnd = $leave->end_date->min($endDate);
                $currentDate = $leaveStart->copy();

                while ($currentDate->lte($leaveEnd)) {
                    $dateStr = $currentDate->format('Y-m-d');
                    // Only count if it's a working day (not Sunday and not holiday)
                    if (! $currentDate->isSunday() && ! in_array($dateStr, $holidayDates)) {
                        $leaveDates[$dateStr] = true;
                    }
                    $currentDate->addDay();
                }
            }

            $dailyDetails = [];
            $totalHours = 0;
            $totalDaysWorked = 0;
            $totalLeaveDays = 0;

            // Process attendance records
            foreach ($groupedByDate as $date => $dayAttendances) {
                $dailyTotalHours = $dayAttendances->sum('total_hours');
                $dailyOvertimeHours = max(0, $dailyTotalHours - $requiredWorkHours);

                $totalHours += $dailyTotalHours;
                $totalDaysWorked++;

                // Remove from leave dates if there's attendance on that day
                unset($leaveDates[$date]);

                // Sort attendances by check_in ascending
                $sortedDayAttendances = $dayAttendances->sortBy(function ($attendance) {
                    return $attendance->check_in;
                })->values();

                $sessions = $sortedDayAttendances->map(function ($attendance, $index) {
                    $tasksCompleted = $attendance->tasks->where('is_completed', true)->count();
                    $tasksIncomplete = $attendance->tasks->where('is_completed', false)->count();

                    return [
                        'session_number' => $index + 1,
                        'check_in' => $attendance->check_in,
                        'check_out' => $attendance->check_out,
                        'total_hours' => $attendance->total_hours,
                        'tasks_completed' => $tasksCompleted,
                        'tasks_incomplete' => $tasksIncomplete,
                        'tasks' => $attendance->tasks->map(function ($task) {
                            return [
                                'id' => $task->id,
                                'title' => $task->title,
                                'is_completed' => $task->is_completed,
                                'blocker_reason' => $task->blocker_reason,
                            ];
                        })->values(),
                    ];
                })->values();

                $status = 'complete';
                if ($dailyTotalHours < $requiredWorkHours) {
                    $status = 'incomplete';
                } elseif ($dailyTotalHours > $requiredWorkHours) {
                    $status = 'overtime';
                }

                $dailyDetails[] = [
                    'date' => $date,
                    'daily_total_hours' => round($dailyTotalHours, 2),
                    'overtime_hours' => round($dailyOvertimeHours, 2),
                    'status' => $status,
                    'sessions' => $sessions,
                ];
            }

            // Add leave days to total hours and daily details
            foreach ($leaveDates as $leaveDate => $value) {
                $totalHours += self::EXPECTED_HOURS_PER_DAY;
                $totalLeaveDays++;

                $dailyDetails[] = [
                    'date' => $leaveDate,
                    'daily_total_hours' => self::EXPECTED_HOURS_PER_DAY,
                    'overtime_hours' => 0,
                    'status' => 'on_leave',
                    'sessions' => [],
                ];
            }

            // Sort daily details by date descending (newest first)
            usort($dailyDetails, function ($a, $b) {
                return strcmp($b['date'], $a['date']);
            });

            // Calculate overtime and deficit based on expected hours from filter range
            $hoursDifference = $totalHours - $expectedTotalHours;
            $totalOvertimeHours = max(0, $hoursDifference);
            $totalDeficitHours = max(0, -$hoursDifference);

            $employeeReports[] = [
                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'email' => $employee->email,
                ],
                'total_hours' => round($totalHours, 2),
                'total_overtime_hours' => round($totalOvertimeHours, 2),
                'total_deficit_hours' => round($totalDeficitHours, 2),
                'total_days_worked' => $totalDaysWorked,
                'total_leave_days' => $totalLeaveDays,
                'daily_details' => $dailyDetails,
            ];
        }

        // Sort by overtime hours descending (most overtime first)
        usort($employeeReports, function ($a, $b) {
            return $b['total_overtime_hours'] <=> $a['total_overtime_hours'];
        });

        return [
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'required_hours' => $requiredWorkHours,
            'working_days' => $workingDaysInRange,
            'expected_total_hours' => $expectedTotalHours,
            'employees' => $employeeReports,
        ];
    }

    /**
     * Get check-in time consistency report.
     * Shows employees who consistently check-in within the configured time window.
     */
    public function getCheckInTimeReport(Carbon $startDate, Carbon $endDate, ?int $teamId = null): array
    {
        $team = $teamId ? Team::find($teamId) : null;
        $windowStart = $team?->getCheckInWindowStart() ?? Team::DEFAULT_CHECK_IN_WINDOW_START;
        $windowEnd = $team?->getCheckInWindowEnd() ?? Team::DEFAULT_CHECK_IN_WINDOW_END;

        $employeeQuery = User::where('role', 'employee');

        if ($teamId) {
            $employeeQuery->where('team_id', $teamId);
        }

        $allEmployees = $employeeQuery->get();
        $employeeReports = [];

        foreach ($allEmployees as $employee) {
            $attendances = $this->attendanceRepository->getByUserInDateRange($employee, $startDate, $endDate);

            if ($attendances->isEmpty()) {
                continue;
            }

            // For each date, get the first check-in (earliest session)
            $groupedByDate = $attendances->groupBy(function ($attendance) {
                return $attendance->date->format('Y-m-d');
            });

            $totalDays = 0;
            $onTimeDays = 0;
            $details = [];

            foreach ($groupedByDate as $date => $dayAttendances) {
                // Get the earliest check-in for the day
                $earliestAttendance = $dayAttendances->sortBy('check_in')->first();
                $checkInTime = $earliestAttendance->check_in->format('H:i:s');

                $isOnTime = $checkInTime >= $windowStart && $checkInTime <= $windowEnd;

                $totalDays++;
                if ($isOnTime) {
                    $onTimeDays++;
                }

                $details[] = [
                    'date' => $date,
                    'check_in_time' => $earliestAttendance->check_in->format('H:i'),
                    'is_on_time' => $isOnTime,
                ];
            }

            $consistencyRate = $totalDays > 0 ? round(($onTimeDays / $totalDays) * 100, 2) : 0;

            // Sort details by date descending
            usort($details, function ($a, $b) {
                return strcmp($b['date'], $a['date']);
            });

            $employeeReports[] = [
                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'email' => $employee->email,
                ],
                'total_days' => $totalDays,
                'on_time_days' => $onTimeDays,
                'late_days' => $totalDays - $onTimeDays,
                'consistency_rate' => $consistencyRate,
                'details' => $details,
            ];
        }

        // Sort by consistency rate descending (best performers first)
        usort($employeeReports, function ($a, $b) {
            if ($a['consistency_rate'] === $b['consistency_rate']) {
                return strcmp($a['employee']['name'], $b['employee']['name']);
            }

            return $b['consistency_rate'] <=> $a['consistency_rate'];
        });

        return [
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'window_start' => substr($windowStart, 0, 5), // Format to HH:MM
            'window_end' => substr($windowEnd, 0, 5), // Format to HH:MM
            'employees' => $employeeReports,
        ];
    }

    /**
     * Calculate the number of working days between two dates.
     * Excludes Sundays and holidays only (Saturdays are working days).
     */
    private function calculateWorkingDays(Carbon $startDate, Carbon $endDate, ?int $teamId = null): int
    {
        // Get holidays in the date range for the team
        $holidayQuery = Holiday::whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);

        if ($teamId) {
            $holidayQuery->where('team_id', $teamId);
        }

        $holidays = $holidayQuery->pluck('date')->map(function ($date) {
            return Carbon::parse($date)->format('Y-m-d');
        })->toArray();

        $workingDays = 0;
        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            // Check if it's Sunday (Sunday = 0 in Carbon)
            $isSunday = $currentDate->isSunday();

            // Check if it's a holiday
            $isHoliday = in_array($currentDate->format('Y-m-d'), $holidays);

            // Working days = all days except Sundays and holidays
            if (! $isSunday && ! $isHoliday) {
                $workingDays++;
            }

            $currentDate->addDay();
        }

        return $workingDays;
    }

    /**
     * Get holiday dates as array of strings for a date range.
     */
    private function getHolidayDates(Carbon $startDate, Carbon $endDate, ?int $teamId = null): array
    {
        $holidayQuery = Holiday::whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);

        if ($teamId) {
            $holidayQuery->where('team_id', $teamId);
        }

        return $holidayQuery->pluck('date')->map(function ($date) {
            return Carbon::parse($date)->format('Y-m-d');
        })->toArray();
    }
}
