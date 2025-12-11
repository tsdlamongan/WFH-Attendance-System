<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\Team;
use App\Repositories\AttendanceRepository;
use App\Repositories\UserRepository;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;

class WhatsAppRecapService
{
    private const MAX_MESSAGE_LENGTH = 3500; // WhatsApp limit is ~4096, use 3500 for safety

    public function __construct(
        private AttendanceRepository $attendanceRepository,
        private UserRepository $userRepository,
        private WhatsAppGatewayService $whatsAppGatewayService,
        private ActivityLogService $activityLogService
    ) {}

    /**
     * Generate recap data for a team on a specific date.
     *
     * @param  Team  $team
     * @param  Carbon  $date
     * @return array
     */
    public function generateRecapData(Team $team, Carbon $date): array
    {
        $employees = $this->userRepository->getEmployees($team->id);

        $checkedInOut = [];
        $stillCheckedIn = [];
        $notCheckedIn = [];
        $allTasks = [];
        $completedTasks = [];
        $incompleteTasks = [];

        foreach ($employees as $employee) {
            // Get all attendances for the date
            $todayAttendances = $this->attendanceRepository->getAllByUserAndDate($employee, $date);

            // Check if employee has active attendance
            $activeAttendance = $this->attendanceRepository->findActiveByUser($employee);

            // Check leave status
            $leave = $employee->leaves()
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $date)
                ->whereDate('end_date', '>=', $date)
                ->first();

            if ($todayAttendances->isEmpty() && ! $activeAttendance) {
                // Not checked in
                $notCheckedIn[] = [
                    'name' => $employee->name,
                    'on_leave' => (bool) $leave,
                ];
            } elseif ($activeAttendance && $activeAttendance->date->isSameDay($date)) {
                // Still checked in
                $stillCheckedIn[] = [
                    'name' => $employee->name,
                    'check_in' => $activeAttendance->check_in->format('H:i'),
                    'duration' => $activeAttendance->check_in->diffInHours(Carbon::now()),
                ];

                // Collect tasks from active attendance
                foreach ($activeAttendance->tasks as $task) {
                    $allTasks[] = $task;
                    if ($task->is_completed) {
                        $completedTasks[] = [
                            'employee' => $employee->name,
                            'title' => $task->title,
                        ];
                    } else {
                        $incompleteTasks[] = [
                            'employee' => $employee->name,
                            'title' => $task->title,
                            'blocker' => $task->blocker_reason,
                        ];
                    }
                }
            } else {
                // Checked in and out (completed sessions)
                $totalHours = $todayAttendances->sum('total_hours');
                $totalTasks = 0;
                $completedTasksCount = 0;

                foreach ($todayAttendances as $attendance) {
                    foreach ($attendance->tasks as $task) {
                        $totalTasks++;
                        $allTasks[] = $task;

                        if ($task->is_completed) {
                            $completedTasksCount++;
                            $completedTasks[] = [
                                'employee' => $employee->name,
                                'title' => $task->title,
                            ];
                        } else {
                            $incompleteTasks[] = [
                                'employee' => $employee->name,
                                'title' => $task->title,
                                'blocker' => $task->blocker_reason,
                            ];
                        }
                    }
                }

                $checkedInOut[] = [
                    'name' => $employee->name,
                    'total_hours' => number_format($totalHours, 1),
                    'tasks_completed' => $completedTasksCount,
                    'tasks_total' => $totalTasks,
                ];
            }
        }

