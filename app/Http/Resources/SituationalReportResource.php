<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SituationalReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'window_starts_at' => $this->window_starts_at,
            'window_ends_at' => $this->window_ends_at,
            'cutoff_at' => $this->cutoff_at,
            'cutoff_label' => $this->cutoff_label,
            'timezone' => $this->timezone,
            'snapshot_at' => $this->snapshot_at,
            'revision' => $this->revision,
            'predecessor_id' => $this->predecessor_id,
            'format_version' => $this->format_version,
            'generated_by_name' => $this->generated_by_name,
            'generated_by_role' => $this->generated_by_role,
            'status_counts' => $this->status_counts,
            'totals' => $this->totals,
            'latency' => $this->latency,
            'barangays' => $this->barangays,
            'stage_two' => $this->stage_two,
            'content' => $this->content,
            'issuance' => $this->whenLoaded('issuance', fn () => $this->issuance === null ? null : [
                'report_number' => $this->issuance->report_number,
                'issued_by_name' => $this->issuance->issued_by_name,
                'issued_by_role' => $this->issuance->issued_by_role,
                'office' => $this->issuance->office,
                'issued_at' => $this->issuance->issued_at,
            ]),
            'inclusions' => $this->whenLoaded('inclusions', fn () => $this->inclusions->map(fn ($inclusion): array => [
                'flood_report_id' => $inclusion->flood_report_id,
                'barangay_id' => $inclusion->barangay_id,
                'alert_level' => $inclusion->alert_level?->value,
                'flood_level' => $inclusion->flood_level?->value,
                'observed_at' => $inclusion->observed_at,
                'validated_at' => $inclusion->validated_at,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
