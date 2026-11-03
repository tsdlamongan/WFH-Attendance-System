<?php

namespace App\Http\Resources;

use App\Enums\ActivityType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityLogResource extends JsonResource
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
            'user' => $this->whenLoaded('user') ? new UserResource($this->user) : null,
            'action' => $this->action instanceof ActivityType ? $this->action->value : (string) $this->action,
            'description' => $this->description ?? '',
            'ip_address' => $this->ip_address ?? null,
            'user_agent' => $this->user_agent ?? null,
            'created_at' => $this->created_at ? $this->created_at->toIso8601String() : null,
        ];
    }
}
