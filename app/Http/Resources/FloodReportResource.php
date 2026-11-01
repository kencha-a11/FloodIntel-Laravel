<?php

namespace App\Http\Resources;

use App\Enums\AnomalyFlag;
use App\Enums\FloodReportStatus;
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
            'flood_level' => $this->flood_level?->value,
            'flood_level_label' => $this->flood_level?->label(),
            'severity' => $this->flood_level?->severity(),
            'alert_level' => $this->alert_level?->value,
            'alert_level_label' => $this->alert_level?->label(),
            'alert_severity' => $this->alert_level?->severity(),
            'flood_type' => $this->flood_type?->value,
            'flood_type_label' => $this->flood_type?->label(),
            'depth_cm' => $this->depth_cm !== null ? (int) $this->depth_cm : null,
            'landmark' => $this->landmark,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'gps_accuracy' => $this->gps_accuracy !== null ? (float) $this->gps_accuracy : null,
            'address' => $this->address,
            'photo_url' => $this->photo_url,
            'notes' => $this->notes,
            'reported_at' => $this->reported_at,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_changes' => FloodReportStatusChangeResource::collection(
                $this->whenLoaded('statusChanges'),
            ),
            'anomaly_flags' => ($this->anomaly_flags ?? collect())
                ->map(fn (AnomalyFlag $flag): array => [
                    'value' => $flag->value,
                    'label' => $flag->label(),
                ])
                ->values()
                ->all(),
            'rejection_reason' => $this->rejection_reason,
            'rejected_at' => $this->rejected_at,
            'client_uuid' => $this->client_uuid,
            'supersedes_id' => $this->supersedes_id,
            'superseded_by_id' => $this->whenLoaded(
                'supersededBy',
                fn () => $this->supersededBy?->id,
            ),
            'supersedes' => $this->whenLoaded(
                'supersedes',
                fn () => $this->supersedes === null ? null : [
                    'id' => $this->supersedes->id,
                    'status' => $this->supersedes->status->value,
                    'status_label' => $this->supersedes->status->label(),
                    'reported_at' => $this->supersedes->reported_at,
                    'returned_reason' => $this->supersedes->statusChanges
                        ->firstWhere('to_status', FloodReportStatus::Returned)?->reason,
                ],
            ),
            'created_at' => $this->created_at,
        ];
    }
}
