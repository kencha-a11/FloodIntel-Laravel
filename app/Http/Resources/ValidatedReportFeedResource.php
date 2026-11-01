<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ValidatedReportFeedResource extends JsonResource
{
    /**
     * Transform the resource into the map feed contract (FR-50).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'gps_accuracy' => $this->gps_accuracy !== null ? (float) $this->gps_accuracy : null,
            'barangay' => [
                'id' => $this->barangay->id,
                'name' => $this->barangay->name,
            ],
            'alert_level' => $this->alert_level?->value,
            'alert_level_label' => $this->alert_level?->label(),
            'alert_severity' => $this->alert_level?->severity(),
            // D-78: the derived surface composes DEM(report) + depth, so the feed carries the depth it needs.
            'depth_cm' => $this->depth_cm !== null ? (int) $this->depth_cm : null,
            'observed_at' => $this->reported_at,
            'received_at' => $this->created_at,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'source' => 'reported',
            'source_label' => 'Reported',
        ];
    }
}
