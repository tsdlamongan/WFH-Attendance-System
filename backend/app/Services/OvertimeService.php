<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\OvertimeStatus;
use App\Models\Attendance;
use App\Models\Overtime;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Overtime (lembur) approval.
 *
 * Business-rule failures throw \DomainException so controllers can answer 422
 * with the (Indonesian) message instead of a generic 500.
 */
class OvertimeService
{
    public function __construct(
        private ActivityLogService $activityLogService,
        private WhatsAppGatewayService $whatsAppGatewayService
    ) {}

    /**
     * Latest overtime record of the user for the given day, whatever its status.
     */
    public function latestForDay(User $user, Carbon $date): ?Overtime
    {
        return Overtime::where('user_id', $user->id)
            ->whereDate('date', $date)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Hours already completed (checked-out sessions) on the given day.
     */
    public function completedHoursForDay(User $user, Carbon $date): float
    {
        return (float) Attendance::where('user_id', $user->id)
            ->whereDate('date', $date)
            ->whereNotNull('check_out')
            ->sum('total_hours');
    }

    /**
     * A check-in is an overtime session when the day's required hours are already met.
     * Auto-checkout closes regular sessions exactly at the threshold, so a session
     * never straddles it.
     */
    public function isOvertimeSession(User $user, Carbon $date): bool
    {
        $user->loadMissing('team');
        $requiredHours = $user->team?->getRequiredWorkHours() ?? Team::DEFAULT_REQUIRED_WORK_HOURS;

        return $this->completedHoursForDay($user, $date) >= $requiredHours;
    }

    /**
     * Decide which overtime record (if any) a new check-in belongs to.
     * Creates a pending request from the check-in payload when none exists yet.
     *
     * @param  array{reason?: string, planned_minutes?: int}|null  $payload
     */
    public function resolveForCheckIn(User $user, Carbon $date, ?array $payload, ?Request $request = null): ?Overtime
    {
        if (! $this->isOvertimeSession($user, $date)) {
            return null;
        }

        $latest = $this->latestForDay($user, $date);

        if ($latest?->isRejected()) {
            throw new \DomainException('Pengajuan lembur Anda hari ini ditolak. Anda tidak dapat check-in lembur.');
        }

        if ($latest) {
            if ($latest->usedMinutes() >= $latest->allowedMinutes()) {
                throw new \DomainException('Durasi lembur yang diajukan hari ini sudah habis. Hubungi manager untuk menambah durasi.');
            }

            return $latest;
        }

        if (empty($payload['reason']) || empty($payload['planned_minutes'])) {
            throw new \DomainException('Check-in lembur memerlukan alasan dan durasi lembur.');
        }

        return $this->create($user, $date, $payload, $request);
    }

    /**
     * Employee asks for overtime today (in advance, or from the check-in flow).
     *
     * @param  array{reason: string, planned_minutes: int}  $data
     */
    public function request(User $user, array $data, ?Request $request = null): Overtime
    {
        if ($user->is_disabled) {
            throw new \DomainException('Akun anda telah dinonaktifkan.');
        }

        $today = Carbon::today();

        DB::beginTransaction();
        try {
            $existing = $this->latestForDay($user, $today);
            if ($existing && ! $existing->isRejected()) {
                throw new \DomainException('Anda sudah memiliki pengajuan lembur untuk hari ini.');
            }

            $overtime = $this->create($user, $today, $data, $request);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Overtime request failed: '.$e->getMessage());
            throw $e;
        }

        $this->notifyRequested($overtime);

        return $overtime->load('user');
    }

    /**
     * Manager approves a pending request, optionally with a different duration.
     */
    public function approve(Overtime $overtime, User $approver, ?int $approvedMinutes = null, ?string $notes = null, ?Request $request = null): Overtime
    {
        return $this->decide($overtime, $approver, OvertimeStatus::APPROVED, $approvedMinutes, $notes, $request);
    }

    /**
     * Manager rejects a pending request.
     */
    public function reject(Overtime $overtime, User $approver, ?string $notes = null, ?Request $request = null): Overtime
    {
        return $this->decide($overtime, $approver, OvertimeStatus::REJECTED, null, $notes, $request);
    }

    /**
     * Manager grants overtime to an employee for today (auto-approved).
     * Also used to extend or override an earlier decision; the latest record of the day wins.
     *
     * @param  array{reason: string, planned_minutes: int}  $data
     */
    public function assign(User $manager, User $employee, array $data, ?Request $request = null): Overtime
    {
        if ($employee->is_disabled) {
            throw new \DomainException('Akun karyawan telah dinonaktifkan.');
        }

        $today = Carbon::today();

        DB::beginTransaction();
        try {
            $latest = $this->latestForDay($employee, $today);
            if ($latest?->isPending()) {
                throw new \DomainException('Masih ada pengajuan lembur karyawan ini yang menunggu keputusan. Setujui atau tolak terlebih dahulu.');
            }

            $overtime = Overtime::create([
                'user_id' => $employee->id,
                'date' => $today,
                'planned_minutes' => $data['planned_minutes'],
                'reason' => $data['reason'],
                'status' => OvertimeStatus::APPROVED,
                'source' => Overtime::SOURCE_MANAGER,
                'approved_by' => $manager->id,
                'approved_at' => Carbon::now(),
            ]);

            $this->activityLogService->logActivity(
                $manager,
                ActivityType::OVERTIME_ASSIGNED,
                "Manager assigned overtime to {$employee->name} for {$today->format('Y-m-d')} ({$overtime->planned_minutes} minutes)",
                $request
            );

            DB::commit();

            return $overtime->load(['user', 'approver']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Assign overtime failed: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Overtime info for the employee's "today" status.
     *
     * @return array{requires_overtime_request: bool, overtime: array<string, mixed>|null}
     */
    public function todayState(User $user, Carbon $today): array
    {
        $latest = $this->latestForDay($user, $today);

        if (! $latest) {
            return [
                'requires_overtime_request' => $this->isOvertimeSession($user, $today),
                'overtime' => null,
            ];
        }

        $allowed = $latest->allowedMinutes();
        $used = (int) round($latest->usedMinutes());

        return [
            'requires_overtime_request' => false,
            'overtime' => [
                'id' => $latest->id,
                'date' => $latest->date->format('Y-m-d'),
                'status' => $latest->status->value,
                'reason' => $latest->reason,
                'notes' => $latest->notes,
                'planned_minutes' => $latest->planned_minutes,
                'approved_minutes' => $latest->approved_minutes,
                'allowed_minutes' => $allowed,
                'used_minutes' => $used,
                'remaining_minutes' => max(0, $allowed - $used),
            ],
        ];
    }

    /**
     * Send the manager a WhatsApp heads-up about a new employee request.
     * Never throws: a failed notification must not block the employee.
     */
    public function notifyRequested(Overtime $overtime): void
    {
        $overtime->loadMissing('user.team');
        $user = $overtime->user;
        $team = $user->team;

        if (! $team || ! $team->isWhatsappConnected() || empty($team->whatsapp_recipient_phone)) {
            return;
        }

        try {
            $message = "⏰ Pengajuan Lembur\n"
                .$user->name." mengajukan lembur {$overtime->planned_minutes} menit\n"
                .'📅 '.$overtime->date->format('d M Y')."\n"
                .'📝 Alasan: '.$overtime->reason."\n"
                .'Silakan tinjau di aplikasi.';

            $this->whatsAppGatewayService->sendMessage(
                $team->whatsapp_api_secret,
                $team->whatsapp_account_unique_id,
                $team->whatsapp_recipient_phone,
                $message
            );

            $this->activityLogService->logActivity(
                $user,
                ActivityType::WHATSAPP_OVERTIME_SENT,
                'Overtime request notification sent to manager via WhatsApp',
                null
            );
        } catch (\Exception $e) {
            Log::error('Overtime WhatsApp notification failed: '.$e->getMessage(), [
                'user_id' => $user->id,
                'overtime_id' => $overtime->id,
            ]);
        }
    }

    /**
     * Create a pending employee request and log it. Callers own the transaction.
     *
     * @param  array{reason: string, planned_minutes: int}  $data
     */
    private function create(User $user, Carbon $date, array $data, ?Request $request): Overtime
    {
        $overtime = Overtime::create([
            'user_id' => $user->id,
            'date' => $date,
            'planned_minutes' => $data['planned_minutes'],
            'reason' => $data['reason'],
            'status' => OvertimeStatus::PENDING,
            'source' => Overtime::SOURCE_EMPLOYEE,
        ]);

        $this->activityLogService->logActivity(
            $user,
            ActivityType::OVERTIME_REQUESTED,
            "User requested overtime for {$date->format('Y-m-d')} ({$overtime->planned_minutes} minutes)",
            $request
        );

        return $overtime;
    }

    private function decide(Overtime $overtime, User $approver, OvertimeStatus $status, ?int $approvedMinutes, ?string $notes, ?Request $request): Overtime
    {
        DB::beginTransaction();
        try {
            // Re-read under lock so two managers cannot decide the same request twice.
            $current = Overtime::with('user')->lockForUpdate()->findOrFail($overtime->id);

            if (! $current->isPending()) {
                throw new \DomainException('Pengajuan lembur ini sudah diputuskan.');
            }

            $current->update([
                'status' => $status,
                'approved_minutes' => $status === OvertimeStatus::APPROVED ? $approvedMinutes : null,
                'approved_by' => $approver->id,
                'approved_at' => Carbon::now(),
                'notes' => $notes,
            ]);

            $approved = $status === OvertimeStatus::APPROVED;

            $this->activityLogService->logActivity(
                $approver,
                $approved ? ActivityType::OVERTIME_APPROVED : ActivityType::OVERTIME_REJECTED,
                'Manager '.($approved ? 'approved' : 'rejected')." overtime request for {$current->user->name} on {$current->date->format('Y-m-d')}",
                $request
            );

            DB::commit();

            return $current->fresh(['user', 'approver']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Decide overtime failed: '.$e->getMessage());
            throw $e;
        }
    }
}
