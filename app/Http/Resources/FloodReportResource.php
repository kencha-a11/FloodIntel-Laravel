<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FloodReportResource extends JsonResource
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
            'user' => [
                'id' => $this->user->id,
                'first_name' => $this->user->first_name,
                'last_name' => $this->user->last_name,
                'role' => $this->user->role->value,
            ],
            'barangay' => [
                'id' => $this->barangay->id,
                'name' => $this->barangay->name,
            ],
            'flood_level' => $this->flood_level->value,
            'flood_level_label' => $this->flood_level->label(),
            'severity' => $this->flood_level->severity(),
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'gps_accuracy' => $this->gps_accuracy !== null ? (float) $this->gps_accuracy : null,
            'address' => $this->address,
            'photo_url' => $this->photo_url,
            'notes' => $this->notes,
            'reported_at' => $this->reported_at,
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'rejected_at' => $this->rejected_at,
            'created_at' => $this->created_at,
        ];
    }
}
