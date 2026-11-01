<?php

namespace App\Http\Requests\Report;

use App\Models\FloodImpactReport;
use Illuminate\Foundation\Http\FormRequest;

class StoreFloodImpactReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', FloodImpactReport::class);
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'integer', 'exists:flood_events,id'],
            'families' => ['required', 'integer', 'min:0'],
            'individuals' => ['required', 'integer', 'min:0', 'gte:families'],
            'breakdown' => ['nullable', 'array'],
            'related_level_report_ids' => ['nullable', 'array'],
            'related_level_report_ids.*' => ['integer', 'exists:flood_reports,id'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'reported_at' => ['nullable', 'date', 'before_or_equal:now'],
            'supersedes_id' => ['nullable', 'integer', 'exists:flood_impact_reports,id'],
        ];
    }
}
