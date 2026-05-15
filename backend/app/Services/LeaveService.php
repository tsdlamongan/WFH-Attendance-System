<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\LeaveStatus;
use App\Models\Leave;
use App\Models\LeaveQuota;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LeaveService
{
    public function __construct(
        private ActivityLogService $activityLogService
    ) {}

    /**
     * Request leave with quota and monthly limit validation.
     */
    public function requestLeave(User $user, array $data, ?Request $request = null): Leave
    {
        if ($user->is_disabled) {
            throw new \Exception('Akun anda telah dinonaktifkan.');
        }

        $user->loadMissing('team');

        $startDate = Carbon::parse($data['start_date']);
        $endDate = Carbon::parse($data['end_date']);

        // Calculate requested days
        $requestedDays = $this->calculateLeaveDays($startDate, $endDate);

        // Check annual quota
        $this->validateAnnualQuota($user, $requestedDays);

        // Check monthly limit
        $this->validateMonthlyLimit($user, $startDate, $endDate, $requestedDays);

        // Check for overlapping leaves
        $this->validateNoOverlap($user, $startDate, $endDate);

        DB::beginTransaction();
        try {
            $leave = Leave::create([
                'user_id' => $user->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'reason' => $data['reason'],
                'status' => LeaveStatus::PENDING,
            ]);

            $this->activityLogService->logActivity(
                $user,
                ActivityType::LEAVE_REQUESTED,
                "User requested leave from {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')} ({$requestedDays} days)",
                $request
            );

            DB::commit();

            return $leave->load('user');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Leave request failed: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Calculate number of leave days between two dates.
     */
    public function calculateLeaveDays(Carbon $startDate, Carbon $endDate): int
    {
        // Add 1 because both start and end dates are inclusive
        return $startDate->diffInDays($endDate) + 1;
    }

    /**
     * Get the leave quota for a user in a specific year.
     * Checks leave_quotas table first, falls back to users.leave_quota_days.
     */
    public function getQuotaForYear(User $user, int $year): int
    {
        $leaveQuota = LeaveQuota::where('user_id', $user->id)
            ->where('year', $year)
            ->first();

        return $leaveQuota ? $leaveQuota->quota_days : $user->leave_quota_days;
    }

    /**
     * Validate annual leave quota.
     */
    private function validateAnnualQuota(User $user, int $requestedDays): void
    {
        $currentYear = Carbon::now()->year;

        // Get approved leaves for current year
        $usedDays = Leave::where('user_id', $user->id)
            ->where('status', LeaveStatus::APPROVED)
            ->whereYear('start_date', $currentYear)
            ->get()
            ->sum(function ($leave) {
                return $this->calculateLeaveDays($leave->start_date, $leave->end_date);
            });

        // Get pending leaves for current year
        $pendingDays = Leave::where('user_id', $user->id)
            ->where('status', LeaveStatus::PENDING)
            ->whereYear('start_date', $currentYear)
            ->get()
            ->sum(function ($leave) {
                return $this->calculateLeaveDays($leave->start_date, $leave->end_date);
            });

        $totalUsed = $usedDays + $pendingDays + $requestedDays;
        $quota = $this->getQuotaForYear($user, $currentYear);

        if ($totalUsed > $quota) {
            $remaining = $quota - ($usedDays + $pendingDays);
            throw new \Exception(
                "Jatah cuti tidak mencukupi. Anda memiliki sisa {$remaining} hari dari total {$quota} hari jatah cuti tahunan. ".
                "(Terpakai: {$usedDays}, Menunggu Persetujuan: {$pendingDays}, Diajukan: {$requestedDays})"
            );
        }
    }

    /**
     * Validate monthly leave limit.
     */
    private function validateMonthlyLimit(User $user, Carbon $startDate, Carbon $endDate, int $requestedDays): void
    {
        $maxPerMonth = $user->team?->getMaxLeaveDaysPerMonth() ?? Team::DEFAULT_MAX_LEAVE_DAYS_PER_MONTH;

        // Get all months covered by this leave request
        $months = [];
        $current = $startDate->copy();
        while ($current->lte($endDate)) {
            $monthKey = $current->format('Y-m');
            if (! isset($months[$monthKey])) {
                $months[$monthKey] = 0;
            }

            // Calculate days in this month for this leave
            $monthEnd = $current->copy()->endOfMonth();
            $periodEnd = $endDate->lt($monthEnd) ? $endDate : $monthEnd;
            $daysInMonth = $current->diffInDays($periodEnd) + 1;

            $months[$monthKey] += $daysInMonth;
            $current = $monthEnd->addDay();
        }

        // Check each month
        foreach ($months as $monthKey => $daysInMonth) {
            [$year, $month] = explode('-', $monthKey);

            // Get approved + pending leaves for this month
            $existingDays = Leave::where('user_id', $user->id)
                ->whereIn('status', [LeaveStatus::APPROVED, LeaveStatus::PENDING])
                ->where(function ($query) use ($year, $month) {
                    $query->whereYear('start_date', $year)
                        ->whereMonth('start_date', $month)
                        ->orWhere(function ($q) use ($year, $month) {
                            $q->whereYear('end_date', $year)
                                ->whereMonth('end_date', $month);
                        });
                })
                ->get()
                ->sum(function ($leave) use ($year, $month) {
                    $monthStart = Carbon::create($year, $month, 1)->startOfMonth();
                    $monthEnd = $monthStart->copy()->endOfMonth();

                    $leaveStart = $leave->start_date->gte($monthStart) ? $leave->start_date : $monthStart;
                    $leaveEnd = $leave->end_date->lte($monthEnd) ? $leave->end_date : $monthEnd;

                    return $this->calculateLeaveDays($leaveStart, $leaveEnd);
                });

            $totalInMonth = $existingDays + $daysInMonth;

            if ($totalInMonth > $maxPerMonth) {
                $monthName = Carbon::create($year, $month, 1)->locale('id')->translatedFormat('F Y');
                throw new \Exception(
                    "Batas cuti bulanan terlampaui untuk bulan {$monthName}. ".
                    "Maksimal {$maxPerMonth} hari per bulan. ".
                    "Anda sudah memiliki {$existingDays} hari di bulan ini, dan mengajukan {$daysInMonth} hari lagi."
                );
            }
        }
    }

    /**
     * Validate no overlapping leave requests.
     */
    private function validateNoOverlap(User $user, Carbon $startDate, Carbon $endDate): void
    {
        $overlapping = Leave::where('user_id', $user->id)
            ->whereIn('status', [LeaveStatus::APPROVED, LeaveStatus::PENDING])
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate])
                    ->orWhere(function ($q) use ($startDate, $endDate) {
                        $q->where('start_date', '<=', $startDate)
                            ->where('end_date', '>=', $endDate);
                    });
            })
            ->exists();

        if ($overlapping) {
            throw new \Exception('Anda sudah memiliki pengajuan cuti untuk rentang tanggal ini.');
        }
    }

    /**
     * Get leave summary for user.
     */
    public function getLeaveSummary(User $user, ?int $year = null): array
    {
        $user->loadMissing('team');

        $year = $year ?? Carbon::now()->year;

        $approvedLeaves = Leave::where('user_id', $user->id)
            ->where('status', LeaveStatus::APPROVED)
            ->whereYear('start_date', $year)
            ->get();

        $pendingLeaves = Leave::where('user_id', $user->id)
            ->where('status', LeaveStatus::PENDING)
            ->whereYear('start_date', $year)
            ->get();

        $usedDays = $approvedLeaves->sum(function ($leave) {
            return $this->calculateLeaveDays($leave->start_date, $leave->end_date);
        });

        $pendingDays = $pendingLeaves->sum(function ($leave) {
            return $this->calculateLeaveDays($leave->start_date, $leave->end_date);
        });

        $quota = $this->getQuotaForYear($user, $year);
        $remaining = $quota - $usedDays - $pendingDays;

        return [
            'year' => $year,
            'total_quota' => $quota,
            'used_days' => $usedDays,
            'pending_days' => $pendingDays,
            'remaining_days' => max(0, $remaining),
            'max_per_month' => $user->team?->getMaxLeaveDaysPerMonth() ?? Team::DEFAULT_MAX_LEAVE_DAYS_PER_MONTH,
        ];
    }

    /**
     * Update leave request (manager edit).
     */
    public function updateLeave(Leave $leave, User $manager, array $data, ?Request $request = null): Leave
    {
        DB::beginTransaction();
        try {
            $changes = [];

            if (isset($data['start_date'])) {
                $old = $leave->start_date->format('Y-m-d');
                $leave->start_date = $data['start_date'];
                if ($old !== $data['start_date']) {
                    $changes[] = "start_date: {$old} -> {$data['start_date']}";
                }
            }

            if (isset($data['end_date'])) {
                $old = $leave->end_date->format('Y-m-d');
                $leave->end_date = $data['end_date'];
                if ($old !== $data['end_date']) {
                    $changes[] = "end_date: {$old} -> {$data['end_date']}";
                }
            }

            if (isset($data['created_at'])) {
                $old = $leave->created_at->toIso8601String();
                $leave->created_at = $data['created_at'];
                $changes[] = "created_at: {$old} -> {$data['created_at']}";
            }

            if (isset($data['approved_at'])) {
                $old = $leave->approved_at?->toIso8601String() ?? 'null';
                $leave->approved_at = $data['approved_at'];
                $changes[] = "approved_at: {$old} -> {$data['approved_at']}";
            }

            $leave->save();

            if (count($changes) > 0) {
                $this->activityLogService->logActivity(
                    $manager,
                    ActivityType::LEAVE_EDITED,
                    "Manager edited leave #{$leave->id} for {$leave->user->name}: ".implode(', ', $changes),
                    $request
                );
            }

            DB::commit();

            return $leave->fresh(['user', 'approver']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Update leave failed: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Approve leave request.
     */
    public function approveLeave(Leave $leave, User $approver, ?string $notes = null, ?Request $request = null): Leave
    {
        DB::beginTransaction();
        try {
            $leave->update([
                'status' => LeaveStatus::APPROVED,
                'approved_by' => $approver->id,
                'approved_at' => Carbon::now(),
                'notes' => $notes,
            ]);

            $this->activityLogService->logActivity(
                $approver,
                ActivityType::LEAVE_APPROVED,
                "Manager approved leave request for {$leave->user->name} from {$leave->start_date->format('Y-m-d')} to {$leave->end_date->format('Y-m-d')}",
                $request
            );

            DB::commit();

            return $leave->fresh(['user', 'approver']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Approve leave failed: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Reject leave request.
     */
    public function rejectLeave(Leave $leave, User $approver, ?string $notes = null, ?Request $request = null): Leave
    {
        DB::beginTransaction();
        try {
            $leave->update([
                'status' => LeaveStatus::REJECTED,
                'approved_by' => $approver->id,
                'approved_at' => Carbon::now(),
                'notes' => $notes,
            ]);

            $this->activityLogService->logActivity(
                $approver,
                ActivityType::LEAVE_REJECTED,
                "Manager rejected leave request for {$leave->user->name} from {$leave->start_date->format('Y-m-d')} to {$leave->end_date->format('Y-m-d')}",
                $request
            );

            DB::commit();

            return $leave->fresh(['user', 'approver']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Reject leave failed: '.$e->getMessage());
            throw $e;
        }
    }
}
