<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OvertimeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'date' => $this->date->format('Y-m-d'),
            'planned_minutes' => $this->planned_minutes,
            'approved_minutes' => $this->approved_minutes,
            'allowed_minutes' => $this->allowedMinutes(),
            'reason' => $this->reason,
            'status' => $this->status->value,
            'source' => $this->source,
            'approved_by' => $this->approved_by,
            'approver' => new UserResource($this->whenLoaded('approver')),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'notes' => $this->notes,
            'requested_at' => $this->created_at->toIso8601String(),
            // Hours worked in finished sessions; only present when the list query sums them.
            'used_hours' => $this->whenHas('used_hours', fn ($hours) => round((float) $hours, 2)),
        ];
    }
}
