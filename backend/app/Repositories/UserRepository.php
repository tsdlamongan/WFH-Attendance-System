<?php

namespace App\Repositories;

use App\Enums\LeaveStatus;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;

class UserRepository
{
    /**
     * Get all users for a team.
     */
    public function getAll(?int $teamId = null): Collection
    {
        $query = User::query();

        if ($teamId) {
            $query->where('team_id', $teamId);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * Get paginated users for a team.
     */
    public function getPaginated(int $perPage = 10, ?int $teamId = null)
    {
        $currentYear = now()->year;

        $query = User::with(['team', 'leaves' => function ($query) use ($currentYear) {
            $query->where('status', LeaveStatus::APPROVED)
                ->whereYear('start_date', $currentYear);
        }]);

        if ($teamId) {
            $query->where('team_id', $teamId);
        }

        return $query->orderBy('name')->paginate($perPage);
    }

    /**
     * Find user by ID.
     */
    public function findById(int $id): ?User
    {
        return User::find($id);
    }

    /**
     * Find user by email.
     */
    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    /**
     * Create user.
     */
    public function create(array $data): User
    {
        $data['password'] = Hash::make($data['password']);

        // Set default leave quota if not provided
        if (! isset($data['leave_quota_days'])) {
            $teamId = $data['team_id'] ?? null;
            $teamDefault = $teamId ? Team::find($teamId)?->getDefaultLeaveQuotaDays() : null;
            $data['leave_quota_days'] = $teamDefault ?? Team::DEFAULT_LEAVE_QUOTA_DAYS;
        }

        return User::create($data);
    }

    /**
     * Update user.
     */
    public function update(User $user, array $data): bool
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        return $user->update($data);
    }

    /**
     * Delete user.
     */
    public function delete(User $user): bool
    {
        return $user->delete();
    }

    /**
     * Get all employees for a team.
     */
    public function getEmployees(?int $teamId = null): Collection
    {
        $query = User::where('role', 'employee');

        if ($teamId) {
            $query->where('team_id', $teamId);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * Get all managers for a team.
     */
    public function getManagers(?int $teamId = null): Collection
    {
        $query = User::where('role', 'manager');

        if ($teamId) {
            $query->where('team_id', $teamId);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * Search users by name within a team.
     */
    public function searchByName(string $search, int $limit = 10, ?int $teamId = null): Collection
    {
        $normalizedLimit = max(1, min($limit, 50));
        
        // Sanitize search term - remove potentially dangerous characters
        // Only allow alphanumeric, spaces, and common search characters
        $searchTerm = preg_replace('/[^a-zA-Z0-9\s@._-]/u', '', mb_strtolower($search, 'UTF-8'));
        
        // Limit search term length to prevent performance issues
        $searchTerm = mb_substr($searchTerm, 0, 100);
        
        if (empty($searchTerm)) {
            return collect();
        }

        $query = User::query()
            ->where(function ($query) use ($searchTerm) {
                // Use parameter binding to prevent SQL injection
                $query->whereRaw('LOWER(name) LIKE ?', ["%{$searchTerm}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$searchTerm}%"]);
            });

        if ($teamId) {
            $query->where('team_id', $teamId);
        }

        return $query->orderBy('name')
            ->limit($normalizedLimit)
            ->get();
    }
}
