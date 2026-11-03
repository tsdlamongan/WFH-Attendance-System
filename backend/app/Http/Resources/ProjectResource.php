<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
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
            'name' => $this->name,
            'pic' => $this->pic,
            'progress' => $this->progress,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'note' => $this->note,
            'is_active' => $this->is_active,
            'tasks_count' => $this->whenCounted('tasks'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
