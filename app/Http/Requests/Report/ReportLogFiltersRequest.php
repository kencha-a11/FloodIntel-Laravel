<?php

namespace App\Http\Requests\Report;

use App\Enums\AlertLevel;
use App\Enums\FloodLevel;
use App\Enums\FloodReportStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The report log's filter surface (FR-21, FR-29).
 *
 * The queue and the CSV export read one request contract, so the two views
 * cannot drift apart about which spellings of a filter are accepted.
 */
class ReportLogFiltersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'flood_level' => ['nullable', Rule::enum(FloodLevel::class)],
            'alert_level' => ['nullable', Rule::enum(AlertLevel::class)],
            'status' => ['nullable', Rule::enum(FloodReportStatus::class)],
            'barangay_id' => ['nullable', 'integer', 'exists:barangays,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
