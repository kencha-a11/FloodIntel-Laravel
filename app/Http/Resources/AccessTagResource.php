<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccessTagResource extends JsonResource
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
            'barangay' => [
                'id' => $this->barangay->id,
                'name' => $this->barangay->name,
            ],
            'recorded_by' => [
                'id' => $this->createdBy->id,
                'first_name' => $this->createdBy->first_name,
                'last_name' => $this->createdBy->last_name,
                'role' => $this->createdBy->role->value,
            ],
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'landmark' => $this->landmark,
            'note' => $this->note,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'gps_accuracy' => $this->gps_accuracy !== null ? (float) $this->gps_accuracy : null,
            'address' => $this->address,
            'observed_at' => $this->observed_at,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
