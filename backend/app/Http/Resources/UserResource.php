<?php

namespace App\Http\Resources;

use App\Models\LeaveQuota;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentYear = now()->year;

        $yearQuota = LeaveQuota::where('user_id', $this->id)
            ->where('year', $currentYear)
            ->first();

        $effectiveQuota = $yearQuota ? $yearQuota->quota_days : $this->leave_quota_days;

        $approvedLeaveDays = $this->relationLoaded('leaves')
            ? (int) $this->leaves->sum(fn ($leave) => $leave->start_date->diffInDays($leave->end_date) + 1)
            : 0;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'team_id' => $this->team_id,
            'team' => $this->when($this->team, function () {
                return [
                    'id' => $this->team->id,
                    'name' => $this->team->name,
                    'slug' => $this->team->slug,
                ];
            }),
            'leave_quota_days' => $effectiveQuota,
            'leave_quota_year' => $currentYear,
            'approved_leave_days' => $approvedLeaveDays,
            'remaining_leave_days' => max(0, $effectiveQuota - $approvedLeaveDays),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
