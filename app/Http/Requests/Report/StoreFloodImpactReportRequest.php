<?php

namespace App\Http\Requests\Report;

use App\Enums\FloodImpactStatus;
use App\Models\FloodImpactReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'supersedes_id' => [
                'nullable',
                'integer',
                Rule::exists('flood_impact_reports', 'id')->where(
                    fn ($query) => $query
                        ->where('barangay_id', $this->user()->barangay_id)
                        ->where('event_id', $this->input('event_id'))
                        ->where('status', FloodImpactStatus::Submitted->value),
                ),
            ],
        ];
    }
}
