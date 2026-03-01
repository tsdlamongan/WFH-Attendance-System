<?php

namespace App\Repositories;

use App\Models\Attendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class AttendanceRepository
{
    /**
     * Find attendance by ID.
     */
    public function findById(int $id): ?Attendance
    {
        return Attendance::find($id);
    }

    /**
     * Find attendance by user and date.
     */
    public function findByUserAndDate(User $user, Carbon $date): ?Attendance
    {
        return Attendance::where('user_id', $user->id)
            ->whereDate('date', $date)
            ->first();
    }

    /**
     * Find active attendance for user (has check_in but no check_out).
     * 
     * Note: Searches today and yesterday to handle cross-day work sessions where:
     * - User checked in late at night (e.g., 23:00)
     * - User continues working past midnight
     * - User can checkout the next day
     */
    public function findActiveByUser(User $user): ?Attendance
    {
        return Attendance::where('user_id', $user->id)
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->where(function ($query) {
                $query->whereDate('date', Carbon::today())
                      ->orWhereDate('date', Carbon::yesterday());
            })
            ->with('tasks')
            ->orderBy('date', 'desc')
            ->orderBy('check_in', 'desc')
            ->first();
    }

    /**
     * Get attendances by user in date range.
     */
    public function getByUserInDateRange(User $user, Carbon $startDate, Carbon $endDate): Collection
    {
        return Attendance::where('user_id', $user->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->with('tasks')
            ->orderBy('date', 'desc')
            ->orderBy('check_in', 'desc')
            ->get();
    }

    /**
     * Get all attendances by user for a specific date.
     */
    public function getAllByUserAndDate(User $user, Carbon $date): Collection
    {
        return Attendance::where('user_id', $user->id)
            ->whereDate('date', $date)
            ->with('tasks')
            ->orderBy('check_in', 'asc')
            ->get();
    }

    /**
     * Create new attendance.
     */
    public function create(array $data): Attendance
    {
        return Attendance::create($data);
    }

    /**
     * Update attendance.
     */
    public function update(Attendance $attendance, array $data): bool
    {
        return $attendance->update($data);
    }

    /**
     * Delete attendance.
     */
    public function delete(Attendance $attendance): bool
    {
        return $attendance->delete();
    }

    /**
     * Get all attendances (for manager) filtered by team.
     */
    public function getAllInDateRange(?Carbon $startDate = null, ?Carbon $endDate = null, ?int $teamId = null): Collection
    {
        $query = Attendance::with(['user', 'tasks']);

        if ($teamId) {
            $query->whereHas('user', function ($q) use ($teamId) {
                $q->where('team_id', $teamId);
            });
        }

        if ($startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]);
        }

        return $query->orderBy('date', 'desc')
            ->orderBy('check_in', 'desc')
            ->get();
    }

    /**
     * Find all active attendances where total daily hours (completed sessions + current session)
     * exceed the team's required work hours.
     */
    public function findAllActiveExceedingHours(): Collection
    {
        return Attendance::whereNotNull('check_in')
            ->whereNull('check_out')
            ->with(['user.team', 'tasks'])
            ->get()
            ->filter(function (Attendance $attendance) {
                $team = $attendance->user?->team;
                if (! $team) {
                    return false;
                }

                $requiredHours = $team->getRequiredWorkHours();

                $completedHours = (float) Attendance::where('user_id', $attendance->user_id)
                    ->whereDate('date', $attendance->date)
                    ->whereNotNull('check_out')
                    ->sum('total_hours');

                $currentElapsedMinutes = $attendance->check_in->diffInMinutes(Carbon::now());
                $currentElapsedHours = $currentElapsedMinutes / 60;

                $totalDailyHours = $completedHours + $currentElapsedHours;

                return $totalDailyHours >= $requiredHours;
            });
    }

    /**
     * Get paginated attendances (for manager) filtered by team.
     */
    public function getPaginatedInDateRange(?Carbon $startDate = null, ?Carbon $endDate = null, int $perPage = 10, ?int $userId = null, ?int $teamId = null)
    {
        $query = Attendance::with(['user', 'tasks']);

        if ($teamId) {
            $query->whereHas('user', function ($q) use ($teamId) {
                $q->where('team_id', $teamId);
            });
        }

        if ($startDate && $endDate) {
            $query->whereBetween('date', [$startDate, $endDate]);
        }

        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query->orderBy('date', 'desc')
            ->orderBy('check_in', 'desc')
            ->paginate($perPage);
    }
}

