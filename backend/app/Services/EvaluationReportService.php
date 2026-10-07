<?php

namespace App\Services;

use App\Enums\EmploymentType;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds the team's evaluation report ("Laporan Evaluasi"): the staff section from the
 * check-in data and the work section from the tasks employees logged per project.
 */
class EvaluationReportService
{
    public const NO_WORK_LABEL = 'Tidak ada';

    public function __construct(
        private ReportService $reportService,
        private ProblemSummaryService $problemSummary
    ) {}

    /**
     * Report data for one team and period, shared by the JSON preview and the PDF.
     */
    public function build(Team $team, Carbon $startDate, Carbon $endDate): array
    {
        return [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
            ],
            'title' => $this->divisionTitle($team),
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'label' => $this->periodLabel($startDate, $endDate),
            ],
            'human_resources' => $this->humanResources($team, $startDate, $endDate),
            'projects' => $this->projects($team, $startDate, $endDate),
        ];
    }

    /**
     * Staff section: every active manager and employee with the hours worked in the period.
     * Hours, working days and expected hours come from the monthly attendance report, so both
     * reports always agree (approved leave days are credited there as well).
     */
    private function humanResources(Team $team, Carbon $startDate, Carbon $endDate): array
    {
        $attendance = $this->reportService->getMonthlyAttendanceReport($startDate, $endDate, $team->id);
        $attendanceByEmployee = collect($attendance['employees'])->keyBy('employee.id');
        $workingDays = $attendance['working_days'];
        $expectedHours = (float) $attendance['expected_total_hours'];

        $users = User::where('team_id', $team->id)
            ->where('is_disabled', false)
            ->whereIn('role', [UserRole::MANAGER, UserRole::EMPLOYEE])
            ->get()
            // Managers first, then permanent employees, then interns; alphabetical inside each group.
            ->sortBy(fn (User $user) => [$this->staffGroup($user), Str::lower($user->name)])
            ->values();

        $rows = $users->map(function (User $user, int $index) use ($attendanceByEmployee, $expectedHours) {
            // Managers do not check in, so they are listed without hours or a rating.
            $employeeAttendance = $attendanceByEmployee->get($user->id);
            $totalHours = $employeeAttendance ? (float) $employeeAttendance['total_hours'] : null;
            $rating = $totalHours === null ? null : $this->rating($totalHours, $expectedHours);

            return [
                'no' => $index + 1,
                'name' => $user->name,
                'job_title' => $user->job_title ?: ($user->isManager() ? 'Manager' : '-'),
                'employment_type' => $user->employment_type->value,
                'total_hours' => $totalHours,
                'hours_label' => $totalHours === null ? '' : $this->hoursLabel($totalHours),
                'rating' => $rating['label'] ?? null,
                'rating_color' => $rating['color'] ?? null,
            ];
        });

        return [
            'summary' => [
                'total' => $users->count(),
                'permanent' => $users->where('employment_type', EmploymentType::PERMANENT)->count(),
                'intern' => $users->where('employment_type', EmploymentType::INTERN)->count(),
                'attendance_percentage' => $this->attendancePercentage($attendance['employees'], $workingDays),
                'working_days' => $workingDays,
                'expected_hours' => $expectedHours,
            ],
            'rows' => $rows->all(),
        ];
    }

    private function staffGroup(User $user): int
    {
        if ($user->isManager()) {
            return 0;
        }

        return $user->employment_type === EmploymentType::INTERN ? 2 : 1;
    }

    /**
     * Share of working days on which the employees were present or on approved leave.
     * Null when there is nothing to measure (no employees or no working day in the period).
     */
    private function attendancePercentage(array $employees, int $workingDays): ?float
    {
        if ($workingDays === 0 || count($employees) === 0) {
            return null;
        }

        $attendedDays = collect($employees)->sum(
            fn (array $employee) => min($workingDays, $employee['total_days_worked'] + $employee['total_leave_days'])
        );

        return round($attendedDays / ($workingDays * count($employees)) * 100, 1);
    }

    /**
     * Rating (label + colour) of worked hours against the expected hours, see config/evaluation_report.php.
     */
    private function rating(float $hours, float $expectedHours): ?array
    {
        if ($expectedHours <= 0) {
            return null;
        }

        $percentage = round($hours / $expectedHours * 100, 2);

        return collect(config('evaluation_report.hour_ratings'))
            ->sortByDesc('min')
            ->first(fn (array $rating) => $percentage >= $rating['min']);
    }

    /**
     * Decimal hours as "88 Jam 5 Menit".
     */
    private function hoursLabel(float $hours): string
    {
        $totalMinutes = (int) round($hours * 60);
        $wholeHours = intdiv($totalMinutes, 60);
        $minutes = $totalMinutes % 60;

        if ($minutes === 0) {
            return "{$wholeHours} Jam";
        }

        return $wholeHours === 0 ? "{$minutes} Menit" : "{$wholeHours} Jam {$minutes} Menit";
    }

    /**
     * Work section: one row per project. Listed are the active projects plus inactive ones
     * that still had work logged in the period.
     */
    private function projects(Team $team, Carbon $startDate, Carbon $endDate): array
    {
        $projects = Project::where('team_id', $team->id)
            ->with(['tasks' => fn ($query) => $query
                ->whereHas('attendance', fn ($attendance) => $attendance->whereBetween('date', [$startDate, $endDate]))
                ->with('attendance.user')])
            ->orderBy('id')
            ->get()
            ->filter(fn (Project $project) => $project->is_active || $project->tasks->isNotEmpty())
            ->values();

        $rows = $projects->map(function (Project $project, int $index) {
            // Oldest first, so a task carried over to later sessions ends up with its latest state.
            $tasks = $project->tasks
                ->sortBy(fn (Task $task) => [$task->attendance->check_in->getTimestamp(), $task->id])
                ->values();

            return [
                'no' => $index + 1,
                'id' => $project->id,
                'name' => $project->name,
                'pic' => $project->pic ?: $this->contributors($tasks),
                'progress' => $project->progress,
                'problem' => $this->workSummary($tasks),
                'note' => $project->note ?: $project->status->label(),
                'status' => $project->status->value,
                'tasks_count' => $tasks->count(),
            ];
        });

        // Long work lists are shortened to a sentence or two (when Gemini is configured and answers).
        $summaries = $this->problemSummary->summarize(
            $rows->map(fn (array $row) => ['id' => $row['id'], 'name' => $row['name'], 'problem' => $row['problem']])->all()
        );

        $rows = $rows->map(function (array $row) use ($summaries) {
            $summarized = isset($summaries[$row['id']]);

            return array_merge($row, [
                'problem' => $summarized ? $summaries[$row['id']] : $row['problem'],
                'problem_detail' => $row['problem'],
                'problem_summarized' => $summarized,
            ]);
        });

        return [
            'summary' => [
                'total' => $projects->count(),
                'completed' => $projects->where('status', ProjectStatus::COMPLETED)->count(),
                'on_progress' => $projects->where('status', ProjectStatus::ON_PROGRESS)->count(),
                'hold' => $projects->where('status', ProjectStatus::HOLD)->count(),
            ],
            'rows' => $rows->all(),
        ];
    }

    /**
     * Names of the employees who logged tasks, used when the manager set no PIC.
     */
    private function contributors(Collection $tasks): string
    {
        $names = $tasks
            ->map(fn (Task $task) => $task->attendance->user?->name)
            ->filter()
            ->unique()
            ->sort();

        return $names->isEmpty() ? '-' : $names->implode(', ');
    }

    /**
     * The work employees logged for a project, one entry per distinct task title.
     * A task left open at check-out carries its blocker, e.g. "Integrasi API (menunggu akses)".
     */
    private function workSummary(Collection $tasks): string
    {
        $entries = [];

        foreach ($tasks as $task) {
            $title = Str::squish($task->title);
            $isOpen = ! $task->is_completed && $task->attendance->check_out !== null;

            // Same title again (carried over from an earlier session): keep its place, take the latest state.
            $entries[Str::lower($title)] = $isOpen
                ? $title.' ('.($task->blocker_reason ?: Task::AUTO_CHECKOUT_BLOCKER).')'
                : $title;
        }

        return $entries === [] ? self::NO_WORK_LABEL : implode('; ', $entries);
    }

    /**
     * Cover title, e.g. team "IT Development" becomes "DIVISI IT DEVELOPMENT".
     */
    private function divisionTitle(Team $team): string
    {
        $name = Str::upper(Str::squish($team->name));

        return Str::startsWith($name, 'DIVISI') ? $name : 'DIVISI '.$name;
    }

    /**
     * Period in Indonesian, e.g. "19 Agustus – 1 September 2026".
     */
    private function periodLabel(Carbon $startDate, Carbon $endDate): string
    {
        $start = $startDate->copy()->locale('id');
        $end = $endDate->copy()->locale('id');

        if ($start->isSameDay($end)) {
            return $end->translatedFormat('j F Y');
        }

        $startFormat = match (true) {
            $start->isSameMonth($end) => 'j',
            $start->isSameYear($end) => 'j F',
            default => 'j F Y',
        };

        return $start->translatedFormat($startFormat).' – '.$end->translatedFormat('j F Y');
    }
}
