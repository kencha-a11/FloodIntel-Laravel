<?php

namespace App\Http\Requests\Report;

use App\Enums\FloodReportStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OverrideFloodReportStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('override', $this->route('floodReport'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'to_status' => [
                'required',
                Rule::in([
                    FloodReportStatus::Validated->value,
                    FloodReportStatus::Returned->value,
                    FloodReportStatus::Rejected->value,
                ]),
            ],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
