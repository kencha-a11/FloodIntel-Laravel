<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SitrepSupplementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'section' => $this->section->value,
            'barangay_id' => $this->barangay_id,
            'barangay' => $this->whenLoaded('barangay', fn () => $this->barangay?->name),
            'payload' => $this->payload,
            'source' => $this->source,
            'observed_at' => $this->observed_at,
            'created_by_name' => $this->created_by_name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
