<?php

namespace App\Repositories;

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class ActivityLogRepository
{
    /**
     * Create activity log.
     */
    public function create(User $user, ActivityType $action, string $description, ?string $ipAddress = null, ?string $userAgent = null): ActivityLog
    {
        return ActivityLog::create([
            'team_id' => $user->team_id,
            'user_id' => $user->id,
            'action' => $action,
            'description' => $description,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * Get activity logs with filters.
     */
    public function getWithFilters(?int $userId = null, ?ActivityType $action = null, ?Carbon $startDate = null, ?Carbon $endDate = null, int $perPage = 50): Collection
    {
        $query = ActivityLog::with('user');

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($action) {
            $query->where('action', $action);
        }

        if ($startDate && $endDate) {
            $query->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()]);
        } elseif ($startDate) {
            $query->where('created_at', '>=', $startDate->startOfDay());
        } elseif ($endDate) {
            $query->where('created_at', '<=', $endDate->endOfDay());
        }

        return $query->orderBy('created_at', 'desc')->limit($perPage)->get();
    }

    /**
     * Get paginated activity logs with filters.
     */
    public function getPaginatedWithFilters(?int $userId = null, ?ActivityType $action = null, ?Carbon $startDate = null, ?Carbon $endDate = null, int $perPage = 10, ?int $teamId = null)
    {
        $query = ActivityLog::with('user');

        if ($teamId) {
            $query->where('team_id', $teamId);
        }

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($action) {
            $query->where('action', $action);
        }

        if ($startDate && $endDate) {
            $query->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()]);
        } elseif ($startDate) {
            $query->where('created_at', '>=', $startDate->startOfDay());
        } elseif ($endDate) {
            $query->where('created_at', '<=', $endDate->endOfDay());
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Get activity logs by user.
     */
    public function getByUser(User $user, int $limit = 50): Collection
    {
        return ActivityLog::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
