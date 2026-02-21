<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
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
            'check_in' => $this->check_in?->toIso8601String(),
            'check_out' => $this->check_out?->toIso8601String(),
            'date' => $this->date->format('Y-m-d'),
            'total_hours' => (float) $this->total_hours,
            'is_auto_checkout' => (bool) $this->is_auto_checkout,
            'tasks' => TaskResource::collection($this->whenLoaded('tasks')),
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
}
