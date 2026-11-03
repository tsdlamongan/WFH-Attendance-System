<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\Attendance;
use App\Models\Task;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Tells the manager when an employee keeps bringing the same unfinished task day after day.
 *
 * A task is "repeated" when the new session carries it and, on each previous day the employee
 * checked in, the task was carried too and left incomplete. Days count, not check-ins: several
 * sessions on one day ("installments") count once, so checking in three times on the same day
 * with the same task never triggers the alert. On the Nth consecutive day (N = the configured
 * threshold, default 3) the manager gets a WhatsApp message asking to review the task; the
 * reminder repeats at every further multiple of N (6th, 9th, ...) as long as the streak goes on.
 * Titles are compared ignoring case and whitespace. Standby tasks are never counted: they have
 * their own notification.
 */
class RepeatedTaskAlertService
{
    /**
     * Sessions dated more than this many days before the new session are not inspected. A streak
     * this long has been reported many times already, so the alert simply stops instead of
     * reading the whole history.
     */
    public const MAX_DAYS_LOOKBACK = 30;

    public function __construct(
        private AttendanceRepository $attendanceRepository,
        private WhatsAppGatewayService $whatsAppGatewayService,
        private ActivityLogService $activityLogService
    ) {}

    /**
     * Number of consecutive days (with at least one check-in) the same unfinished task has to be
     * brought to trigger the alert.
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
     * in the session's task order. `days` counts the consecutive check-in days the task has been
     * carried, the session's own day included; `blocker_reason` is the latest reason the
     * employee gave (earlier sessions of the same day included), ignoring the auto-checkout
     * placeholder.
     *
     * A previous day continues the streak when the task was left open in the day's latest
     * session that carried it; a day on which it was completed, or that did not carry it at
     * all, breaks the streak. Earlier sessions of the check-in day itself never add a day.
     *
     * @return list<array{title: string, days: int, blocker_reason: ?string}>
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
            $candidates[$key] = ['title' => trim($task->title), 'days' => 1, 'blocker_reason' => null, 'alive' => true];
        }

        if ($candidates === []) {
            return [];
        }

        $today = $attendance->date->toDateString();
        $previousDays = $this->attendanceRepository
            ->getSessionsBefore($attendance, self::MAX_DAYS_LOOKBACK)
            ->groupBy(fn (Attendance $session) => $session->date->toDateString());

        // Newest day first, so the first blocker found is the latest one written.
        foreach ($previousDays as $day => $sessions) {
            $dayTasks = self::tasksAtEndOfDay($sessions);

            $anyAlive = false;
            foreach ($candidates as $key => $candidate) {
                if (! $candidate['alive']) {
                    continue;
                }

                $task = $dayTasks[$key] ?? null;
                $openThatDay = $task !== null && ! $task->is_completed;
                if ($openThatDay) {
                    $candidates[$key]['blocker_reason'] ??= self::informativeBlocker($task->blocker_reason);
                }

                if ($day === $today) {
                    // Another session of the check-in day: the day is already counted.
                    $anyAlive = true;

                    continue;
                }

                if (! $openThatDay) {
                    $candidates[$key]['alive'] = false;

                    continue;
                }
                $candidates[$key]['days']++;
                $anyAlive = true;
            }

            if (! $anyAlive) {
                break;
            }
        }

        return collect($candidates)
            ->filter(fn (array $candidate) => $candidate['days'] >= $threshold && $candidate['days'] % $threshold === 0)
            ->map(fn (array $candidate) => [
                'title' => $candidate['title'],
                'days' => $candidate['days'],
                'blocker_reason' => $candidate['blocker_reason'],
            ])
            ->values()
            ->all();
    }

    /**
     * How each task title stood at the end of a day: the task as recorded by the day's latest
     * session that carried it. Within one session an open duplicate wins over a completed one.
     *
     * @param  Collection<int, Attendance>  $sessions  the day's sessions, newest first
     * @return array<string, Task> keyed by normalized title
     */
    private static function tasksAtEndOfDay(Collection $sessions): array
    {
        $dayTasks = [];
        foreach ($sessions as $session) {
            $sessionTasks = [];
            foreach ($session->tasks as $task) {
                $key = Task::normalizeTitle($task->title);
                if (! isset($sessionTasks[$key]) || $sessionTasks[$key]->is_completed) {
                    $sessionTasks[$key] = $task;
                }
            }
            // Array union keeps the entries already set by newer sessions.
            $dayTasks += $sessionTasks;
        }

        return $dayTasks;
    }

    /**
     * @param  list<array{title: string, days: int, blocker_reason: ?string}>  $repeated
     */
    private function buildMessage(User $user, array $repeated, Carbon $checkInTime): string
    {
        $timeWib = $checkInTime->timezone('Asia/Jakarta');

        $lines = [];
        foreach ($repeated as $task) {
            $lines[] = "• {$task['title']} ({$task['days']} hari)";
            if ($task['blocker_reason'] !== null) {
                $lines[] = "  Blocker terakhir: {$task['blocker_reason']}";
            }
        }

        return "🔁 Tugas Perlu Review\n"
            .$user->name.' membawa tugas yang sama tanpa selesai selama '.self::threshold()." hari berturut-turut:\n"
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