        return [
            'date' => $date,
            'team_name' => $team->name,
            'checked_in_out' => $checkedInOut,
            'still_checked_in' => $stillCheckedIn,
            'not_checked_in' => $notCheckedIn,
            'all_tasks_count' => count($allTasks),
            'completed_tasks' => $completedTasks,
            'incomplete_tasks' => $incompleteTasks,
        ];
    }

    /**
     * Format recap data into WhatsApp message.
     *
     * @param  array  $recapData
     * @return string
     */
    public function formatRecapMessage(array $recapData): string
    {
        $date = $recapData['date'];
        $teamName = $recapData['team_name'];

        $message = "📊 *Daily Attendance Recap*\n";
        $message .= "🗓️ {$teamName} - {$date->format('d M Y')}\n\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━\n\n";

        // Checked In & Out
        $checkedInOutCount = count($recapData['checked_in_out']);
        $message .= "✅ *Checked In & Out: {$checkedInOutCount}*\n";
        if ($checkedInOutCount > 0) {
            foreach ($recapData['checked_in_out'] as $emp) {
                $message .= "• {$emp['name']} - {$emp['total_hours']}h\n";
                $message .= "  Tasks: {$emp['tasks_completed']}/{$emp['tasks_total']} completed\n";
            }
        } else {
            $message .= "  (None)\n";
        }
        $message .= "\n";

        // Still Checked In
        $stillCheckedInCount = count($recapData['still_checked_in']);
        $message .= "⏰ *Still Checked In: {$stillCheckedInCount}*\n";
        if ($stillCheckedInCount > 0) {
            foreach ($recapData['still_checked_in'] as $emp) {
                $message .= "• {$emp['name']} (since {$emp['check_in']})\n";
            }
        } else {
            $message .= "  (None)\n";
        }
        $message .= "\n";

        // Not Checked In
        $notCheckedInCount = count($recapData['not_checked_in']);
        $message .= "❌ *Not Checked In: {$notCheckedInCount}*\n";
        if ($notCheckedInCount > 0) {
            foreach ($recapData['not_checked_in'] as $emp) {
                $leaveIndicator = $emp['on_leave'] ? ' (On Leave)' : '';
                $message .= "• {$emp['name']}{$leaveIndicator}\n";
            }
        } else {
            $message .= "  (None)\n";
        }
        $message .= "\n";

        $message .= "━━━━━━━━━━━━━━━━━━━━\n\n";

        // Tasks Summary
        $completedCount = count($recapData['completed_tasks']);
        $incompleteCount = count($recapData['incomplete_tasks']);
        $totalTasksCount = $recapData['all_tasks_count'];

        $message .= "📋 *All Tasks Today: {$totalTasksCount}*\n";
        $message .= "✓ Completed: {$completedCount}\n";
        $message .= "✗ Incomplete: {$incompleteCount}\n\n";

        // Completed Tasks
        if ($completedCount > 0) {
            $message .= "*Completed Tasks:*\n";
            $tasksToShow = array_slice($recapData['completed_tasks'], 0, 10); // Limit to 10
            foreach ($tasksToShow as $task) {
                $message .= "• {$task['employee']} - {$task['title']}\n";
            }
            if ($completedCount > 10) {
                $message .= "  ...and ".($completedCount - 10)." more\n";
            }
            $message .= "\n";
        }

        // Incomplete Tasks
        if ($incompleteCount > 0) {
            $message .= "*Incomplete Tasks:*\n";
            $tasksToShow = array_slice($recapData['incomplete_tasks'], 0, 10); // Limit to 10
            foreach ($tasksToShow as $task) {
                $message .= "• {$task['employee']} - {$task['title']}\n";
                if ($task['blocker']) {
                    $message .= "  Blocker: {$task['blocker']}\n";
                }
            }
            if ($incompleteCount > 10) {
                $message .= "  ...and ".($incompleteCount - 10)." more\n";
            }
            $message .= "\n";
        }

        $message .= "━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "Generated at ".Carbon::now()->format('H:i');

        // Truncate if too long
        if (strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $message = substr($message, 0, self::MAX_MESSAGE_LENGTH - 50);
            $message .= "\n\n...Message truncated due to length limit";
        }

        return $message;
    }

    /**
     * Send recap for a team.
     *
     * @param  Team  $team
     * @param  Carbon|null  $date
     * @return bool
     */
    public function sendRecap(Team $team, ?Carbon $date = null): bool
    {
        if (! $team->canSendWhatsappRecap()) {
            $error = 'Team is not properly configured for WhatsApp recap';
            $team->update(['whatsapp_last_error' => $error]);
            Log::warning("Cannot send WhatsApp recap for team {$team->id}: {$error}");

            return false;
        }

        $date = $date ?? Carbon::today();

        try {
            // Generate recap data
            $recapData = $this->generateRecapData($team, $date);

            // Format message
            $message = $this->formatRecapMessage($recapData);

            // Send via WhatsApp Gateway
            $this->whatsAppGatewayService->sendMessage(
                $team->whatsapp_api_secret,
                $team->whatsapp_account_unique_id,
                $team->whatsapp_recipient_phone,
                $message
            );

            // Update last sent timestamp
            $team->update([
                'whatsapp_last_sent_at' => Carbon::now(),
                'whatsapp_last_error' => null,
            ]);

            // Log activity (use first manager for console commands)
            $manager = $team->managers()->first();
            if ($manager) {
                $this->activityLogService->logActivitySimple(
                    $manager,
                    ActivityType::WHATSAPP_RECAP_SENT,
                    "Daily attendance recap sent via WhatsApp for {$date->format('d M Y')}"
                );
            }

            return true;
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            $team->update(['whatsapp_last_error' => $errorMessage]);
            Log::error("WhatsApp recap send failed for team {$team->id}: {$errorMessage}");

            return false;
        }
    }

    /**
     * Preview recap message without sending.
     *
     * @param  Team  $team
     * @param  Carbon|null  $date
     * @return string
     */
    public function previewRecap(Team $team, ?Carbon $date = null): string
    {
        $date = $date ?? Carbon::today();
        $recapData = $this->generateRecapData($team, $date);

        return $this->formatRecapMessage($recapData);
    }
}
