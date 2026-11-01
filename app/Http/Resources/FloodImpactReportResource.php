<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FloodImpactReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'barangay_id' => $this->barangay_id,
            'event_id' => $this->event_id,
            'families' => $this->families,
            'individuals' => $this->individuals,
            'breakdown' => $this->breakdown,
            'related_level_report_ids' => $this->related_level_report_ids,
            'remarks' => $this->remarks,
            'reported_at' => $this->reported_at,
            'status' => $this->status?->value,
            'supersedes_id' => $this->supersedes_id,
            'client_uuid' => $this->client_uuid,
            'created_at' => $this->created_at,
        ];
    }
}
