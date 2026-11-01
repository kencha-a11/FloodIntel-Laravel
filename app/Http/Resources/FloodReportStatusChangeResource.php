<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FloodReportStatusChangeResource extends JsonResource
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
            'actor_name' => $this->actor_name,
            'actor_role' => $this->actor_role,
            'actor_role_label' => UserRole::tryFrom($this->actor_role)?->label(),
            'from_status' => $this->from_status?->value,
            'from_status_label' => $this->from_status?->label(),
            'to_status' => $this->to_status->value,
            'to_status_label' => $this->to_status->label(),
            'reason' => $this->reason,
            'created_at' => $this->created_at,
        ];
    }
}
