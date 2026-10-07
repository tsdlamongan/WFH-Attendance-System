<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\Attendance;
use App\Models\Task;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Tells the manager when an employee keeps bringing the same unfinished task to check-in.
 *
 * A task is "repeated" when the new session carries it and each session right before it also
 * carried it and left it incomplete. At the Nth consecutive session (N = the configured
 * threshold, default 3) the manager gets a WhatsApp message asking to review the task; the
 * reminder repeats at every further multiple of N (6th, 9th, ...) as long as the streak goes on.
 * Titles are compared ignoring case and whitespace. Standby tasks are never counted: they have
 * their own notification.
 */
class RepeatedTaskAlertService
{
    /**
     * Sessions older than this are not inspected. A streak this long has been reported many
     * times already, so the alert simply stops instead of reading the whole history.
     */
    public const MAX_SESSIONS_LOOKBACK = 30;

    public function __construct(
        private AttendanceRepository $attendanceRepository,
        private WhatsAppGatewayService $whatsAppGatewayService,
        private ActivityLogService $activityLogService
    ) {}

    /**
     * Number of consecutive check-ins with the same unfinished task that triggers the alert.
     */
    public static function threshold(): int
    {
        return max(2, (int) config('attendance.repeated_task_threshold', 3));
    }

    /**
     * Send the review request to the team's WhatsApp recipient when the session has tasks that
     * reached the threshold. Never throws: failures are logged so the check-in is not affected.
     */
    public function notifyIfNeeded(User $user, Attendance $attendance, Carbon $checkInTime): void
    {
        $team = $user->team;
        if (! $team || ! $team->isWhatsappConnected() || empty($team->whatsapp_recipient_phone)) {
            return;
        }

        try {
            $repeated = $this->findRepeatedTasks($attendance);
            if ($repeated === []) {
                return;
            }

            $this->whatsAppGatewayService->sendMessage(
                $team->whatsapp_api_secret,
                $team->whatsapp_account_unique_id,
                $team->whatsapp_recipient_phone,
                $this->buildMessage($user, $repeated, $checkInTime)
            );

            $titles = implode(', ', array_column($repeated, 'title'));
            $this->activityLogService->logActivity(
                $user,
                ActivityType::WHATSAPP_TASK_REVIEW_SENT,
                "Task review notification sent to manager via WhatsApp: {$titles}",
                null
            );
        } catch (\Exception $e) {
            Log::error('Task review WhatsApp notification failed: '.$e->getMessage(), [
                'user_id' => $user->id,
                'attendance_id' => $attendance->id,
            ]);
        }
    }

    /**
     * Tasks of the session whose unfinished streak just hit the threshold (or a multiple of it),
     * in the session's task order. `count` includes the session itself; `blocker_reason` is the
     * latest reason the employee gave, ignoring the auto-checkout placeholder.
     *
     * @return list<array{title: string, count: int, blocker_reason: ?string}>
     */
    public function findRepeatedTasks(Attendance $attendance): array
    {
        $threshold = self::threshold();
        $attendance->loadMissing('tasks');

        // One candidate per distinct title of the new session; `alive` is false once the streak broke.
        $candidates = [];
        foreach ($attendance->tasks as $task) {
            $key = Task::normalizeTitle($task->title);
            if ($key === '' || $task->isStandby() || isset($candidates[$key])) {
                continue;
            }
            $candidates[$key] = ['title' => trim($task->title), 'count' => 1, 'blocker_reason' => null, 'alive' => true];
        }

        if ($candidates === []) {
            return [];
        }

        $previousSessions = $this->attendanceRepository->getSessionsBefore($attendance, self::MAX_SESSIONS_LOOKBACK - 1);

        foreach ($previousSessions as $session) {
            $openTasks = [];
            foreach ($session->tasks as $task) {
                if (! $task->is_completed) {
                    $openTasks[Task::normalizeTitle($task->title)] ??= $task;
                }
            }

            $anyAlive = false;
            foreach ($candidates as $key => $candidate) {
                if (! $candidate['alive']) {
                    continue;
                }
                if (! isset($openTasks[$key])) {
                    $candidates[$key]['alive'] = false;
                    continue;
                }
                $candidates[$key]['count']++;
                $candidates[$key]['blocker_reason'] ??= self::informativeBlocker($openTasks[$key]->blocker_reason);
                $anyAlive = true;
            }

            if (! $anyAlive) {
                break;
            }
        }

        return collect($candidates)
            ->filter(fn (array $candidate) => $candidate['count'] >= $threshold && $candidate['count'] % $threshold === 0)
            ->map(fn (array $candidate) => [
                'title' => $candidate['title'],
                'count' => $candidate['count'],
                'blocker_reason' => $candidate['blocker_reason'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{title: string, count: int, blocker_reason: ?string}>  $repeated
     */
    private function buildMessage(User $user, array $repeated, Carbon $checkInTime): string
    {
        $timeWib = $checkInTime->timezone('Asia/Jakarta');

        $lines = [];
        foreach ($repeated as $task) {
            $lines[] = "• {$task['title']} ({$task['count']}x)";
            if ($task['blocker_reason'] !== null) {
                $lines[] = "  Blocker terakhir: {$task['blocker_reason']}";
            }
        }

        return "🔁 Tugas Perlu Review\n"
            .$user->name.' membawa tugas yang sama tanpa selesai di '.self::threshold()."x check-in berturut-turut:\n"
            .implode("\n", $lines)."\n"
            .'⏰ '.$timeWib->format('H:i').' WIB - '.$timeWib->format('d M Y')."\n"
            .'Mohon review tugas tersebut bersama '.$user->name.'.';
    }

    /**
     * The blocker as written by the employee; the auto-checkout placeholder says nothing useful.
     */
    private static function informativeBlocker(?string $blocker): ?string
    {
        $blocker = trim((string) $blocker);

        return $blocker === '' || strcasecmp($blocker, Task::AUTO_CHECKOUT_BLOCKER) === 0 ? null : $blocker;
    }
}
