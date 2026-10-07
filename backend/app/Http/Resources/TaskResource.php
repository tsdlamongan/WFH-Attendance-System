<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
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
            'attendance_id' => $this->attendance_id,
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => $this->project?->name),
            'title' => $this->title,
            'is_completed' => $this->is_completed,
            'blocker_reason' => $this->blocker_reason,
            'attendance' => $this->whenLoaded('attendance', function () {
                return [
                    'id' => $this->attendance->id,
                    'date' => $this->attendance->date,
                    'check_in' => $this->attendance->check_in,
                ];
            }),
        ];
    }
}
